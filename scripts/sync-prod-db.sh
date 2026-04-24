#!/usr/bin/env bash
#
# sync-prod-db.sh
#
# Dumps a configurable list of tables from one or more production databases
# and imports them to localhost. Tables and their source databases are defined
# in .env via PROD_SYNC_TABLES as comma-separated DB_KEY:table pairs, where
# DB_KEY maps to the env var prefix holding that database's credentials
# (e.g. MYFINANCE2_DB → MYFINANCE2_DB_HOST / MYFINANCE2_DB_DATABASE / etc.,
#       DB           → DB_HOST / DB_DATABASE / etc.).
#
# Optionally reimports one or more full database schemas beforehand (defined in
# PROD_SYNC_SCHEMAS as DB_KEY:path pairs, paths relative to laravel-admin dir).
# Two schema-source modes are available:
#   default          — git pull of the schema repo, then import local .sql files
#   --fresh-schemas  — run artisan schema:dump on production, scp files back,
#                      then import; artisan connection is derived from filename
#                      (e.g. myfinance2_mysql-schema.sql → --database myfinance2_mysql)
#
# Usage:    bash scripts/sync-prod-db.sh [--fresh-schemas]
# Run from: ~/Repositories/laravel-admin/
# Requires: sshpass  (sudo apt-get install sshpass)
# Config:   see PROD_* and PROD_SYNC_* keys in .env / .env.example

set -euo pipefail

# ── Args ───────────────────────────────────────────────────────────────────────
FRESH_SCHEMAS=false
for arg in "$@"; do
    case "$arg" in
        --fresh-schemas) FRESH_SCHEMAS=true ;;
        *) echo "Unknown argument: $arg" >&2; exit 1 ;;
    esac
done

# ── Paths ──────────────────────────────────────────────────────────────────────
LARAVEL_ADMIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$LARAVEL_ADMIN_DIR/.env"
TMP_DIR="/tmp/db_sync"

# ── Helpers ────────────────────────────────────────────────────────────────────
env_var()
{
    grep "^${1}=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'" | tr -d '\r' || true
}

die()
{
    echo "Error: $1" >&2
    exit 1
}

check_cmd()
{
    command -v "$1" &>/dev/null || die "'$1' is required. Install with: sudo apt-get install $1"
}

# Parse a "DB_KEY:value,..." string into an associative array (db_key → values)
# Usage: parse_pairs ASSOC_ARRAY_NAME "raw_string"
parse_pairs()
{
    local -n _map="$1"
    local raw="$2"
    local pair db_key value

    IFS=',' read -ra PAIRS <<< "$raw"
    for pair in "${PAIRS[@]}"; do
        pair="${pair// /}"  # trim spaces
        db_key="${pair%%:*}"
        value="${pair#*:}"
        [[ -n "$db_key" && -n "$value" && "$db_key" != "$value" ]] \
            || die "Invalid entry '$pair' (expected format: DB_KEY:value)"
        _map[$db_key]="${_map[$db_key]:+${_map[$db_key]} }$value"
    done
}

# ── Preflight ──────────────────────────────────────────────────────────────────
[[ -f "$ENV_FILE" ]] || die ".env not found at $ENV_FILE"
check_cmd sshpass
check_cmd mysql

# ── Load config from .env ──────────────────────────────────────────────────────
PROD_SSH_HOST=$(env_var "PROD_SSH_HOST")
PROD_SSH_USER=$(env_var "PROD_SSH_USER")
SYNC_TABLES_RAW=$(env_var "PROD_SYNC_TABLES")
SYNC_SCHEMAS_RAW=$(env_var "PROD_SYNC_SCHEMAS")        # optional
SCHEMA_GIT_DIR=$(env_var "PROD_SYNC_SCHEMA_GIT_DIR")   # optional; relative to laravel-admin dir
PROD_LARAVEL_DIR=$(env_var "PROD_LARAVEL_DIR")
PROD_LARAVEL_DIR="${PROD_LARAVEL_DIR:-~/Repositories/laravel-admin}"

[[ -n "$PROD_SSH_HOST"   ]] || die "PROD_SSH_HOST is not set in .env"
PROD_SSH_USER="${PROD_SSH_USER:-$USER}"
[[ -n "$SYNC_TABLES_RAW" ]] || die "PROD_SYNC_TABLES is not set in .env"

# Parse PROD_SYNC_TABLES into db_key → "table1 table2" map
declare -A DB_TABLES
parse_pairs DB_TABLES "$SYNC_TABLES_RAW"

# Parse PROD_SYNC_SCHEMAS into db_key → "schema_file" map (if set)
declare -A DB_SCHEMAS
[[ -n "$SYNC_SCHEMAS_RAW" ]] && parse_pairs DB_SCHEMAS "$SYNC_SCHEMAS_RAW"

# ── Step 1: SSH password ───────────────────────────────────────────────────────
echo "Connecting to production: $PROD_SSH_USER@$PROD_SSH_HOST"
read -r -s -p "SSH password: " SSH_PASS
echo ""

export SSHPASS="$SSH_PASS"

sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 \
    "$PROD_SSH_USER@$PROD_SSH_HOST" true \
    || die "Cannot connect to $PROD_SSH_HOST"

echo "Connected."

# ── Step 2: Dump tables on production ─────────────────────────────────────────
echo ""
echo "Dumping tables on production..."

# SYNC_TABLES_RAW is expanded locally; inside the heredoc all vars are remote
sshpass -e ssh -T -o StrictHostKeyChecking=no \
    "$PROD_SSH_USER@$PROD_SSH_HOST" \
    "SYNC_CONFIG='$SYNC_TABLES_RAW' bash" << 'REMOTE_EOF'
set -euo pipefail

ENV_FILE=~/Repositories/laravel-admin/.env

env_var()
{
    grep "^${1}=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'" | tr -d '\r' || true
}

declare -A DB_TABLES
IFS=',' read -ra PAIRS <<< "$SYNC_CONFIG"
for PAIR in "${PAIRS[@]}"; do
    PAIR="${PAIR// /}"
    DB_KEY="${PAIR%%:*}"
    TABLE="${PAIR#*:}"
    DB_TABLES[$DB_KEY]="${DB_TABLES[$DB_KEY]:+${DB_TABLES[$DB_KEY]} }$TABLE"
done

for DB_KEY in "${!DB_TABLES[@]}"; do
    DB_HOST=$(env_var "${DB_KEY}_HOST")
    DB_PORT=$(env_var "${DB_KEY}_PORT")
    DB_DATABASE=$(env_var "${DB_KEY}_DATABASE")
    DB_USER=$(env_var "${DB_KEY}_USERNAME")
    DB_PASS=$(env_var "${DB_KEY}_PASSWORD")
    TABLES="${DB_TABLES[$DB_KEY]}"
    DUMP_FILE="/tmp/db_sync_${DB_KEY}.sql"

    echo "  [$DB_KEY] $DB_DATABASE on $DB_HOST:${DB_PORT:-3306} — tables: $TABLES"

    mysqldump \
        -h"$DB_HOST" -P"${DB_PORT:-3306}" \
        -u"$DB_USER" -p"$DB_PASS" \
        --single-transaction --no-tablespaces \
        "$DB_DATABASE" $TABLES \
        > "$DUMP_FILE"

    echo "  [$DB_KEY] Dump size: $(du -h "$DUMP_FILE" | cut -f1)"
done
REMOTE_EOF

echo "Production dump complete."

# ── Step 3: Copy dumps to localhost ───────────────────────────────────────────
echo ""
echo "Copying dumps to localhost..."
mkdir -p "$TMP_DIR"

for DB_KEY in "${!DB_TABLES[@]}"; do
    sshpass -e scp -o StrictHostKeyChecking=no \
        "$PROD_SSH_USER@$PROD_SSH_HOST:/tmp/db_sync_${DB_KEY}.sql" \
        "$TMP_DIR/db_sync_${DB_KEY}.sql"

    echo "  [$DB_KEY] Saved: $TMP_DIR/db_sync_${DB_KEY}.sql ($(du -h "$TMP_DIR/db_sync_${DB_KEY}.sql" | cut -f1))"
done

# Clean up dump files on production server
for DB_KEY in "${!DB_TABLES[@]}"; do
    sshpass -e ssh -o StrictHostKeyChecking=no \
        "$PROD_SSH_USER@$PROD_SSH_HOST" \
        "rm -f /tmp/db_sync_${DB_KEY}.sql"
done

# ── Step 4: Optional full-schema reimports ────────────────────────────────────
if [[ "${#DB_SCHEMAS[@]}" -gt 0 ]]; then
    echo ""
    if [[ "$FRESH_SCHEMAS" == true ]]; then
        read -r -p "Reimport full schema(s) (dumped fresh from production) before importing tables? [y/N] " REIMPORT
    else
        read -r -p "Reimport full schema(s) from laravel-package-admin-mydata before importing tables? [y/N] " REIMPORT
    fi

    if [[ "$REIMPORT" =~ ^[Yy]$ ]]; then

        # ── Step 4a: Obtain schema files ──────────────────────────────────────
        if [[ "$FRESH_SCHEMAS" == true ]]; then
            # Dump fresh schemas from production via artisan schema:dump.
            # Artisan connection name is derived from the filename:
            # e.g. myfinance2_mysql-schema.sql → --database myfinance2_mysql
            echo ""
            echo "Dumping schemas on production (artisan schema:dump)..."

            for DB_KEY in "${!DB_SCHEMAS[@]}"; do
                REMOTE_PATH="${DB_SCHEMAS[$DB_KEY]}"
                ARTISAN_CONN="$(basename "$REMOTE_PATH" | sed 's/-schema\.sql$//')"

                sshpass -e ssh -o StrictHostKeyChecking=no \
                    "$PROD_SSH_USER@$PROD_SSH_HOST" \
                    "cd $PROD_LARAVEL_DIR && mkdir -p \"\$(dirname '$REMOTE_PATH')\" && php artisan schema:dump --database $ARTISAN_CONN --path $REMOTE_PATH"

                echo "  [$DB_KEY] Dumped (connection: $ARTISAN_CONN): $PROD_LARAVEL_DIR/$REMOTE_PATH"
            done

            echo ""
            echo "Copying schema files to localhost..."

            for DB_KEY in "${!DB_SCHEMAS[@]}"; do
                REMOTE_PATH="${DB_SCHEMAS[$DB_KEY]}"
                LOCAL_PATH="$LARAVEL_ADMIN_DIR/$REMOTE_PATH"
                mkdir -p "$(dirname "$LOCAL_PATH")"

                sshpass -e scp -o StrictHostKeyChecking=no \
                    "$PROD_SSH_USER@$PROD_SSH_HOST:$PROD_LARAVEL_DIR/$REMOTE_PATH" \
                    "$LOCAL_PATH"

                echo "  [$DB_KEY] Saved: $LOCAL_PATH ($(du -h "$LOCAL_PATH" | cut -f1))"
            done

        else
            # Pull latest schema files from the git repo
            if [[ -n "$SCHEMA_GIT_DIR" && -d "$LARAVEL_ADMIN_DIR/$SCHEMA_GIT_DIR" ]]; then
                echo ""
                echo "Updating schema repo (git checkout main && git pull)..."
                git -C "$LARAVEL_ADMIN_DIR/$SCHEMA_GIT_DIR" checkout main
                git -C "$LARAVEL_ADMIN_DIR/$SCHEMA_GIT_DIR" pull
            fi
        fi

        # ── Step 4b: Import schema files locally ──────────────────────────────
        for DB_KEY in "${!DB_SCHEMAS[@]}"; do
            SCHEMA_FILE="$LARAVEL_ADMIN_DIR/${DB_SCHEMAS[$DB_KEY]}"

            [[ -f "$SCHEMA_FILE" ]] || die "Schema file not found: $SCHEMA_FILE"

            LOCAL_DB=$(env_var "${DB_KEY}_DATABASE")
            LOCAL_DB_HOST=$(env_var "${DB_KEY}_HOST")
            LOCAL_DB_PORT=$(env_var "${DB_KEY}_PORT")
            LOCAL_DB_USER=$(env_var "${DB_KEY}_USERNAME")
            LOCAL_DB_PASS=$(env_var "${DB_KEY}_PASSWORD")

            echo ""
            echo "  [$DB_KEY] WARNING: This will DROP and recreate all tables in '$LOCAL_DB'."
            read -r -p "  [$DB_KEY] Confirm reimport from ${DB_SCHEMAS[$DB_KEY]}? [y/N] " CONFIRM

            if [[ "$CONFIRM" =~ ^[Yy]$ ]]; then
                mysql \
                    -h"$LOCAL_DB_HOST" -P"${LOCAL_DB_PORT:-3306}" \
                    -u"$LOCAL_DB_USER" -p"$LOCAL_DB_PASS" \
                    --init-command="SET SESSION time_zone = '+00:00';" \
                    "$LOCAL_DB" \
                    < "$SCHEMA_FILE"
                echo "  [$DB_KEY] Schema reimport complete."
            else
                echo "  [$DB_KEY] Skipped."
            fi
        done
    fi
fi

unset SSHPASS

# ── Step 5: Import tables locally ──────────────────────────────────────────────
echo ""
echo "Importing tables into local databases..."

for DB_KEY in "${!DB_TABLES[@]}"; do
    LOCAL_DB=$(env_var "${DB_KEY}_DATABASE")
    LOCAL_DB_HOST=$(env_var "${DB_KEY}_HOST")
    LOCAL_DB_PORT=$(env_var "${DB_KEY}_PORT")
    LOCAL_DB_USER=$(env_var "${DB_KEY}_USERNAME")
    LOCAL_DB_PASS=$(env_var "${DB_KEY}_PASSWORD")

    echo "  [$DB_KEY] Importing '${DB_TABLES[$DB_KEY]}' into $LOCAL_DB..."

    mysql \
        -h"$LOCAL_DB_HOST" -P"${LOCAL_DB_PORT:-3306}" \
        -u"$LOCAL_DB_USER" -p"$LOCAL_DB_PASS" \
        --init-command="SET SESSION time_zone = '+00:00';" \
        "$LOCAL_DB" \
        < "$TMP_DIR/db_sync_${DB_KEY}.sql"
done

echo ""
echo "Production tables synced to localhost successfully."
