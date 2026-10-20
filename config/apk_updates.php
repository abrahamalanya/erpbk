<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aplicativo móvil (umax-app)
    |--------------------------------------------------------------------------
    |
    | applicationId configurado en umax-app/app.config.ts (android.package).
    | Las versiones APK subidas deben declarar exactamente este valor.
    |
    */

    'application_id' => env('APK_UPDATES_APPLICATION_ID', 'com.credimasperu.umax'),

    'max_size_mb' => (int) env('APK_UPDATES_MAX_SIZE_MB', 300),

];
