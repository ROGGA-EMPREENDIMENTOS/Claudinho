<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Ferramentas;

use Rogga\Claudinho\FerramentaBase;
use Rogga\Claudinho\Grafico\Especificacao;

/**
 * Ferramenta embutida: desenha barras na conversa.
 *
 * Não consulta dado nenhum — é primitiva de renderização, por isso dispensa
 * permissão. O modelo manda apenas dados tipados; o SVG é gerado no servidor
 * pelo View Component, então nunca há HTML do modelo na página.
 */
class GerarGrafico extends FerramentaBase
{
    public function nome(): string
    {
        return 'gerar_grafico';
    }

    public function descricao(): string
    {
        return 'Desenha um gráfico de barras na conversa a partir de dados que você JÁ obteve de outra ferramenta. '
            .'Nunca invente os valores: consulte primeiro. Use quando o usuário pedir visão gráfica, ou quando comparar '
            .'magnitudes entre categorias ou datas ficar mais claro visualmente. Depois de chamar, comente o gráfico em '
            .'uma ou duas frases em vez de repetir todos os números.';
    }

    public function propriedades(): array
    {
        $max = (int) config('claudinho.grafico.max_series', 12);

        return [
            'tipo' => [
                'type' => 'string',
                'enum' => Especificacao::TIPOS,
                'description' => 'barra_horizontal (padrão) para comparar categorias, especialmente com nomes longos; '
                    .'barra para séries no tempo, na ordem cronológica.',
            ],
            'titulo' => [
                'type' => 'string',
                'description' => 'Título curto dizendo o que está sendo medido, incluindo a unidade quando fizer sentido.',
            ],
            'series' => [
                'type' => 'array',
                'description' => "De 1 a {$max} itens, já ordenados como devem aparecer. Apenas valores positivos.",
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'rotulo' => ['type' => 'string', 'description' => 'Nome da categoria ou data.'],
                        'valor' => ['type' => 'number', 'description' => 'Valor numérico, zero ou positivo.'],
                    ],
                    'required' => ['rotulo', 'valor'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function obrigatorios(): array
    {
        return ['titulo', 'series'];
    }

    public function executar(array $input): array
    {
        ['spec' => $spec, 'erro' => $erro] = Especificacao::validar($input);

        if ($erro !== null) {
            return ['erro' => $erro];
        }

        return [
            'renderizado' => true,
            'tipo' => $spec['tipo'],
            'itens' => count($spec['series']),
            'observacao' => 'O gráfico já está visível para o usuário. Comente o que ele mostra em uma ou duas frases, sem repetir todos os valores.',
        ];
    }
}
