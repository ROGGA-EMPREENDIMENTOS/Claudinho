<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rogga\Claudinho\Http\Middleware\AutenticaCanal;

/**
 * O endpoint para canais externos.
 *
 * Este arquivo cobre o que não depende de banco: registro da rota, token do
 * chamador e o resolver de usuário. A conversa persistida está no
 * EndpointConversaTest, que precisa da tabela.
 *
 * As rotas são registradas no boot do provider a partir do config, então cada
 * cenário reinicia a aplicação com o config que quer — não dá para ligar o
 * endpoint depois de a aplicação já ter subido.
 */
beforeEach(function () {
    ResolvedorFake::$chamadas = [];
    ResolvedorFake::$conhecidos = ['5547999998888' => 7];
});

it('registra as rotas sempre, e quem liga ou desliga é o middleware', function () {
    // Decidir isto no boot exigiria ler o banco em TODA requisição da aplicação, e
    // é o banco que guarda o interruptor da tela. Rota existir com o endpoint
    // desligado não é brecha: o middleware recusa antes de qualquer processamento,
    // e depois do token — quem não se autenticou recebe 401 nos dois casos.
    expect(Route::has('claudinho.conversa'))->toBeTrue()
        ->and(Route::has('claudinho.conversa.reiniciar'))->toBeTrue();
});

it('não vaza pela resposta se o endpoint está ligado, para quem não tem token', function () {
    comEndpoint(['claudinho.api.habilitado' => false]);

    // Mesmo 401 do endpoint ligado: a resposta não serve de sensor de estado.
    test()->postJson('/claudinho/conversa', [
        'canal' => 'whatsapp', 'identificador' => '5547999998888', 'mensagem' => 'oi',
    ])->assertStatus(401);
});

it('recusa requisição sem token', function () {
    comEndpoint();

    test()->postJson('/claudinho/conversa', [
        'canal' => 'whatsapp',
        'identificador' => '5547999998888',
        'mensagem' => 'oi',
    ])->assertStatus(401);
});

it('recusa token errado', function () {
    comEndpoint();

    test()->withToken('outro-token')
        ->postJson('/claudinho/conversa', [
            'canal' => 'whatsapp',
            'identificador' => '5547999998888',
            'mensagem' => 'oi',
        ])
        ->assertStatus(401);
});

it('responde 503 quando o endpoint está ligado sem token configurado', function () {
    comEndpoint(['claudinho.api.token' => null]);

    // Token em branco não pode liberar: seria endpoint aberto por esquecimento de
    // configurar, que é justamente o acidente a evitar.
    test()->postJson('/claudinho/conversa', [
        'canal' => 'whatsapp',
        'identificador' => '5547999998888',
        'mensagem' => 'oi',
    ])->assertStatus(503);
});

it('recusa remetente que o resolver não conhece, sem dizer o motivo', function () {
    comEndpoint();

    $resposta = test()->withToken('token-do-gateway')
        ->postJson('/claudinho/conversa', [
            'canal' => 'whatsapp',
            'identificador' => '5547911111111',
            'mensagem' => 'quantas obras ativas?',
        ]);

    $resposta->assertStatus(403);

    // Mensagem genérica: quem chama não deve mapear os números cadastrados
    // testando um por um.
    expect($resposta->json('message'))->not->toContain('5547911111111');
});

it('passa canal e identificador para o resolver', function () {
    comEndpoint();
    ResolvedorFake::$conhecidos = [];

    test()->withToken('token-do-gateway')
        ->postJson('/claudinho/conversa', [
            'canal' => 'telegram',
            'identificador' => 'abc123',
            'mensagem' => 'oi',
        ])->assertStatus(403);

    expect(ResolvedorFake::$chamadas)->toBe([['telegram', 'abc123']]);
});

it('exige canal, identificador e mensagem', function () {
    comEndpoint();

    test()->withToken('token-do-gateway')
        ->postJson('/claudinho/conversa', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['canal', 'identificador', 'mensagem']);
});

it('explica quando o resolvedor não foi configurado', function () {
    comEndpoint(['claudinho.api.resolvedor' => null]);

    // Erro de configuração precisa ser legível: sem resolver, o endpoint não tem
    // como saber de quem é a permissão, e responder qualquer coisa seria pior.
    test()->withToken('token-do-gateway')
        ->postJson('/claudinho/conversa', [
            'canal' => 'whatsapp',
            'identificador' => '5547999998888',
            'mensagem' => 'oi',
        ])->assertStatus(500);
});

it('aplica o throttle configurado', function () {
    comEndpoint(['claudinho.api.throttle' => '2,1']);

    $rota = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'claudinho.conversa');

    expect($rota->gatherMiddleware())->toContain('throttle:2,1');
});

it('sempre põe o middleware do token, mesmo com middleware customizado', function () {
    comEndpoint(['claudinho.api.middleware' => ['api'], 'claudinho.api.throttle' => '']);

    $rota = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'claudinho.conversa');

    // Habilitar o endpoint sem autenticar o chamador não é configuração oferecida.
    expect($rota->gatherMiddleware())
        ->toContain(AutenticaCanal::class);
});

it('usa o prefixo configurado', function () {
    comEndpoint(['claudinho.api.prefixo' => 'bot/v1']);

    $rota = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'claudinho.conversa');

    expect($rota->uri())->toBe('bot/v1/conversa');
});

it('exige do remetente a mesma permissão que abre o chat em tela', function () {
    comEndpoint(['claudinho.permissao' => 'use_assistente']);

    Gate::define('use_assistente', fn (): bool => false);

    // Número cadastrado, mas sem a permissão: sem esta checagem, o WhatsApp seria um
    // caminho paralelo para usar o assistente sem o gate que a tela exige.
    $resposta = test()->withToken('token-do-gateway')
        ->postJson('/claudinho/conversa', [
            'canal' => 'whatsapp',
            'identificador' => '5547999998888',
            'mensagem' => 'quantas obras ativas?',
        ]);

    $resposta->assertStatus(403);

    // Mesma mensagem de número desconhecido: a resposta não distingue "não
    // cadastrado" de "sem permissão", senão vira sonda de quem tem acesso.
    expect($resposta->json('message'))->toBe('Remetente não autorizado.');
});

it('atende quando o remetente tem a permissão', function () {
    comEndpoint(['claudinho.permissao' => 'use_assistente']);

    Gate::define('use_assistente', fn (): bool => true);
    fakeStreams(rodadaTexto('São 12 obras ativas.'));

    test()->withToken('token-do-gateway')
        ->postJson('/claudinho/conversa', [
            'canal' => 'whatsapp',
            'identificador' => '5547999998888',
            'mensagem' => 'quantas obras ativas?',
        ])
        ->assertOk();
})->skip(fn () => ! extension_loaded('pdo_sqlite') && ! env('DB_CONNECTION'), 'Requer banco.');
