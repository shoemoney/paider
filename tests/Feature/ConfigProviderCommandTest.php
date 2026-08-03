<?php

beforeEach(function () {
    $this->originalCwd = getcwd();
    $this->tempCwd = sys_get_temp_dir().'/paider-config-provider-test-'.uniqid('', true);
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

it('sets the active preset on a valid preset name', function () {
    $this->artisan('config:provider', ['preset' => 'kimi'])
        ->assertExitCode(0);

    $settings = json_decode(file_get_contents($this->tempCwd.'/.paider/settings.json'), true);

    expect($settings)->toBe(['preset' => 'kimi']);
});

it('rejects the accounts key and writes nothing', function () {
    $this->artisan('config:provider', ['preset' => 'accounts'])
        ->assertExitCode(1);

    expect(is_file($this->tempCwd.'/.paider/settings.json'))->toBeFalse();
});

it('rejects an unknown preset name and writes nothing', function () {
    $this->artisan('config:provider', ['preset' => 'not-a-real-preset'])
        ->assertExitCode(1);

    expect(is_file($this->tempCwd.'/.paider/settings.json'))->toBeFalse();
});
