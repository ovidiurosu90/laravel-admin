<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The tests run on the local copy of the production database, so each run must leave it as it found it: the tables
 * of the connections in the 'connections' parameter (phpunit.xml) are read before the first test and after the last,
 * and any row the run left behind fails the run with a warning naming the tables. Tables in 'ignore'
 * ("connection.table", comma-separated) are left out.
 *
 * Catches rows added anywhere, and rows removed from the tables without an id; not changes to existing rows.
 */
final class LeftoverRowsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $check = new LeftoverRowsCheck(new TableSnapshot(
            $this->_list($parameters, 'connections'),
            $this->_list($parameters, 'ignore'),
        ));

        $facade->registerSubscriber(new class ($check) implements ExecutionStartedSubscriber
        {
            public function __construct(private readonly LeftoverRowsCheck $check)
            {
            }

            public function notify(ExecutionStarted $event): void
            {
                $this->check->start();
            }
        });

        $facade->registerSubscriber(new class ($check) implements ExecutionFinishedSubscriber
        {
            public function __construct(private readonly LeftoverRowsCheck $check)
            {
            }

            public function notify(ExecutionFinished $event): void
            {
                $warning = $this->check->finish();
                if ($warning !== null) {
                    EventFacade::emitter()->testRunnerTriggeredPhpunitWarning($warning);
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    private function _list(ParameterCollection $parameters, string $name): array
    {
        if (!$parameters->has($name)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $parameters->get($name)))));
    }
}
