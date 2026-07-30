<?php

use Rogga\Claudinho\Claude;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'claudinho.api_key' => 'fake-key',
        'claudinho.model' => 'claude-opus-5',
        'claudinho.max_tokens' => 16000,
        'claudinho.effort' => 'medium',
        'claudinho.timeout' => 120,
    ]);
});

it('devolve o texto concatenado dos blocos de texto da resposta', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => ''],
                ['type' => 'text', 'text' => 'O SGT controla '],
                ['type' => 'text', 'text' => 'terceiros em obras.'],
            ],
        ]),
    ]);

    $resposta = (new Claude)->mensagem([['role' => 'user', 'content' => 'O que é o SGT?']]);

    expect($resposta)->toBe('O SGT controla terceiros em obras.');
});

it('envia model, headers e system com cache_control no payload', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]),
    ]);

    (new Claude)->mensagem([['role' => 'user', 'content' => 'oi']], 'Você é o assistente do SGT.');

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-api-key', 'fake-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-opus-5'
            && $request['thinking']['type'] === 'adaptive'
            && $request['output_config']['effort'] === 'medium'
            && $request['system'][0]['text'] === 'Você é o assistente do SGT.'
            && $request['system'][0]['cache_control']['type'] === 'ephemeral';
    });
});

it('omite o system quando não informado', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]),
    ]);

    (new Claude)->mensagem([['role' => 'user', 'content' => 'oi']]);

    Http::assertSent(fn ($request) => ! isset($request['system']));
});

it('lança exceção com a mensagem de erro devolvida pela api', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'error' => ['message' => 'credit balance is too low'],
        ], 400),
    ]);

    expect(fn () => (new Claude)->mensagem([['role' => 'user', 'content' => 'oi']]))
        ->toThrow(RuntimeException::class, 'credit balance is too low');
});

it('lança exceção quando o modelo recusa a solicitação', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'stop_reason' => 'refusal',
            'content' => [],
        ]),
    ]);

    expect(fn () => (new Claude)->mensagem([['role' => 'user', 'content' => 'oi']]))
        ->toThrow(RuntimeException::class, 'recusada pelos filtros de segurança');
});

it('lança exceção quando a api key não está configurada', function () {
    config(['claudinho.api_key' => null]);

    expect(fn () => (new Claude)->mensagem([['role' => 'user', 'content' => 'oi']]))
        ->toThrow(RuntimeException::class, 'ANTHROPIC_API_KEY');

    expect((new Claude)->configurado())->toBeFalse();
});

it('emite eventos de texto e um evento fim ao fazer streaming', function () {
    fakeStream([
        ['type' => 'message_start'],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'ignorar']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Boa ']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'tarde!']],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
        ['type' => 'message_stop'],
    ]);

    $eventos = eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']]));

    $texto = collect($eventos)->where('tipo', 'texto')->pluck('conteudo')->implode('');

    expect($texto)->toBe('Boa tarde!')
        ->and(end($eventos))->toBe(['tipo' => 'fim', 'stop_reason' => 'end_turn']);
});

it('acumula input_json_delta e emite tool_use com o input decodificado', function () {
    fakeStream([
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Vou verificar.']],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_abc', 'name' => 'doctos_vencendo']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"dias":']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => ' 15, "obra": "Ravena"}']],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
        ['type' => 'message_stop'],
    ]);

    $eventos = eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']]));

    $toolUse = collect($eventos)->firstWhere('tipo', 'tool_use');

    expect($toolUse['id'])->toBe('toolu_abc')
        ->and($toolUse['nome'])->toBe('doctos_vencendo')
        ->and($toolUse['input'])->toBe(['dias' => 15, 'obra' => 'Ravena'])
        ->and(end($eventos)['stop_reason'])->toBe('tool_use');
});

it('emite tool_use com input vazio quando o json não vem', function () {
    fakeStream([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'status_ppc']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
    ]);

    $toolUse = collect(eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']])))
        ->firstWhere('tipo', 'tool_use');

    expect($toolUse['input'])->toBe([]);
});

it('envia tools e stream true no payload quando as ferramentas são informadas', function () {
    fakeStream([['type' => 'message_stop']]);

    $tools = [[
        'name' => 'status_ppc',
        'description' => 'Situação do PPC',
        'input_schema' => ['type' => 'object', 'properties' => []],
    ]];

    eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']], null, $tools));

    Http::assertSent(function ($request) {
        return $request['stream'] === true
            && $request['tools'][0]['name'] === 'status_ppc';
    });
});

it('omite tools no payload quando nenhuma ferramenta é informada', function () {
    fakeStream([['type' => 'message_stop']]);

    eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']]));

    Http::assertSent(fn ($request) => ! isset($request['tools']));
});

it('serializa input vazio de tool_use como objeto e não como array', function () {
    fakeStream([['type' => 'message_stop']]);

    $historico = [
        ['role' => 'user', 'content' => 'tem tarefa de ppc em restrição?'],
        ['role' => 'assistant', 'content' => [
            ['type' => 'text', 'text' => 'Vou verificar.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'status_ppc', 'input' => []],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"total_por_status":{}}'],
        ]],
    ];

    eventosDe((new Claude)->stream($historico));

    // "input":[] devolve 400 da API: messages.N.content.M.tool_use.input: Input should be an object
    Http::assertSent(function ($request) {
        return str_contains($request->body(), '"input":{}')
            && ! str_contains($request->body(), '"input":[]');
    });
});

it('preserva input preenchido de tool_use na serialização', function () {
    fakeStream([['type' => 'message_stop']]);

    $historico = [
        ['role' => 'user', 'content' => 'doctos da azaleia'],
        ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'doctos_vencendo', 'input' => ['dias' => 15]],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => '{}'],
        ]],
    ];

    eventosDe((new Claude)->stream($historico));

    Http::assertSent(fn ($request) => str_contains($request->body(), '"input":{"dias":15}'));
});

it('lança exceção quando o stream devolve um evento de erro', function () {
    fakeStream([
        ['type' => 'error', 'error' => ['message' => 'overloaded_error']],
    ]);

    expect(fn () => eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']])))
        ->toThrow(RuntimeException::class, 'overloaded_error');
});

it('lança exceção quando o stream sinaliza recusa', function () {
    fakeStream([
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'refusal']],
    ]);

    expect(fn () => eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']])))
        ->toThrow(RuntimeException::class, 'recusada pelos filtros de segurança');
});
