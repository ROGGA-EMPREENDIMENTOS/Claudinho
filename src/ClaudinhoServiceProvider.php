<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Rogga\Claudinho\Contracts\Ferramenta;
use Rogga\Claudinho\Ferramentas\GerarGrafico;
use Rogga\Claudinho\Livewire\Chat;
use Rogga\Claudinho\View\Components\Grafico;

class ClaudinhoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/claudinho.php', 'claudinho');

        $this->app->singleton(FerramentaRegistry::class, function ($app): FerramentaRegistry {
            $registro = new FerramentaRegistry;

            if (config('claudinho.grafico.habilitado', true)) {
                $registro->registrar(new GerarGrafico);
            }

            foreach ((array) config('claudinho.ferramentas', []) as $classe) {
                $ferramenta = $app->make($classe);

                if ($ferramenta instanceof Ferramenta) {
                    $registro->registrar($ferramenta);
                }
            }

            return $registro;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'claudinho');

        Livewire::component('claudinho.chat', Chat::class);
        Blade::component('claudinho::grafico', Grafico::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/claudinho.php' => config_path('claudinho.php'),
            ], 'claudinho-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/claudinho'),
            ], 'claudinho-views');
        }
    }
}
