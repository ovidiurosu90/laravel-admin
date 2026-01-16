<?php

/**
 * PUBLISHED from vendor/ovidiuro/laravel-package-admin-mydata/src/config/test-data.php
 *
 * WHY BOTH FILES NEEDED:
 * - Package file: For service provider mergeConfigFrom()
 * - This file: For tests to read during data provider evaluation (before Laravel boots)
 */

return [
    'providers' => [
        'returns' => 'ovidiuro\adminmydata\Testing\MyFinance2TestData',
    ],
];

