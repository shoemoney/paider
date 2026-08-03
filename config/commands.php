<?php

use App\Commands\ChatCommand;
use Illuminate\Console\Scheduling\ScheduleFinishCommand;
use Illuminate\Console\Scheduling\ScheduleListCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Foundation\Console\VendorPublishCommand;
use LaravelZero\Framework\Commands\BuildCommand;
use LaravelZero\Framework\Commands\InstallCommand;
use LaravelZero\Framework\Commands\MakeCommand;
use LaravelZero\Framework\Commands\RenameCommand;
use LaravelZero\Framework\Commands\StubPublishCommand;
use LaravelZero\Framework\Commands\TestMakeCommand;
use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;
use Symfony\Component\Console\Command\HelpCommand;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Command
    |--------------------------------------------------------------------------
    |
    | Laravel Zero will always run the command specified below when no command name is
    | provided. Consider update the default command for single command applications.
    | You cannot pass arguments to the default command because they are ignored.
    |
    */

    'default' => ChatCommand::class,

    /*
    |--------------------------------------------------------------------------
    | Commands Paths
    |--------------------------------------------------------------------------
    |
    | This value determines the "paths" that should be loaded by the console's
    | kernel. Foreach "path" present on the array provided below the kernel
    | will extract all "Illuminate\Console\Command" based class commands.
    |
    */

    'paths' => [app_path('Commands')],

    /*
    |--------------------------------------------------------------------------
    | Added Commands
    |--------------------------------------------------------------------------
    |
    | You may want to include a single command class without having to load an
    | entire folder. Here you can specify which commands should be added to
    | your list of commands. The console's kernel will try to load them.
    |
    */

    'add' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Hidden Commands
    |--------------------------------------------------------------------------
    |
    | Your application commands will always be visible on the application list
    | of commands. But you can still make them "hidden" specifying an array
    | of commands below. All "hidden" commands can still be run/executed.
    |
    */

    'hidden' => [
        SummaryCommand::class,
        DumpCompletionCommand::class,
        HelpCommand::class,
        ScheduleRunCommand::class,
        ScheduleListCommand::class,
        ScheduleFinishCommand::class,
        VendorPublishCommand::class,
        StubPublishCommand::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Removed Commands
    |--------------------------------------------------------------------------
    |
    | Do you have a service provider that loads a list of commands that
    | you don't need? No problem. Laravel Zero allows you to specify
    | below a list of commands that you don't to see in your app.
    |
    */

    /*
     * Whatever is public at the first tag is frozen by the release policy —
     * removing a command afterwards is a breaking change. None of these are
     * Paider's; they are Laravel Zero skeleton commands for building Laravel
     * Zero apps, which is not what anyone installs this to do.
     *
     * `app:build` is the sharpest one: it builds a PHAR, and PHAR is an
     * explicitly cut distribution format (PLAN.md, "Distribution and
     * concurrency"). Leaving it public advertises a channel that does not exist
     * — the same reason box.json is no longer shipped in the dist archive.
     */
    'remove' => [
        BuildCommand::class,
        MakeCommand::class,
        RenameCommand::class,
        InstallCommand::class,
        TestMakeCommand::class,

        // `paider test` runs Paider's own suite. tests/ is export-ignored from the
        // dist archive, so for anyone who installed via composer this command has
        // nothing to run — it would fail confusingly rather than do nothing.
        // Contributors work from a clone and use `vendor/bin/pest` directly.
        TestCommand::class,
    ],

];
