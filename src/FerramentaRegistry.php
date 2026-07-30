<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Rogga\Claudinho\Contracts\Ferramenta;

/**
 * Registro das ferramentas disponíveis. É o único lugar que decide o que o
 * modelo fica sabendo que existe.
 */
class FerramentaRegistry
{
    /** @var array<string, Ferramenta> */
    private array $ferramentas = [];

    public function registrar(Ferramenta $ferramenta): void
    {
        $this->ferramentas[$ferramenta->nome()] = $ferramenta;
    }

    /**
     * @return array<string, Ferramenta>
     */
    public function todas(): array
    {
        return $this->ferramentas;
    }

    public function obter(string $nome): ?Ferramenta
    {
        return $this->ferramentas[$nome] ?? null;
    }

    /**
     * Definições enviadas à API — somente as permitidas ao usuário atual.
     *
     * Filtrar aqui não é cosmético: o que não é exposto não pode ser pedido, e
     * essa é a primeira das duas barreiras de permissão (a segunda é executar()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function definicoes(): array
    {
        $definicoes = [];

        foreach ($this->ferramentas as $ferramenta) {
            if (! $ferramenta->permitida()) {
                continue;
            }

            $definicoes[] = $ferramenta instanceof FerramentaBase
                ? $ferramenta->definicao()
                : $this->definicaoDe($ferramenta);
        }

        return $definicoes;
    }

    /**
     * Executa revalidando a permissão. Nome desconhecido e permissão ausente
     * viram erro legível, não exceção: o modelo explica ao usuário.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executar(string $nome, array $input): array
    {
        $ferramenta = $this->obter($nome);

        if (! $ferramenta) {
            return ['erro' => "Ferramenta desconhecida: {$nome}."];
        }

        if (! $ferramenta->permitida()) {
            return ['erro' => 'Usuário sem permissão para esta consulta.'];
        }

        return $ferramenta->executar($input);
    }

    /**
     * @return array<string, mixed>
     */
    private function definicaoDe(Ferramenta $ferramenta): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $ferramenta->propriedades(),
            'additionalProperties' => false,
        ];

        if ($ferramenta->obrigatorios() !== []) {
            $schema['required'] = $ferramenta->obrigatorios();
        }

        return [
            'name' => $ferramenta->nome(),
            'description' => $ferramenta->descricao(),
            'input_schema' => $schema,
        ];
    }
}
