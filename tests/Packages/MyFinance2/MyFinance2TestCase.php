<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2;

use ovidiuro\myfinance2\MyFinance2ServiceProvider;
use Tests\TestCase;

/**
 * Base for the MyFinance2 package tests: they skip when the package is not installed, so the main app's suite
 * still passes without it.
 */
abstract class MyFinance2TestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Skips the whole class: neither setUp() (DatabaseTransactions on myfinance2_mysql) nor the tearDown()
        // methods that call the package's classes run.
        if (!class_exists(MyFinance2ServiceProvider::class)) {
            static::markTestSkipped('The ovidiuro/myfinance2 package is not installed.');
        }

        parent::setUpBeforeClass();
    }
}
