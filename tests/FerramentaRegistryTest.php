<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Rogga\Claudinho\FerramentaBase;
use Rogga\Claudinho\FerramentaRegistry;
use Rogga\Claudinho\Ferramentas\GerarGrafico;

function ferramentaFake(string $nome, ?string $permissao = null): FerramentaBase
{
    return new class($nome, $permissao) extends FerramentaBase
    {
        public function __construct(private string $id, ?string $permissao)
        {
            $this->permissao = $permissao;
        }

        public function nome(): string
        {
            return $this->id;
        }

        public function descricao(): string
        {
            return 'Ferramenta de teste';
        }

        public function propriedades(): array
        {
            return ['obra' => ['type' => 'string', 'description' => 'Obra']];
        }

        public function obrigatorios(): array
        {
            return ['obra'];
        }

        public function executar(array $input): array
        {
            return ['executou' => $this->id];
        }
    };
}

it('monta a definição no formato aceito pela api', function () {
    $registro = new FerramentaRegistry;
    $registro->registrar(ferramentaFake('consultar_obra'));

    $definicao = $registro->definicoes()[0];

    expect($definicao['name'])->toBe('consultar_obra')
        ->and($definicao['input_schema']['type'])->toBe('object')
        ->and($definicao['input_schema']['required'])->toBe(['obra'])
        // schema fechado: a API recusa campo inventado antes de chegar ao handler
        ->and($definicao['input_schema']['additionalProperties'])->toBeFalse();
});

it('omite required quando não há campo obrigatório', function () {
    $registro = new FerramentaRegistry;
    $registro->registrar(new GerarGrafico);

    $definicao = collect($registro->definicoes())->firstWhere('name', 'gerar_grafico');

    expect($definicao['input_schema']['required'])->toBe(['titulo', 'series']);
});

it('não expõe nem executa ferramenta sem permissão', function () {
    Gate::define('pode_consultar', fn () => false);
    Auth::setUser(new Illuminate\Foundation\Auth\User);

    $registro = new FerramentaRegistry;
    $registro->registrar(ferramentaFake('secreta', 'pode_consultar'));

    expect($registro->definicoes())->toBe([])
        ->and($registro->executar('secreta', [])['erro'])->toContain('sem permissão');
});

it('expõe e executa ferramenta permitida', function () {
    Gate::define('pode_consultar', fn () => true);
    Auth::setUser(new Illuminate\Foundation\Auth\User);

    $registro = new FerramentaRegistry;
    $registro->registrar(ferramentaFake('liberada', 'pode_consultar'));

    expect(collect($registro->definicoes())->pluck('name')->all())->toBe(['liberada'])
        ->and($registro->executar('liberada', ['obra' => 'x']))->toBe(['executou' => 'liberada']);
});

it('recusa ferramenta desconhecida em vez de lançar exceção', function () {
    $registro = new FerramentaRegistry;

    expect($registro->executar('rodar_sql', ['q' => 'select * from users'])['erro'])
        ->toContain('desconhecida');
});

it('registra o gráfico quando habilitado na config', function () {
    $nomes = collect(app(FerramentaRegistry::class)->definicoes())->pluck('name')->all();

    expect($nomes)->toContain('gerar_grafico');
});
