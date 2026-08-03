<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Rogga\Claudinho\Models\Configuracao;
use Rogga\Claudinho\Models\ConversaExterna;

/**
 * O endpoint com a conversa persistida: continuidade, expiração e a confirmação de
 * ação por texto de ponta a ponta.
 *
 * Separado do EndpointTest porque só este precisa de banco — o outro cobre rota,
 * token e resolver sem tabela nenhuma.
 */
beforeEach(function () {
    // comEndpoint() recria a aplicação; migrar e limpar tem de vir depois dela.
    comEndpoint();
    exigeBanco();

    Gate::define('pode_cancelar', fn () => true);
    CancelarPedido::$executadas = [];
});

function conversar(string $mensagem, string $identificador = '5547999998888'): TestResponse
{
    return test()->withToken('token-do-gateway')->postJson('/claudinho/conversa', [
        'canal' => 'whatsapp',
        'identificador' => $identificador,
        'mensagem' => $mensagem,
    ]);
}

it('responde e guarda a conversa do número', function () {
    fakeStreams(rodadaTexto('São 12 obras ativas.'));

    conversar('quantas obras ativas?')
        ->assertOk()
        ->assertJson([
            'resposta' => 'São 12 obras ativas.',
            'estado' => 'concluida',
            'confirmacao' => null,
        ]);

    $registro = ConversaExterna::query()->firstOrFail();

    expect($registro->canal)->toBe('whatsapp')
        ->and($registro->identificador)->toBe('5547999998888')
        // O id vem do resolver: é quem responde pela permissão daqui para baixo.
        ->and($registro->user_id)->toBe(7)
        ->and($registro->estado['mensagens'])->toHaveCount(2);
});

it('continua a mesma conversa na mensagem seguinte', function () {
    fakeStreams(rodadaTexto('São 12.'), rodadaTexto('A maior é a Azaleia.'));

    conversar('quantas obras ativas?')->assertOk();
    conversar('e qual a maior?')->assertOk();

    expect(ConversaExterna::query()->count())->toBe(1);

    // O histórico acumula, senão a segunda pergunta não teria contexto nenhum.
    $mensagens = ConversaExterna::query()->firstOrFail()->estado['mensagens'];

    expect($mensagens)->toHaveCount(4)
        ->and($mensagens[2]['content'])->toBe('e qual a maior?');
});

it('separa conversas de números diferentes', function () {
    ResolvedorFake::$conhecidos = ['5547999998888' => 7, '5547977776666' => 9];
    fakeStreams(rodadaTexto('a'), rodadaTexto('b'));

    conversar('oi', '5547999998888')->assertOk();
    conversar('oi', '5547977776666')->assertOk();

    expect(ConversaExterna::query()->count())->toBe(2)
        ->and(ConversaExterna::query()->pluck('user_id')->sort()->values()->all())->toBe([7, 9]);
});

it('recomeça depois do silêncio configurado', function () {
    fakeStreams(rodadaTexto('a'), rodadaTexto('b'));

    conversar('primeira')->assertOk();

    // Vencida é reaproveitada com estado zerado, não apagada: mantém uma linha por
    // canal+identificador e evita corrida no unique da tabela.
    ConversaExterna::query()->update(['expira_em' => now()->subMinute()]);

    conversar('segunda')->assertOk();

    $registro = ConversaExterna::query()->firstOrFail();

    expect(ConversaExterna::query()->count())->toBe(1)
        ->and($registro->estado['mensagens'])->toHaveCount(2)
        ->and($registro->estado['mensagens'][0]['content'])->toBe('segunda');
});

it('zera a conversa quando o resolver passa a devolver outro usuário', function () {
    fakeStreams(rodadaTexto('a'), rodadaTexto('b'));

    conversar('primeira')->assertOk();

    // O histórico anterior foi respondido sob outro escopo de permissão e não pode
    // continuar no contexto de quem assumiu o número.
    ResolvedorFake::$conhecidos = ['5547999998888' => 99];

    conversar('segunda')->assertOk();

    $registro = ConversaExterna::query()->firstOrFail();

    expect($registro->user_id)->toBe(99)
        ->and($registro->estado['mensagens'])->toHaveCount(2);
});

it('reinicia a conversa quando pedido', function () {
    fakeStreams(rodadaTexto('a'));

    conversar('oi')->assertOk();

    test()->withToken('token-do-gateway')
        ->postJson('/claudinho/conversa/reiniciar', [
            'canal' => 'whatsapp',
            'identificador' => '5547999998888',
        ])
        ->assertOk()
        ->assertJson(['estado' => 'reiniciada']);

    expect(ConversaExterna::query()->count())->toBe(0);
});

/**
 * =====================================
 * Confirmação de ação por texto
 * =====================================
 */
it('pede confirmação em vez de executar a ação', function () {
    registro([new CancelarPedido]);
    fakeStreams(rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821], 'Vou cancelar.'));

    $resposta = conversar('cancela o pedido 4821');

    $resposta->assertOk()->assertJson([
        'estado' => 'aguardando_confirmacao',
        'confirmacao' => 'Cancelar o pedido 4821?',
    ]);

    expect(CancelarPedido::$executadas)->toBe([])
        // A resposta pronta traz a instrução: sem ela a pessoa não sabe que a
        // palavra é exigida exata.
        ->and($resposta->json('resposta'))
        ->toContain('Cancelar o pedido 4821?')
        ->toContain('Responda apenas SIM');
});

it('executa quando a resposta é exatamente SIM', function () {
    registro([new CancelarPedido]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Pedido 4821 cancelado.'),
    );

    conversar('cancela o pedido 4821')->assertOk();

    conversar('SIM')
        ->assertOk()
        ->assertJson(['estado' => 'concluida', 'confirmacao' => null])
        ->assertJsonPath('resposta', 'Pedido 4821 cancelado.');

    expect(CancelarPedido::$executadas)->toBe([['pedido' => 4821]]);
});

it('cancela a ação quando a resposta não é exatamente SIM', function () {
    registro([new CancelarPedido]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Ok, não cancelei nada.'),
    );

    conversar('cancela o pedido 4821')->assertOk();

    // O caso que justifica a regra: por conteúdo, isto autorizaria o oposto.
    $resposta = conversar('não, não confirmo');

    $resposta->assertOk()->assertJson(['estado' => 'concluida']);

    expect(CancelarPedido::$executadas)->toBe([])
        ->and($resposta->json('resposta'))->toContain('cancelada');
});

it('cancela a ação quando o prazo de confirmação expira', function () {
    registro([new CancelarPedido]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Ok, deixei como estava.'),
    );

    conversar('cancela o pedido 4821')->assertOk();

    // Um "sim" solto tempo depois não pode autorizar alteração já esquecida.
    ConversaExterna::query()->update(['confirmar_ate' => now()->subMinute()]);

    $resposta = conversar('sim');

    expect(CancelarPedido::$executadas)->toBe([])
        ->and($resposta->json('resposta'))->toContain('prazo para confirmar');
});

it('não deixa a pergunta seguinte passar por cima da pendência', function () {
    registro([new CancelarPedido]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Cancelei nada. Sobre obras ativas, pergunte de novo.'),
    );

    conversar('cancela o pedido 4821')->assertOk();

    // Tratar isto como pergunta nova deixaria um tool_use sem tool_result e a API
    // rejeitaria a conversa inteira dali em diante.
    $resposta = conversar('quantas obras ativas?');

    $resposta->assertOk()->assertJson(['estado' => 'concluida']);

    expect(CancelarPedido::$executadas)->toBe([])
        ->and(ConversaExterna::query()->firstOrFail()->estado['pendentes'])->toBe([]);
});

it('não oferece nem executa ação quando o canal é somente-leitura', function () {
    comEndpoint(['claudinho.api.acoes' => false]);

    registro([new CancelarPedido]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Não altero dados por aqui.'),
    );

    $resposta = conversar('cancela o pedido 4821');

    $resposta->assertOk()->assertJson(['estado' => 'concluida', 'confirmacao' => null]);

    expect(CancelarPedido::$executadas)->toBe([]);
});

it('devolve 502 sem inutilizar a conversa quando a API falha', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(
            ['error' => ['message' => 'sobrecarregado']], 529
        ),
    ]);

    conversar('quantas obras ativas?')
        ->assertStatus(502)
        ->assertJson(['estado' => 'erro']);

    // Estado gravado mesmo na falha: o motor fechou o que estava aberto, então a
    // próxima pergunta ainda funciona.
    expect(ConversaExterna::query()->count())->toBe(1);
});

it('responde algo mesmo quando o modelo não escreve texto', function () {
    registro([new BuscarPedido]);

    // Só chamada de ferramenta, sem uma palavra: silêncio parece falha para quem
    // está do outro lado.
    fakeStreams(
        rodadaToolUse('toolu_q', 'buscar_pedido', ['pedido' => 1]),
        [['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]],
    );

    $resposta = conversar('e o pedido 1?');

    $resposta->assertOk();

    expect($resposta->json('resposta'))->not->toBe('');
});

it('recusa o atendimento quando desligado na tela de configurações', function () {
    Configuracao::definirBooleano('api_ativa', false);

    fakeStreams(rodadaTexto('nunca chega aqui'));

    conversar('quantas obras ativas?')->assertStatus(503);

    // Nem conversa foi criada: o interruptor barra antes de qualquer processamento.
    expect(ConversaExterna::query()->count())->toBe(0);
});

it('checa o interruptor depois do token, não antes', function () {
    Configuracao::definirBooleano('api_ativa', false);

    // Quem não se autenticou não descobre se o atendimento está ligado — 401 e não
    // 503, senão o endpoint vira um sensor de estado para quem não tem token.
    test()->postJson('/claudinho/conversa', [
        'canal' => 'whatsapp', 'identificador' => '5547999998888', 'mensagem' => 'oi',
    ])->assertStatus(401);
});

it('volta a atender quando religado', function () {
    Configuracao::definirBooleano('api_ativa', false);
    conversar('oi')->assertStatus(503);

    Configuracao::definirBooleano('api_ativa', true);
    fakeStreams(rodadaTexto('São 12 obras ativas.'));

    conversar('quantas obras ativas?')->assertOk();
});
