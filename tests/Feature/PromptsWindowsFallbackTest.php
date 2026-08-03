<?php

use Laravel\Prompts\Callout;
use Laravel\Prompts\Note;
use Laravel\Prompts\Progress;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Spinner;
use Laravel\Prompts\Stream;
use Laravel\Prompts\Table;
use Laravel\Prompts\Task;

/**
 * Guards the Windows conclusions in DECISIONS.md §9. A laravel/prompts upgrade
 * that breaks either of these silently breaks native Windows support.
 */

/**
 * Fallbacks are registered by Illuminate's ConfiguresPrompts during a command run,
 * so this needs to run SOME command — any one of Paider's own will do. It used to
 * run `inspire`, which was Laravel Zero scaffold and has since been deleted; pick
 * a command the product actually owns so the test does not depend on scaffolding
 * outliving the scaffold.
 */
function registeredFallbacks(): array
{
    test()->artisan('config:show')->run();

    return (new ReflectionClass(Prompt::class))->getStaticPropertyValue('fallbacks');
}

it('registers a Windows fallback for every input prompt except number()', function () {
    $registered = array_map(
        fn (string $c) => class_basename($c),
        array_keys(registeredFallbacks())
    );

    // number() is the known gap — it reaches Prompt::checkEnvironment() and throws
    // on native Windows. Paider must not call it. If this starts failing because
    // NumberPrompt gained a fallback, delete the exclusion and the DECISIONS.md note.
    expect($registered)->not->toContain('NumberPrompt');

    expect($registered)->toContain(
        'TextPrompt', 'TextareaPrompt', 'PasswordPrompt', 'ConfirmPrompt',
        'SelectPrompt', 'MultiSelectPrompt', 'SuggestPrompt', 'SearchPrompt',
        'MultiSearchPrompt', 'PausePrompt',
    );
});

it('keeps the streaming output path clear of the Windows guard', function ($class) {
    // The guard lives in Prompt::prompt(). These classes override it, which is the
    // whole reason stream()/table()/progress() render identically on Windows.
    $declaring = (new ReflectionClass($class))->getMethod('prompt')->getDeclaringClass()->getName();

    expect($declaring)->not->toBe(Prompt::class);
})->with([
    Stream::class,
    Task::class,
    Spinner::class,
    Progress::class,
    Note::class,
    Table::class,
    Callout::class,
]);
