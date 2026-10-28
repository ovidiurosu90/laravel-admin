<?php

/*
 * This app's settings for the ovidiuro/myfinance2 package; every other key keeps the package's default
 * (vendor/ovidiuro/myfinance2/src/config/myfinance2.php). Harmless without the package.
 */
return [
    // Log each MyFinance2 request in the activity log and apply the blocker, like this app's own pages. The package
    // always adds 'web' before and its access gate after these.
    'middleware' => ['activity', 'checkblocked'],

    // The finance menu goes into this app's navbar registry (config/navigation.php)
    'navbar_registry' => 'navigation.menus',

    // MyFinance2 pages render inside this app's layout (resources/views/layouts/app.blade.php) and its sections; the
    // layout already shows the flash messages (partials.form-status)
    'layout'          => 'layouts.app',
    'layout_sections' => [
        'title'   => 'template_title',
        'styles'  => 'template_linked_css',
        'content' => 'content',
        'scripts' => 'footer_scripts',
    ],
    'flash_messages'  => false,
];
