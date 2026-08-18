<?php

namespace App\Support;

/**
 * Project-scoped settings: `.paider/settings.json` relative to getcwd().
 * Currently holds only the active preset name. Session-level tier overrides
 * (from `/tier`) are never persisted here — that store is a later work item.
 *
 * Read precedence: the project file (path()) always wins when present. When
 * it is absent, reads fall back to the user-level XDG file (xdgPath()) —
 * `$XDG_CONFIG_HOME/paider/settings.json`, or `~/.config/paider/settings.json`
 * when XDG_CONFIG_HOME is unset. If neither exists, built-in defaults apply.
 * All writes stay project-scoped: the XDG file is read-only from here.
 */
class SettingsStore
{
    public static function path(): string
    {
        return getcwd().'/.paider/settings.json';
    }

    public static function xdgPath(): string
    {
        $base = getenv('XDG_CONFIG_HOME');

        if ($base === false || trim($base) === '') {
            $home = getenv('HOME');
            $base = ($home === false || $home === '') ? '' : $home.'/.config';
        }

        return $base.'/paider/settings.json';
    }

    /**
     * The settings file that reads should actually consult: the project file
     * when it exists, else the XDG user file when that exists, else the
     * (nonexistent) project path so callers' is_file() checks fall through
     * to their built-in defaults.
     */
    private static function readPath(): string
    {
        if (is_file(self::path())) {
            return self::path();
        }

        if (self::xdgPath() !== '' && is_file(self::xdgPath())) {
            return self::xdgPath();
        }

        return self::path();
    }

    public static function activePreset(): string
    {
        $path = self::readPath();

        if (! is_file($path)) {
            return 'balanced';
        }

        // A hand-edited or truncated settings file should fall back to the default, not blow
        // up a session. Decode strictly so the fallback is a deliberate branch rather than a
        // null-offset warning that happens to land on the same value.
        try {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'balanced';
        }

        // Well-formed JSON of the wrong shape (preset as an array/object) is not caught by the
        // JsonException above — it decodes fine and then array_key_exists() below throws a
        // TypeError on a non-scalar key, breaking the "never blow up a session" promise this
        // method makes for itself. is_string() is the correct total check anyway: the return
        // type is string and every real preset name is one.
        $preset = is_array($data) && is_string($data['preset'] ?? null) ? $data['preset'] : 'balanced';

        return ($preset === 'accounts' || ! array_key_exists($preset, config('presets')))
            ? 'balanced'
            : $preset;
    }

    public static function testCommand(): ?string
    {
        $path = self::readPath();

        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['test_command'] ?? null)) {
            return null;
        }

        $cmd = trim($data['test_command']);

        return $cmd === '' ? null : $cmd;
    }

    public static function setTestCommand(?string $command): void
    {
        $path = self::path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $existing = [];

        if (is_file($path)) {
            try {
                $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            } catch (\JsonException) {
                $existing = [];
            }
        }

        if ($command === null || trim($command) === '') {
            unset($existing['test_command']);
        } else {
            $existing['test_command'] = trim($command);
        }

        if (! isset($existing['preset'])) {
            try {
                $existing['preset'] = self::activePreset();
            } catch (\Throwable) {
                $existing['preset'] = 'balanced';
            }
        }

        $tmp = $path.'.'.uniqid('', true).'.tmp';
        $json = json_encode($existing, JSON_THROW_ON_ERROR);

        if (file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to write settings to {$tmp}");
        }

        rename($tmp, $path);
    }

    public static function setActivePreset(string $preset): void
    {
        $presets = config('presets');

        if ($preset === 'accounts' || ! array_key_exists($preset, $presets)) {
            throw new \InvalidArgumentException("Unknown preset: {$preset}");
        }

        $path = self::path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $tmp = $path.'.'.uniqid('', true).'.tmp';

        // Preserve existing keys like test_command when updating preset
        $existing = [];

        if (is_file($path)) {
            try {
                $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            } catch (\JsonException) {
                $existing = [];
            }
        }

        $existing['preset'] = $preset;
        $json = json_encode($existing, JSON_THROW_ON_ERROR);

        // Renaming an unwritten or partially-written temp file over good settings loses them.
        // The point of write-then-rename is atomicity, which only holds if the write is checked.
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to write settings to {$tmp}");
        }

        rename($tmp, $path);
    }
}
