<?php

declare(strict_types=1);

use Rogga\Claudinho\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Monta um corpo SSE no mesmo formato que a API devolve.
 */
function sseBody(array $eventos): string
{
    $linhas = [];

    foreach ($eventos as $evento) {
        $linhas[] = 'event: '.$evento['type'];
        $linhas[] = 'data: '.json_encode($evento);
        $linhas[] = '';
    }

    return implode("\n", $linhas);
}

function fakeStream(array $eventos): void
{
    Illuminate\Support\Facades\Http::fake([
        'api.anthropic.com/v1/messages' => Illuminate\Support\Facades\Http::response(sseBody($eventos)),
    ]);
}

/**
 * Uma resposta por volta do loop de ferramentas. Sem sequência, o fake devolve o
 * mesmo tool_use para sempre e o loop só para no max_iteracoes.
 */
function fakeStreams(array ...$rodadas): void
{
    $sequencia = Illuminate\Support\Facades\Http::sequence();

    foreach ($rodadas as $eventos) {
        $sequencia->push(sseBody($eventos));
    }

    Illuminate\Support\Facades\Http::fake(['api.anthropic.com/v1/messages' => $sequencia]);
}

/**
 * Eventos SSE de uma volta que chama uma ferramenta.
 */
function rodadaToolUse(string $id, string $nome, array $input = [], string $texto = ''): array
{
    $eventos = [];

    if ($texto !== '') {
        $eventos[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $texto]];
    }

    $eventos[] = ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $nome]];
    $eventos[] = ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($input ?: new stdClass)]];
    $eventos[] = ['type' => 'content_block_stop', 'index' => 1];
    $eventos[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']];
    $eventos[] = ['type' => 'message_stop'];

    return $eventos;
}

/**
 * Eventos SSE de uma volta que só devolve texto.
 */
function rodadaTexto(string $texto): array
{
    return [
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $texto]],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
        ['type' => 'message_stop'],
    ];
}

function eventosDe(Generator $stream): array
{
    return iterator_to_array($stream, preserve_keys: false);
}
