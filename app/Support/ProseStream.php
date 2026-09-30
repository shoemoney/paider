<?php

namespace App\Support;

use Laravel\Prompts\Stream;

/**
 * Laravel Prompts' Stream word-wraps prose at `terminal cols - 20`, which throws away a fifth
 * of the width on every line. StreamRenderer indents each line by exactly one space, so a
 * margin of 4 leaves that space on the left and three columns of slack on the right — enough
 * that nothing collides with the edge, without the wide dead gutter.
 */
final class ProseStream extends Stream
{
    private const MARGIN = 4;

    /**
     * Scrub AIGATE_TOKEN (and other provider keys) from text before it reaches the terminal
     * or prompt history. A model that echoes a key it saw in tool output would otherwise
     * persist that secret into the next prompt and the event log.
     */
    public static function scrubSecrets(string $text): string
    {
        foreach (['AIGATE_TOKEN', 'AIGATE_URL', 'OPENROUTER_API_KEY', 'ANTHROPIC_API_KEY', 'META_API_KEY', 'MOONSHOT_API_KEY', 'DEEPSEEK_API_KEY', 'DASHSCOPE_API_KEY', 'XAI_API_KEY', 'GLM_API_KEY'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '' && strlen($value) >= 8) {
                $text = str_replace($value, '[redacted:'.$key.']', $text);
            }
        }

        return self::scrubSecretPatterns($text);
    }

    /**
     * Redact credential-SHAPED strings, whether or not they are one of OUR keys.
     *
     * ## Why this exists separately from scrubSecrets()
     *
     * The env-var loop above can only redact keys this machine happens to hold. That is the wrong
     * shape for the event log, because the log records work done on OTHER people's credentials: a
     * coding agent is routinely asked to `curl -H "Authorization: Bearer sk-..."` against a
     * customer's API, and that string was landing verbatim in .paider/paider.db.
     *
     * Two reasons that matters, in increasing severity:
     *
     *  1. The log is plaintext on disk and described as "local-only" — which it was, until RAG.
     *  2. `RagStore` embeds that same log and ships it to `PAIDER_EMBEDDING_URL`. So a foreign
     *     credential in a tool_call payload became an outbound request body.
     *
     * Found by an adversarial review (glm-5.3-flash) that noted the asymmetry precisely: every
     * message goes through scrubSecrets() and the tool_call event did not.
     *
     * DELIBERATELY CONSERVATIVE, because a redactor that eats ordinary text is worse than none —
     * it silently corrupts the log it is meant to protect. Each pattern requires a provider
     * prefix or an explicit keyword, not a bare high-entropy string: redacting any 20+ char
     * token would swallow hashes, ids and base64 chunks that the operator needs to read back.
     *
     * The real defence for a known-key leak remains ShellEnv, which does not hand live keys to a
     * child at all. This is the second layer, for keys the user deliberately puts in a command.
     */
    public static function scrubSecretPatterns(string $text): string
    {
        $patterns = [
            // Provider-prefixed keys, wherever they appear in a line.
            '/\b(sk-(?:live|test|proj|or)?[-_]?[A-Za-z0-9]{16,})\b/' => 'sk-',
            // ghp_/gho_/ghu_/ghs_/ghr_ GitHub tokens, and github_pat_ fine-grained ones.
            '/\b(gh[pousr]_[A-Za-z0-9]{20,})\b/' => 'gh',
            '/\b(github_pat_[A-Za-z0-9_]{20,})\b/' => 'github_pat_',
            // AWS access key ids, and Google API keys.
            '/\b(AKIA[0-9A-Z]{16})\b/' => 'AKIA',
            '/\b(AIza[0-9A-Za-z_-]{30,})\b/' => 'AIza',
            // xoxb/xoxp/xoxa Slack tokens.
            '/\b(xox[baprs]-[A-Za-z0-9-]{10,})\b/' => 'xox',
            // An Authorization / api-key header with an inline value. The keyword requirement is
            // what keeps this from firing on ordinary prose that happens to contain a long token.
            '/\b(Authorization\s*:\s*(?:Bearer|Basic|Token)\s+)([A-Za-z0-9._~+\/=-]{12,})/i' => 'auth-header',
            '/\b((?:api[_-]?key|apikey|access[_-]?token|auth[_-]?token|secret)\s*[:=]\s*)([\'"]?)([A-Za-z0-9._~+\/=-]{12,})/i' => 'kv-assign',
        ];

        foreach ($patterns as $pattern => $label) {
            $text = match ($label) {
                // Header and assignment forms keep their prefix, so the operator can still see
                // WHICH credential was used; only the value is replaced.
                'auth-header' => (string) preg_replace($pattern, '$1[redacted:auth-header]', $text),
                'kv-assign' => (string) preg_replace($pattern, '$1$2[redacted:kv]', $text),
                // Bare provider tokens carry no prefix to preserve.
                default => (string) preg_replace($pattern, '[redacted:'.$label.']', $text),
            };
        }

        return $text;
    }

    public function __construct()
    {
        // See ChatPrompt: registration goes before the parent constructor renders anything,
        // so the class cannot be built into an unregistered-renderer fatal.
        PromptTheme::activate();

        parent::__construct();

        // Set after parent::__construct(), which is where the cols - 20 default is assigned.
        $this->maxWidth = max(20, self::terminal()->cols() - self::MARGIN);
    }

    /**
     * Stock Stream::fadeOut() ignores Palette entirely: it gates only on
     * Terminal::supportsTrueColor() (COLORTERM), so under NO_COLOR/PAIDER_COLOR=0/non-tty it
     * still calls Terminal::queryColors(), which unconditionally fwrite()s an OSC 10/11 probe
     * to STDOUT with no tty or NO_COLOR check (vendor/laravel/prompts/src/Terminal.php:184),
     * and still emits raw truecolor \e[38;2;R;G;Bm (Stream.php:118) — falsifying Palette's own
     * "single source of colour" contract on the app's primary output surface. Skipping the
     * parent's colour-probing branch entirely when colour is off removes both.
     */
    protected function fadeOut(int $steps = 10): array
    {
        return Palette::enabled() ? parent::fadeOut($steps) : [fn (string $text) => $text];
    }
}
