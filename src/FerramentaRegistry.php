<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Rogga\Claudinho\Contracts\Acao;
use Rogga\Claudinho\Contracts\Ferramenta;

/**
 * Registro das ferramentas disponíveis. É o único lugar que decide o que o
 * modelo fica sabendo que existe, e em que termos.
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

            $definicao = $ferramenta instanceof FerramentaBase
                ? $ferramenta->definicao()
                : $this->definicaoDe($ferramenta);

            $definicoes[] = $ferramenta instanceof Acao
                ? $this->comAvisoDeAlteracao($definicao, $ferramenta)
                : $definicao;
        }

        return $definicoes;
    }

    /**
     * Alguma ação está exposta ao usuário atual? É o que decide se o system
     * prompt fala de alteração: num chat só de consulta, mencionar ações é
     * convite para o modelo prometer o que não pode fazer.
     */
    public function temAcoes(): bool
    {
        foreach ($this->ferramentas as $ferramenta) {
            if ($ferramenta instanceof Acao && $ferramenta->permitida()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A ferramenta com este nome altera dados? O chat usa para o rótulo na
     * conversa — consulta e alteração não podem aparecer com o mesmo verbo.
     */
    public function ehAcao(string $nome): bool
    {
        return $this->obter($nome) instanceof Acao;
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
     * Aviso acrescentado à descrição de toda ação. Fica aqui, e não em cada
     * classe, porque assim vale também para quem implementa Acao direto, sem o
     * AcaoBase — a garantia é "nenhuma ação chega ao modelo sem estar marcada".
     *
     * @param  array<string, mixed>  $definicao
     * @return array<string, mixed>
     */
    private function comAvisoDeAlteracao(array $definicao, Acao $acao): array
    {
        $definicao['description'] = trim((string) $definicao['description'])
            .' ATENÇÃO: esta ferramenta ALTERA DADOS.'
            .($acao->exigeConfirmacao()
                ? ' A interface pede confirmação ao usuário antes de executar, então chame-a'
                    .' normalmente em vez de pedir permissão por texto.'
                : ' O efeito é imediato, sem confirmação do usuário.');

        return $definicao;
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
