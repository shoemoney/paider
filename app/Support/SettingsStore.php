<?php

namespace App\Support;

/**
 * Project-scoped settings: `.paider/settings.json` relative to getcwd().
 * Currently holds only the active preset name. Session-level tier overrides
 * (from `/tier`) are never persisted here — that store is a later work item.
 */
class SettingsStore
{
    public static function path(): string
    {
        return getcwd().'/.paider/settings.json';
    }

    public static function activePreset(): string
    {
        $path = self::path();

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

        $json = json_encode(['preset' => $preset], JSON_THROW_ON_ERROR);

        // Renaming an unwritten or partially-written temp file over good settings loses them.
        // The point of write-then-rename is atomicity, which only holds if the write is checked.
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to write settings to {$tmp}");
        }

        rename($tmp, $path);
    }
}
