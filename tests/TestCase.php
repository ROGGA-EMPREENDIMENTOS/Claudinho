<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rogga\Claudinho\ClaudinhoServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Config aplicado ANTES de a aplicação subir. Ver comConfig().
     *
     * @var array<string, mixed>
     */
    private array $configExtra = [];

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

        foreach ($this->configExtra as $chave => $valor) {
            $app['config']->set($chave, $valor);
        }
    }

    /**
     * Recria a aplicação com config extra.
     *
     * Necessário porque as rotas do endpoint são registradas no boot do provider a
     * partir do config: um config()->set() depois de a aplicação ter subido não
     * cria rota nenhuma, e o teste passaria por motivo errado.
     *
     * @param  array<string, mixed>  $config
     */
    public function comConfig(array $config): void
    {
        $this->configExtra = $config;

        $this->reloadApplication();
    }
}
