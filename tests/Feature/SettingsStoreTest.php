<?php

use App\Support\SettingsStore;

beforeEach(function () {
    $this->originalCwd = getcwd();
    $this->tempCwd = sys_get_temp_dir().'/paider-settings-test-'.uniqid('', true);
    mkdir($this->tempCwd);
    chdir($this->tempCwd);
});

afterEach(function () {
    chdir($this->originalCwd);

    $settings = $this->tempCwd.'/.paider/settings.json';
    if (is_file($settings)) {
        unlink($settings);
    }
    if (is_dir($this->tempCwd.'/.paider')) {
        rmdir($this->tempCwd.'/.paider');
    }
    rmdir($this->tempCwd);
});

it('defaults activePreset to balanced when no settings file exists', function () {
    expect(SettingsStore::activePreset())->toBe('balanced');
});

it('persists and reads back a valid preset', function () {
    SettingsStore::setActivePreset('kimi');

    expect(SettingsStore::activePreset())->toBe('kimi');
});

it('rejects the accounts key as not a tier stack', function () {
    expect(fn () => SettingsStore::setActivePreset('accounts'))
        ->toThrow(InvalidArgumentException::class);

    expect(is_file($this->tempCwd.'/.paider/settings.json'))->toBeFalse();
});

it('rejects an unknown preset name', function () {
    expect(fn () => SettingsStore::setActivePreset('not-real'))
        ->toThrow(InvalidArgumentException::class);

    expect(is_file($this->tempCwd.'/.paider/settings.json'))->toBeFalse();
});

it('falls back to balanced when a stale settings file holds an invalid preset', function () {
    mkdir($this->tempCwd.'/.paider');
    file_put_contents($this->tempCwd.'/.paider/settings.json', json_encode(['preset' => 'accounts']));

    expect(SettingsStore::activePreset())->toBe('balanced');
});

it('falls back to balanced when a stale settings file holds an unknown preset', function () {
    mkdir($this->tempCwd.'/.paider');
    file_put_contents($this->tempCwd.'/.paider/settings.json', json_encode(['preset' => 'not-real']));

    expect(SettingsStore::activePreset())->toBe('balanced');
});

it('falls back to the default preset when the settings file is corrupt', function () {
    mkdir(getcwd().'/.paider');
    file_put_contents(getcwd().'/.paider/settings.json', '{"preset": "balanc');

    expect(SettingsStore::activePreset())->toBe('balanced');
});

it('falls back to the default preset when the settings file holds valid JSON of the wrong shape', function () {
    mkdir(getcwd().'/.paider');
    file_put_contents(getcwd().'/.paider/settings.json', '"just a string"');

    expect(SettingsStore::activePreset())->toBe('balanced');
});

it('falls back to the default preset instead of throwing when preset itself is an array or object', function () {
    mkdir(getcwd().'/.paider');

    file_put_contents(getcwd().'/.paider/settings.json', '{"preset": []}');
    expect(SettingsStore::activePreset())->toBe('balanced');

    file_put_contents(getcwd().'/.paider/settings.json', '{"preset": {"a": 1}}');
    expect(SettingsStore::activePreset())->toBe('balanced');
});
