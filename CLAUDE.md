# Laravel Admin

The host app. It installs two packages of the same author through Composer:

- **ovidiuro/myfinance2** (`vendor/ovidiuro/myfinance2`, public): finance features; has its own `CLAUDE.md`
- **ovidiuro/laravel-package-admin-mydata** (`vendor/ovidiuro/laravel-package-admin-mydata`, private): backup table
  list, schema dumps, private config, test data

## Repository boundaries (keep coupling minimal)

Keep the coupling between the three repositories to a minimum:

- Couple only as a last resort, when there is no other way. Prefer the packages' contracts: their config (override in
  this app's `config/<package>.php`), myfinance2's gates (`myfinance2.access`, `myfinance2.manage`, defined in
  `app/Providers/AuthServiceProvider.php`), events and view extension points.
- This app must keep working without myfinance2 and admin-mydata installed: guard every reference to them
  (`class_exists`, `@includeIf`, tests that skip when the package is missing, see
  `tests/Packages/MyFinance2/MyFinance2TestCase.php`).
- myfinance2 must work in other Laravel apps: it never references this app's code, layout, roles, permissions or
  middleware directly; this app supplies them through the config and the gates.
- Document every coupling point that remains, with a comment at the spot and in the README of the repository that
  depends on the other.

## Tests

- Each repository tests its own logic. This app tests only its own code and its wiring of the packages (its roles ->
  myfinance2's gates, its middleware, its layout, its config): `tests/Packages/MyFinance2`. Everything myfinance2
  implements is tested in the package (`vendor/ovidiuro/myfinance2`, `vendor/bin/phpunit --testsuite Feature`).
  Exceptions kept here: the Returns regression and holdings tests (they need this deployment's real portfolio) and
  admin-mydata's logic (it has no test runner: `AdminMyDataTradesConfigTest`, `DatabaseBackupCoverageTest`).
- Both repositories' tests run on the local copy of production (there is no test database): every test rolls back,
  writes only synthetic rows and never saves a user. `tests/Support/LeftoverRowsExtension` (phpunit.xml) fails any run
  that leaves rows behind.
