<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rogga\Claudinho\ClaudinhoServiceProvider;

abstract class TestCase extends Orchestra
{

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            ClaudinhoServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Livewire assina o payload do componente: sem chave, qualquer render explode.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('claudinho.api_key', 'fake-key');
        $app['config']->set('claudinho.model', 'claude-opus-5');
        $app['config']->set('claudinho.max_tokens', 16000);
        $app['config']->set('claudinho.effort', 'medium');
        $app['config']->set('claudinho.timeout', 120);
    }
}
