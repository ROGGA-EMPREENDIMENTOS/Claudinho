<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Grafico;

/**
 * Valida e normaliza a especificação do gráfico vinda do modelo.
 *
 * Precisa ser chamada TAMBÉM na hora de renderizar: o estado do Livewire rehidrata
 * o input do tool_use a partir de JSON, então o que chega na view é dado do modelo
 * e nunca deve virar geometria sem passar por aqui.
 */
class Especificacao
{
    public const TIPOS = ['barra_horizontal', 'barra'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{spec: array{tipo: string, titulo: string, series: array<int, array{rotulo: string, valor: float}>}|null, erro: string|null}
     */
    public static function validar(array $input): array
    {
        $maxSeries = (int) config('claudinho.grafico.max_series', 12);

        $erro = fn (string $mensagem): array => ['spec' => null, 'erro' => $mensagem];

        $tipo = (string) ($input['tipo'] ?? 'barra_horizontal');

        if (! in_array($tipo, self::TIPOS, true)) {
            return $erro('Tipo de gráfico inválido. Use: '.implode(' ou ', self::TIPOS).'.');
        }

        $titulo = trim((string) ($input['titulo'] ?? ''));

        if ($titulo === '') {
            return $erro('Informe um título para o gráfico.');
        }

        $series = $input['series'] ?? null;

        if (! is_array($series) || ! array_is_list($series) || $series === []) {
            return $erro('Informe series como uma lista de itens com rotulo e valor.');
        }

        if (count($series) > $maxSeries) {
            return $erro("O gráfico aceita no máximo {$maxSeries} itens; agrupe o resto ou filtre mais.");
        }

        $normalizadas = [];

        foreach ($series as $item) {
            if (! is_array($item) || ! array_key_exists('valor', $item)) {
                return $erro('Cada item de series precisa ter rotulo e valor.');
            }

            if (! is_numeric($item['valor']) || ! is_finite((float) $item['valor'])) {
                return $erro('Os valores do gráfico precisam ser numéricos.');
            }

            $valor = (float) $item['valor'];

            if ($valor < 0) {
                return $erro('O gráfico de barras parte de zero e não representa valores negativos.');
            }

            $rotulo = trim((string) ($item['rotulo'] ?? ''));

            $normalizadas[] = [
                'rotulo' => $rotulo === '' ? '—' : mb_substr($rotulo, 0, 40),
                'valor' => $valor,
            ];
        }

        return [
            'spec' => [
                'tipo' => $tipo,
                'titulo' => mb_substr($titulo, 0, 120),
                'series' => $normalizadas,
            ],
            'erro' => null,
        ];
    }
}
