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

function eventosDe(Generator $stream): array
{
    return iterator_to_array($stream, preserve_keys: false);
}
