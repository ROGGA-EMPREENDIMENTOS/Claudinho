<?php

use Rogga\Claudinho\Grafico\Especificacao;
use Rogga\Claudinho\View\Components\Grafico;

function spec(array $input): array
{
    return Especificacao::validar($input)['spec'];
}

function grafico(array $input): Grafico
{
    return new Grafico(spec($input));
}

function serie(int $quantidade, float $valor = 10): array
{
    return array_map(fn (int $i) => ['rotulo' => "OBRA {$i}", 'valor' => $valor], range(1, $quantidade));
}

it('gera uma barra por item, com ponta arredondada e base reta', function () {
    $g = grafico(['titulo' => 'Doctos por obra', 'series' => [
        ['rotulo' => 'AZALEIA', 'valor' => 10],
        ['rotulo' => 'GRANT', 'valor' => 5],
    ]]);

    expect($g->barras)->toHaveCount(2)
        // começa na coluna do rótulo (base reta) e arredonda com raio 4 na ponta
        ->and($g->barras[0]['path'])->toStartWith('M 150 0 H ')
        ->and($g->barras[0]['path'])->toContain('A 4 4 0 0 1');
});

it('escala as barras pelo maior valor da série', function () {
    $g = grafico(['titulo' => 'x', 'series' => [
        ['rotulo' => 'A', 'valor' => 100],
        ['rotulo' => 'B', 'valor' => 50],
    ]]);

    // valor_x fica na ponta exata da barra (sem o desconto do raio no path)
    $comprimentoMaior = $g->barras[0]['valor_x'] - 8 - 150;
    $comprimentoMetade = $g->barras[1]['valor_x'] - 8 - 150;

    expect(round($comprimentoMetade / $comprimentoMaior, 2))->toBe(0.5);
});

it('não estoura a largura mesmo com valor de sete dígitos', function () {
    $g = grafico(['titulo' => 'x', 'series' => [['rotulo' => 'A', 'valor' => 1234567.89]]]);

    $fim = $g->barras[0]['valor_x'] + mb_strlen($g->barras[0]['valor']) * 6.4;

    expect($fim)->toBeLessThanOrEqual($g->largura)
        ->and($g->barras[0]['valor'])->toBe('1.234.567,89');
});

it('corta rótulo longo para não invadir a área das barras', function () {
    $g = grafico(['titulo' => 'x', 'series' => [
        ['rotulo' => 'CONSTRUTORA COM NOME MUITO LONGO', 'valor' => 1],
    ]]);

    expect(mb_strlen($g->barras[0]['rotulo']))->toBeLessThanOrEqual(22)
        ->and($g->barras[0]['rotulo'])->toEndWith('…')
        // o rótulo completo continua acessível
        ->and($g->descricaoAcessivel)->toContain('CONSTRUTORA COM NOME MUITO LONGO');
});

it('sobrevive a série inteira com valor zero', function () {
    $g = grafico(['titulo' => 'x', 'series' => [
        ['rotulo' => 'A', 'valor' => 0],
        ['rotulo' => 'B', 'valor' => 0],
    ]]);

    expect($g->barras)->toHaveCount(2)
        ->and($g->barras[0]['valor'])->toBe('0')
        ->and($g->barras[0]['path'])->not->toContain('NAN');
});

it('formata valores em pt-BR', function () {
    $g = grafico(['titulo' => 'x', 'series' => [
        ['rotulo' => 'A', 'valor' => 8598],
        ['rotulo' => 'B', 'valor' => 537.57],
    ]]);

    expect($g->barras[0]['valor'])->toBe('8.598')
        ->and($g->barras[1]['valor'])->toBe('537,57');
});

it('cresce em altura conforme a quantidade de itens na horizontal', function () {
    expect(grafico(['titulo' => 'x', 'series' => serie(3)])->altura)->toBe(84.0)
        ->and(grafico(['titulo' => 'x', 'series' => serie(12)])->altura)->toBe(336.0);
});

it('mantém altura fixa na vertical, independente da quantidade', function () {
    $tres = grafico(['tipo' => 'barra', 'titulo' => 'x', 'series' => serie(3)]);
    $doze = grafico(['tipo' => 'barra', 'titulo' => 'x', 'series' => serie(12)]);

    expect($tres->altura)->toBe($doze->altura)
        ->and($doze->barras)->toHaveCount(12);
});

it('descreve a série inteira no texto acessível', function () {
    $g = grafico(['titulo' => 'Doctos por obra', 'series' => [
        ['rotulo' => 'AZALEIA', 'valor' => 37],
        ['rotulo' => 'GRANT', 'valor' => 24],
    ]]);

    expect($g->descricaoAcessivel)->toBe('Doctos por obra. AZALEIA: 37; GRANT: 24');
});
