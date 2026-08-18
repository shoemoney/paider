<?php

use App\Support\SettingsStore;

beforeEach(function () {
    $this->originalCwd = getcwd();
    $this->originalXdgConfigHome = getenv('XDG_CONFIG_HOME');
    $this->originalHome = getenv('HOME');

    $this->tempCwd = sys_get_temp_dir().'/paider-xdg-project-'.uniqid('', true);
    mkdir($this->tempCwd);
    chdir($this->tempCwd);

    $this->tempXdg = sys_get_temp_dir().'/paider-xdg-config-'.uniqid('', true);
    mkdir($this->tempXdg, 0777, true);
    putenv('XDG_CONFIG_HOME='.$this->tempXdg);
});

afterEach(function () {
    chdir($this->originalCwd);

    if ($this->originalXdgConfigHome === false) {
        putenv('XDG_CONFIG_HOME');
    } else {
        putenv('XDG_CONFIG_HOME='.$this->originalXdgConfigHome);
    }

    if ($this->originalHome === false) {
        putenv('HOME');
    } else {
        putenv('HOME='.$this->originalHome);
    }

    exec('rm -rf '.escapeshellarg($this->tempCwd));
    exec('rm -rf '.escapeshellarg($this->tempXdg));
});

function writeProjectSettings(string $cwd, array $data): void
{
    mkdir($cwd.'/.paider', 0777, true);
    file_put_contents($cwd.'/.paider/settings.json', json_encode($data));
}

function writeXdgSettings(string $xdgConfigHome, array $data): void
{
    mkdir($xdgConfigHome.'/paider', 0777, true);
    file_put_contents($xdgConfigHome.'/paider/settings.json', json_encode($data));
}

it('reads the project settings when only the project file exists', function () {
    writeProjectSettings($this->tempCwd, ['preset' => 'kimi']);

    expect(SettingsStore::activePreset())->toBe('kimi');
});

it('falls back to the XDG settings when only the XDG file exists', function () {
    writeXdgSettings($this->tempXdg, ['preset' => 'kimi']);

    expect(SettingsStore::activePreset())->toBe('kimi');
});

it('prefers the project settings over XDG when both exist', function () {
    writeProjectSettings($this->tempCwd, ['preset' => 'kimi']);
    writeXdgSettings($this->tempXdg, ['preset' => 'zai']);

    expect(SettingsStore::activePreset())->toBe('kimi');
});

it('falls back to built-in defaults when neither file exists', function () {
    expect(SettingsStore::activePreset())->toBe('balanced');
});

it('defaults XDG_CONFIG_HOME to ~/.config/paider when unset', function () {
    putenv('XDG_CONFIG_HOME');

    $fakeHome = sys_get_temp_dir().'/paider-xdg-home-'.uniqid('', true);
    mkdir($fakeHome.'/.config/paider', 0777, true);
    putenv('HOME='.$fakeHome);

    file_put_contents($fakeHome.'/.config/paider/settings.json', json_encode(['preset' => 'kimi']));

    expect(SettingsStore::xdgPath())->toBe($fakeHome.'/.config/paider/settings.json');
    expect(SettingsStore::activePreset())->toBe('kimi');

    exec('rm -rf '.escapeshellarg($fakeHome));
});

it('never writes to the XDG settings file — writes stay project-scoped', function () {
    writeXdgSettings($this->tempXdg, ['preset' => 'balanced']);

    SettingsStore::setActivePreset('kimi');

    expect(SettingsStore::path())->toBe(getcwd().'/.paider/settings.json');
    expect(is_file(getcwd().'/.paider/settings.json'))->toBeTrue();

    $xdgContents = json_decode(file_get_contents($this->tempXdg.'/paider/settings.json'), true);
    expect($xdgContents['preset'])->toBe('balanced');

    // Reading now prefers the freshly-written project file over the untouched XDG one.
    expect(SettingsStore::activePreset())->toBe('kimi');
});

it('resolves testCommand through the same XDG fallback chain', function () {
    writeXdgSettings($this->tempXdg, ['test_command' => 'vendor/bin/pest']);

    expect(SettingsStore::testCommand())->toBe('vendor/bin/pest');

    writeProjectSettings($this->tempCwd, ['test_command' => 'phpunit']);

    expect(SettingsStore::testCommand())->toBe('phpunit');
});
