<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Service Providers
    |--------------------------------------------------------------------------
    |
    | Here you may register all of the service providers for your application.
    | These providers will be loaded automatically on each request to your
    | application. Feel free to add your own services to this array.
    |
    */

    App\Providers\AppServiceProvider::class,

    /*
    |--------------------------------------------------------------------------
    | Filament Panels
    |--------------------------------------------------------------------------
    */

    App\Providers\Filament\AdminPanelProvider::class,

    App\Providers\Filament\TeacherPanelProvider::class,

];