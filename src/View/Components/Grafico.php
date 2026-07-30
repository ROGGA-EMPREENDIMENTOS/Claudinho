<?php

declare(strict_types=1);

namespace Rogga\Claudinho\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Desenha o gráfico do assistente em SVG no servidor.
 *
 * A especificação vem do modelo (bloco tool_use), então é sempre revalidada por
 * Grafico\Especificacao::validar() antes de virar geometria. Nada de HTML/SVG do modelo
 * chega à página: aqui só entram números e rótulos, e os rótulos passam pelo escape
 * do Blade.
 *
 * Especificação visual (uma série, magnitude):
 * - hue única #2a78d6, validada contra a superfície #f9fafb da bolha;
 * - barra de 18px (limite de 24), ponta arredondada em 4px e base reta;
 * - sem gridlines: cada barra é rotulada direto com o valor;
 * - texto em tons de cinza, nunca na cor da série.
 */
class Grafico extends Component
{
    /** Fallback caso a config não esteja publicada. */
    private const COR_PADRAO = '#2a78d6';

    private const ALTURA_BARRA = 18;

    private const ESPACO_BANDA = 10;

    private const RAIO = 4;

    private const LARGURA = 560;

    private const COL_ROTULO = 150;

    /** Largura mínima reservada ao rótulo do valor; cresce com o maior número da série. */
    private const COL_VALOR_MIN = 40;

    /** Largura aproximada de um dígito a 11px, usada para reservar a coluna do valor. */
    private const LARGURA_DIGITO = 6.4;

    /**
     * Corte do rótulo por tipo. A coluna de 150px comporta ~22 caracteres a 11px;
     * na vertical a banda é estreita, então o corte é mais agressivo. O rótulo
     * completo continua na descrição acessível do SVG.
     */
    private const CORTE_ROTULO = ['barra_horizontal' => 22, 'barra' => 10];

    /** @var array<int, array<string, mixed>> */
    public array $barras = [];

    public string $titulo;

    public string $tipo;

    public float $largura = self::LARGURA;

    public float $altura = 0;

    public string $cor = self::COR_PADRAO;

    public string $descricaoAcessivel = '';

    /**
     * @param  array{tipo: string, titulo: string, series: array<int, array{rotulo: string, valor: float}>}  $spec
     */
    public function __construct(array $spec)
    {
        $this->tipo = $spec['tipo'];
        $this->titulo = $spec['titulo'];
        $this->cor = (string) config('claudinho.grafico.cor', self::COR_PADRAO);

        $series = $spec['series'];
        $maximo = max(array_map(fn (array $item) => $item['valor'], $series));
        $escala = $maximo > 0 ? $maximo : 1;

        $this->barras = $this->tipo === 'barra'
            ? $this->colunas($series, $escala)
            : $this->barrasHorizontais($series, $escala);

        $this->descricaoAcessivel = $this->titulo.'. '.collect($series)
            ->map(fn (array $item) => $item['rotulo'].': '.$this->formata($item['valor']))
            ->implode('; ');
    }

    public function render(): View
    {
        return view('claudinho::components.grafico');
    }

    /**
     * @param  array<int, array{rotulo: string, valor: float}>  $series
     * @return array<int, array<string, mixed>>
     */
    private function barrasHorizontais(array $series, float $escala): array
    {
        // A coluna do valor é dimensionada pelo maior rótulo numérico da série, para
        // nenhum valor vazar da viewBox por mais alto que seja.
        $maiorRotulo = max(array_map(fn (array $item) => mb_strlen($this->formata($item['valor'])), $series));
        $colValor = max(self::COL_VALOR_MIN, $maiorRotulo * self::LARGURA_DIGITO + 12);

        $areaBarra = self::LARGURA - self::COL_ROTULO - $colValor;
        $this->altura = count($series) * (self::ALTURA_BARRA + self::ESPACO_BANDA);

        $barras = [];

        foreach ($series as $indice => $item) {
            $comprimento = $item['valor'] / $escala * $areaBarra;
            $y = $indice * (self::ALTURA_BARRA + self::ESPACO_BANDA);

            $barras[] = [
                'path' => $this->pathHorizontal(self::COL_ROTULO, $y, max($comprimento, 1)),
                'rotulo' => $this->corta($item['rotulo']),
                'rotulo_x' => self::COL_ROTULO - 8,
                'rotulo_y' => $y + self::ALTURA_BARRA / 2,
                'valor' => $this->formata($item['valor']),
                'valor_x' => self::COL_ROTULO + max($comprimento, 1) + 8,
                'valor_y' => $y + self::ALTURA_BARRA / 2,
            ];
        }

        return $barras;
    }

    /**
     * @param  array<int, array{rotulo: string, valor: float}>  $series
     * @return array<int, array<string, mixed>>
     */
    private function colunas(array $series, float $escala): array
    {
        $alturaArea = 150;
        $alturaRotulos = 34;
        $this->altura = $alturaArea + $alturaRotulos;

        $banda = self::LARGURA / count($series);
        $largura = min(self::ALTURA_BARRA + 6, $banda - self::ESPACO_BANDA);

        $barras = [];

        foreach ($series as $indice => $item) {
            $alturaBarra = max($item['valor'] / $escala * ($alturaArea - 20), 1);
            $x = $indice * $banda + ($banda - $largura) / 2;
            $topo = $alturaArea - $alturaBarra;

            $barras[] = [
                'path' => $this->pathVertical($x, $topo, $largura, $alturaBarra, $alturaArea),
                'rotulo' => $this->corta($item['rotulo']),
                'rotulo_x' => $x + $largura / 2,
                'rotulo_y' => $alturaArea + 16,
                'valor' => $this->formata($item['valor']),
                'valor_x' => $x + $largura / 2,
                'valor_y' => $topo - 6,
            ];
        }

        return $barras;
    }

    /**
     * Ponta arredondada só na extremidade do dado; a base fica reta.
     */
    private function pathHorizontal(float $x, float $y, float $comprimento): string
    {
        $h = self::ALTURA_BARRA;
        $r = min(self::RAIO, $comprimento);
        $fim = $x + $comprimento;

        return sprintf(
            'M %s %s H %s A %s %s 0 0 1 %s %s V %s A %s %s 0 0 1 %s %s H %s Z',
            $this->n($x), $this->n($y),
            $this->n($fim - $r),
            $this->n($r), $this->n($r), $this->n($fim), $this->n($y + $r),
            $this->n($y + $h - $r),
            $this->n($r), $this->n($r), $this->n($fim - $r), $this->n($y + $h),
            $this->n($x)
        );
    }

    private function pathVertical(float $x, float $topo, float $largura, float $altura, float $base): string
    {
        $r = min(self::RAIO, $largura / 2, $altura);

        return sprintf(
            'M %s %s V %s A %s %s 0 0 1 %s %s H %s A %s %s 0 0 1 %s %s V %s Z',
            $this->n($x), $this->n($base),
            $this->n($topo + $r),
            $this->n($r), $this->n($r), $this->n($x + $r), $this->n($topo),
            $this->n($x + $largura - $r),
            $this->n($r), $this->n($r), $this->n($x + $largura), $this->n($topo + $r),
            $this->n($base)
        );
    }

    /**
     * Corta o rótulo para não invadir a área das barras. Determinístico de propósito:
     * o layout não pode depender do tamanho do nome que vier do banco.
     */
    private function corta(string $rotulo): string
    {
        $limite = self::CORTE_ROTULO[$this->tipo] ?? 22;

        return mb_strlen($rotulo) > $limite
            ? mb_substr($rotulo, 0, $limite - 1).'…'
            : $rotulo;
    }

    private function n(float $valor): string
    {
        return (string) round($valor, 2);
    }

    private function formata(float $valor): string
    {
        return $valor === floor($valor)
            ? number_format($valor, 0, ',', '.')
            : number_format($valor, 2, ',', '.');
    }
}
