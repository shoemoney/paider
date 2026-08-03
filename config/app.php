<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => 'Paider',

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value determines the "version" your application is currently running
    | in. You may want to follow the "Semantic Versioning" - Given a version
    | number MAJOR.MINOR.PATCH when an update happens: https://semver.org.
    |
    */

    /*
     * Composer records the version it installed, so ask it rather than the
     * filesystem. Laravel Zero's default here is app('git.version'), which shells
     * out to `git describe --tags` from basePath() — two problems, both real:
     *
     *   1. Installed via composer there is no .git in vendor/paider/paider, and
     *      git WALKS UP. Inside a host app tagged v9.9.9, `paider --version`
     *      reports v9.9.9 — the consumer's version, presented as Paider's.
     *   2. It forks a git process on every single invocation, in a tool whose
     *      whole pitch includes a 94.8ms cold start.
     *
     * InstalledVersions is generated at install time, costs nothing to read, and
     * is correct in vendor/. The fallback covers the FrankenPHP static binary and
     * any other context where composer's runtime API is not present.
     */
    'version' => class_exists(\Composer\InstalledVersions::class)
        ? (\Composer\InstalledVersions::getPrettyVersion('paider/paider') ?? 'unreleased')
        : 'unreleased',

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. This can be overridden using
    | the global command line "--env" option when calling commands.
    |
    */

    'env' => 'development',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],

];
