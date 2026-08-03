<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Rogga\Claudinho\Console\LimparConversas;
use Rogga\Claudinho\Contracts\Ferramenta;
use Rogga\Claudinho\Ferramentas\GerarGrafico;
use Rogga\Claudinho\Http\Controllers\ConversaController;
use Rogga\Claudinho\Http\Middleware\AutenticaCanal;
use Rogga\Claudinho\Livewire\Chat;
use Rogga\Claudinho\Livewire\Configuracoes;
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
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Livewire::component('claudinho.chat', Chat::class);
        Livewire::component('claudinho.configuracoes', Configuracoes::class);
        Blade::component('claudinho::grafico', Grafico::class);

        $this->registraRotas();

        if ($this->app->runningInConsole()) {
            $this->commands([LimparConversas::class]);

            $this->publishes([
                __DIR__.'/../config/claudinho.php' => config_path('claudinho.php'),
            ], 'claudinho-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/claudinho'),
            ], 'claudinho-views');

            // O logo é PNG servido pelo webserver, não asset compilado: sem este publish
            // o header aparece com imagem quebrada. Republicar a cada update do pacote.
            $this->publishes([
                __DIR__.'/../resources/images' => public_path('vendor/claudinho'),
            ], 'claudinho-assets');
        }
    }

    /**
     * O endpoint para canais externos. Desligado por padrão: rota de escrita que
     * passa a existir só por ter atualizado o pacote seria surpresa de segurança.
     *
     * O middleware do token vem sempre, e não como opção da aplicação — habilitar o
     * endpoint sem autenticar o chamador não é uma configuração que faça sentido
     * oferecer. O que a aplicação escolhe é o que vem ANTES dele.
     */
    private function registraRotas(): void
    {
        if (! config('claudinho.api.habilitado', false)) {
            return;
        }

        $throttle = (string) config('claudinho.api.throttle', '30,1');

        Route::prefix((string) config('claudinho.api.prefixo', 'claudinho'))
            ->middleware(array_merge(
                (array) config('claudinho.api.middleware', ['api']),
                $throttle !== '' ? ["throttle:{$throttle}"] : [],
                [AutenticaCanal::class],
            ))
            ->group(function (): void {
                Route::post('conversa', [ConversaController::class, 'conversar'])
                    ->name('claudinho.conversa');

                Route::post('conversa/reiniciar', [ConversaController::class, 'reiniciar'])
                    ->name('claudinho.conversa.reiniciar');
            });
    }
}
