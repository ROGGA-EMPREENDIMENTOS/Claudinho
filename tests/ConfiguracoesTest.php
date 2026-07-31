<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rogga\Claudinho\Claude;
use Rogga\Claudinho\Livewire\Chat;
use Rogga\Claudinho\Livewire\Configuracoes;
use Rogga\Claudinho\Models\Configuracao;

// Só este arquivo precisa de banco. Migra na mão em vez de usar RefreshDatabase
// porque a trait roda no setUp, antes do skip abaixo — e o :memory: do testbench
// já nasce vazio em cada teste, então não há o que reverter no fim.
beforeEach(function () {
    if (! extension_loaded('pdo_sqlite')) {
        test()->markTestSkipped(
            'Requer a extensão pdo_sqlite para o banco em memória do testbench: '
            .'sudo apt install php8.3-sqlite3'
        );
    }

    test()->artisan('migrate')->run();
});

/**
 * Autentica um usuário e resolve o gate de administração como quiser.
 */
function comoAdmin(bool $permitido = true): void
{
    Gate::define('claudinho_admin', fn ($usuario): bool => $permitido);

    $usuario = new class extends User
    {
        protected $table = 'users';
    };

    $usuario->forceFill(['id' => 1, 'name' => 'Admin']);

    test()->actingAs($usuario);
}

it('grava a chave da API criptografada, não em texto puro', function () {
    Configuracao::definir('api_key', 'sk-ant-api03-super-secreta');

    $bruto = (string) DB::table('claudinho_configuracoes')->where('chave', 'api_key')->value('valor');

    expect($bruto)
        ->not->toContain('sk-ant-api03-super-secreta')
        ->and(Configuracao::valor('api_key'))->toBe('sk-ant-api03-super-secreta');
});

it('faz o valor gravado em tela vencer o config', function () {
    config()->set('claudinho.model', 'claude-sonnet-5');

    expect(Configuracao::valor('model', config('claudinho.model')))->toBe('claude-sonnet-5');

    Configuracao::definir('model', 'claude-opus-5');

    expect(Configuracao::valor('model', config('claudinho.model')))->toBe('claude-opus-5');
});

it('trata valor vazio como ausente, voltando para o config', function () {
    config()->set('claudinho.api_key', 'do-env');

    Configuracao::definir('api_key', 'da-tela');
    expect(Configuracao::valor('api_key', config('claudinho.api_key')))->toBe('da-tela');

    // É assim que o botão Limpar devolve o controle para o .env.
    Configuracao::definir('api_key', null);
    expect(Configuracao::valor('api_key', config('claudinho.api_key')))->toBe('do-env');
});

it('usa no payload da API o modelo definido em tela', function () {
    config()->set('claudinho.model', 'claude-sonnet-5');
    Configuracao::definir('model', 'claude-opus-5');
    Configuracao::definir('api_key', 'sk-ant-da-tela');

    fakeStream([['type' => 'message_stop']]);

    eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']]));

    Http::assertSent(
        fn ($request) => $request['model'] === 'claude-opus-5'
            && $request->header('x-api-key') === ['sk-ant-da-tela']
    );
});

it('esconde a engrenagem de quem não tem a permissão', function () {
    comoAdmin(permitido: false);

    Livewire::test(Chat::class)
        ->assertDontSee('claudinho-abrir-configuracoes', false)
        ->assertDontSee('Configurações do Claudinho');
});

it('mostra a engrenagem para quem tem a permissão', function () {
    comoAdmin();

    Livewire::test(Chat::class)
        ->assertSee('claudinho-abrir-configuracoes', false)
        ->assertSee('aria-label="Configurações"', false);
});

it('salva modelo e chave pelo componente de configurações', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->set('modelo', 'claude-haiku-4-5')
        ->set('chaveNova', 'sk-ant-api03-uma-chave-bem-longa')
        ->call('salvar')
        ->assertHasNoErrors()
        // O campo é só de escrita: precisa sair limpo para não voltar no HTML.
        ->assertSet('chaveNova', '')
        ->assertDispatched('claudinho-configuracoes-salvas');

    expect(Configuracao::valor('model'))->toBe('claude-haiku-4-5')
        ->and(Configuracao::valor('api_key'))->toBe('sk-ant-api03-uma-chave-bem-longa');
});

it('mantém a chave atual quando o campo é enviado em branco', function () {
    comoAdmin();
    Configuracao::definir('api_key', 'sk-ant-api03-a-que-ja-existia');

    Livewire::test(Configuracoes::class)
        ->set('modelo', 'claude-opus-5')
        ->call('salvar')
        ->assertHasNoErrors();

    expect(Configuracao::valor('api_key'))->toBe('sk-ant-api03-a-que-ja-existia');
});

it('nunca coloca a chave gravada no HTML da tela', function () {
    comoAdmin();
    Configuracao::definir('api_key', 'sk-ant-api03-nao-pode-aparecer');

    $html = Livewire::test(Configuracoes::class)->html();

    expect($html)
        ->not->toContain('sk-ant-api03-nao-pode-aparecer')
        // Mas a dica mascarada confirma qual chave está valendo.
        ->toContain('sk-ant-a');
});

it('recusa a gravação de quem não tem a permissão', function () {
    comoAdmin(permitido: false);

    Livewire::test(Configuracoes::class)
        ->set('modelo', 'claude-opus-5')
        ->call('salvar')
        ->assertForbidden();
});

it('rejeita chave curta demais para ser válida', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->set('modelo', 'claude-opus-5')
        ->set('chaveNova', 'sk-ant-curta')
        ->call('salvar')
        ->assertHasErrors(['chaveNova' => 'min']);

    expect(Configuracao::valor('api_key'))->toBeNull();
});

it('sobrevive à tabela ainda não migrada, caindo no config', function () {
    config()->set('claudinho.model', 'claude-sonnet-5');
    config()->set('claudinho.api_key', 'do-env');

    Schema::drop('claudinho_configuracoes');

    expect(Configuracao::todas())->toBe([])
        ->and(Configuracao::valor('model', config('claudinho.model')))->toBe('claude-sonnet-5')
        ->and(Configuracao::valor('api_key', config('claudinho.api_key')))->toBe('do-env');
});

it('mantém selecionável um modelo fora da lista do config', function () {
    comoAdmin();
    Configuracao::definir('model', 'claude-fable-5');

    $disponiveis = Livewire::test(Configuracoes::class)->instance()->modelosDisponiveis();

    expect($disponiveis)->toHaveKey('claude-fable-5')
        ->and(array_key_first($disponiveis))->toBe('claude-fable-5');
});
