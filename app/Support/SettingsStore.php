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

        $data = json_decode(file_get_contents($path), true);
        $preset = $data['preset'] ?? 'balanced';

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

        $dir = getcwd().'/.paider';

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = self::path();
        $tmp = $path.'.'.uniqid('', true).'.tmp';

        file_put_contents($tmp, json_encode(['preset' => $preset]));
        rename($tmp, $path);
    }
}
