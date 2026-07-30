<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Illuminate\Support\Facades\Auth;
use Rogga\Claudinho\Contracts\Ferramenta;

/**
 * Base para ferramentas: cuida do boilerplate e deixa para a aplicação apenas
 * o que é decisão de negócio (nome, descrição, filtros, permissão, query).
 */
abstract class FerramentaBase implements Ferramenta
{
    /**
     * Gate exigido. null dispensa permissão — use só para ferramentas que não
     * consultam dado (o gráfico, por exemplo).
     */
    protected ?string $permissao = null;

    /** Máximo de linhas devolvidas por consulta. */
    protected int $limite = 25;

    public function obrigatorios(): array
    {
        return [];
    }

    public function permitida(): bool
    {
        if ($this->permissao === null) {
            return true;
        }

        return (bool) Auth::user()?->can($this->permissao);
    }

    /**
     * Definição no formato aceito pela API.
     *
     * @return array<string, mixed>
     */
    public function definicao(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $this->propriedades(),
            // Fechar o schema faz a API recusar campo inventado em vez de o
            // handler receber input que não sabe tratar.
            'additionalProperties' => false,
        ];

        if ($this->obrigatorios() !== []) {
            $schema['required'] = $this->obrigatorios();
        }

        return [
            'name' => $this->nome(),
            'description' => $this->descricao(),
            'input_schema' => $schema,
        ];
    }

    /**
     * Resposta paginada padronizada. Devolver o total junto da amostra é o que
     * permite ao assistente dizer "são 84, mostrando 25" em vez de contar as
     * linhas que recebeu e errar.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  callable(mixed): array<string, mixed>  $mapa
     * @return array<string, mixed>
     */
    protected function paginado($query, callable $mapa, string $chave = 'itens'): array
    {
        $total = (clone $query)->count();
        $itens = $query->limit($this->limite)->get();

        return [
            'total_encontrado' => $total,
            'mostrando' => $itens->count(),
            'truncado' => $total > $itens->count(),
            $chave => $itens->map($mapa)->all(),
        ];
    }
}
