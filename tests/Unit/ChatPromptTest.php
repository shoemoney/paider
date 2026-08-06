<?php

use App\Support\ChatPrompt;
use App\Support\PromptTheme;
use App\Support\ProseStream;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

/** Drives the prompt's key handling directly — emit('key', ...) is what the input loop calls. */
function pressKeys(ChatPrompt $prompt, string ...$keys): void
{
    foreach ($keys as $key) {
        $prompt->emit('key', $key);
    }
}

test('Enter submits instead of inserting a newline', function () {
    $prompt = new ChatPrompt('paider>');

    pressKeys($prompt, 'h', 'i', Key::ENTER);

    expect($prompt->value())->toBe('hi');
    expect($prompt->state)->toBe('submit');
});

test('Enter does not both insert a newline and submit', function () {
    // The parent constructor's Enter->newline listener has to be cleared, not just added to:
    // listeners accumulate, so a missed clearListeners() leaves both firing on one keypress
    // and the submitted value silently carries a trailing newline.
    $prompt = new ChatPrompt('paider>');

    pressKeys($prompt, 'a', Key::ENTER);

    expect($prompt->value())->toBe('a')->not->toContain(PHP_EOL);
});

test('Ctrl+D still submits, because the box label advertises it', function () {
    $prompt = new ChatPrompt('paider>');

    pressKeys($prompt, 'y', 'o', Key::CTRL_D);

    expect($prompt->value())->toBe('yo');
    expect($prompt->state)->toBe('submit');
});

test('the theme registers every custom prompt, so one cannot unregister the other', function () {
    // addTheme() replaces the whole map for a theme name. Registering per-class would mean the
    // last writer wins and the other resolves to a null renderer at render time.
    PromptTheme::activate();

    $themes = (new ReflectionProperty(Prompt::class, 'themes'))->getValue();

    expect($themes[PromptTheme::NAME])
        ->toHaveKey(ChatPrompt::class)
        ->toHaveKey(ProseStream::class);
});
