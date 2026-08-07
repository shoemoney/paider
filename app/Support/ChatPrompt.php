<?php

namespace App\Support;

use Laravel\Prompts\Key;
use Laravel\Prompts\TextareaPrompt;

/**
 * A textarea that sends on Enter.
 *
 * text() is a single line that scrolls horizontally and hides the overflow behind a '…', so a
 * long prompt becomes unreadable while you are still typing it. textarea() wraps and grows,
 * but binds Enter to newline and only submits on Ctrl+D — in a chat REPL that reads as a dead
 * key: you type, press Enter, and get another blank line. This keeps the wrapping and puts
 * Enter back on submit.
 *
 * The trade is that Enter can no longer insert a newline, so a pasted multi-line block submits
 * at its first line break. That matches what text() already did, so it costs nothing that was
 * previously working.
 */
final class ChatPrompt extends TextareaPrompt
{
    public function __construct(string $label)
    {
        // Before parent::__construct(), which renders — and rendering an unregistered Prompt
        // subclass is a fatal, not a fallback. Registering here rather than in the caller
        // means there is no way to construct this class into that fatal.
        PromptTheme::activate();

        // rows is a literal, not a parameter — nothing in app/ or tests/ ever passes anything
        // but the default 3, and TextareaPrompt itself defaults to 5, so dropping this silently
        // would grow the box.
        parent::__construct(label: $label, rows: 3);

        // The parent constructor bound Enter->newline (trackTypedValue with submit: false) and
        // Ctrl+D->submit. Both live in 'key' listeners, and listeners accumulate rather than
        // replace — rebinding without clearing first would leave Enter inserting a newline AND
        // submitting on the same keypress.
        $this->clearListeners();

        $this->trackTypedValue(submit: true, allowNewLine: false);

        $this->on('key', function (string $key): void {
            if ($key[0] === "\e") {
                match ($key) {
                    Key::UP, Key::UP_ARROW, Key::CTRL_P => $this->handleUpKey(),
                    Key::DOWN, Key::DOWN_ARROW, Key::CTRL_N => $this->handleDownKey(),
                    default => null,
                };

                return;
            }

            // Ctrl+D stays bound as an unadvertised alias. ChatPromptRenderer rewrites the
            // box label to name Enter, so nothing tells the user about this key any more —
            // but it costs one branch and anyone who built the Ctrl+D habit keeps it.
            foreach (mb_str_split($key) as $char) {
                if ($char === Key::CTRL_D) {
                    $this->submit();

                    return;
                }
            }
        });
    }

    public static function ask(string $label): string
    {
        return (new self($label))->prompt();
    }
}
