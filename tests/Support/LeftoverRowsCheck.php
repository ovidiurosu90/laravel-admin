<?php

declare(strict_types=1);

namespace Tests\Support;

use Throwable;

/**
 * One run of the leftover-rows check (LeftoverRowsExtension): the snapshot before the first test, the comparison
 * after the last. A database it cannot read skips the check with one warning saying why, never a stack trace or a
 * false report.
 */
final class LeftoverRowsCheck
{
    private ?string $_skipped = null;

    public function __construct(private readonly TableSnapshot $snapshot)
    {
    }

    public function start(): void
    {
        try {
            $this->snapshot->take();
        } catch (Throwable $e) {
            $this->_skipped = $e->getMessage();
        }
    }

    /**
     * The warning to fail the run with, or null when the run left the database as it found it.
     */
    public function finish(): ?string
    {
        if ($this->_skipped !== null) {
            return "Leftover-rows check skipped, the tables could not be read before the run: {$this->_skipped}";
        }

        try {
            $changes = $this->snapshot->changes();
        } catch (Throwable $e) {
            return "Leftover-rows check skipped, the tables could not be read after the run: {$e->getMessage()}";
        }

        return $changes === [] ? null
            : "The tests left rows behind in the database (clean them up, then fix the test):\n  "
                . implode("\n  ", $changes);
    }
}
