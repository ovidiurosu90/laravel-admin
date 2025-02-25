<?php

return [

    /*
     * Is email activation required
     */
    'app_project_version' => env('APP_PROJECT_VERSION', 10),

    /*
     * Is email activation required
     */
    'activation' => env('ACTIVATION', false),

    /*
     * Is email activation required
     */
    'timePeriod' => env('ACTIVATION_LIMIT_TIME_PERIOD', 24),

    /*
     * Is email activation required
     */
    'maxAttempts' => env('ACTIVATION_LIMIT_MAX_ATTEMPTS', 3),

    /*
     * NULL Ip to enter to match database schema
     */
    'nullIpAddress' => env('NULL_IP_ADDRESS', '0.0.0.0'),

    /*
     * User restore encryption type
     */
    'restoreUserEncType' => 'AES-256-ECB',

    /*
     * User restore days past cutoff
     */
    'restoreUserCutoff' => env('USER_RESTORE_CUTOFF_DAYS', 31),

    /*
     * User list pagination size
     */
    'userListPaginationSize' => env('USER_LIST_PAGINATION_SIZE', 50),

    /*
     * User restore encryption key
     */
    'restoreKey' => env('USER_RESTORE_ENCRYPTION_KEY', 'sup3rDuP3rS3cr3tR35t0r3K3y21!'),

    /*
     * ReCaptcha Status
     */
    'reCaptchStatus' => env('ENABLE_RECAPTCHA', false),

    /*
     * ReCaptcha Site Key
     */
    'reCaptchSite'   => env('RE_CAP_SITE', 'YOURGOOGLECAPTCHAsitekeyHERE'),

    /*
     * ReCaptcha Secret
     */
    'reCaptchSecret' => env('RE_CAP_SECRET', 'YOURGOOGLECAPTCHAsecretHERE'),

    /*
     * Google Maps API V3 Status
     */
    'googleMapsAPIStatus' => env('GOOGLEMAPS_API_STATUS', false),

    /*
     * Google Maps API Key
     */
    'googleMapsAPIKey'    => env('GOOGLEMAPS_API_KEY', 'YOURGOOGLEMAPSkeyHERE'),

    /*
     * Google Maps Map ID
     * https://console.cloud.google.com/google/maps-apis/studio/maps
     */
    'googleMapsMapId'     => env('GOOGLEMAPS_MAP_ID', 'YOURGOOGLEMAPSmapIdHERE'),

    /*
     * DropZone CDN
     */
    'dropZoneJsCDN'    => env('DROPZONE_JS_CDN', 'https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.4.0/dropzone.js'),

    /*
     * Google Analytics
     */
    'googleanalyticsId' => env('GOOGLE_ANALYTICS_ID', false),

    /*
     * enable/disable HTTP Method column in admin's route details list
     */
    'showRouteHttpMethod' => env('SHOW_ROUTE_HTTP_METHOD', 1),
];
