<?php

namespace Tests;

use App\Models\Channel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // Channel work folders and outboxes go to a throwaway folder.
        $this->storage = sys_get_temp_dir().'/channel-manager-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app');
        File::ensureDirectoryExists($this->storage.'/framework/views');
        $this->app->useStoragePath($this->storage);
        config(['view.compiled' => $this->storage.'/framework/views']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    protected function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/Fixtures/'.$name);
    }

    protected function makeChannel(array $overrides = [], array $settings = []): Channel
    {
        return Channel::create($overrides + [
            'name' => 'Trend Brief',
            'platform' => 'facebook',
            'strategy' => 'trending',
            'active' => true,
            'live' => false,
            'settings' => array_replace_recursive(Channel::defaultSettings(), ['timezone' => 'UTC'], $settings),
        ]);
    }
}
