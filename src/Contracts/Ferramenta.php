<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Contracts;

/**
 * Uma consulta que o assistente pode executar.
 *
 * Invariantes que toda implementação deve respeitar:
 * - somente leitura;
 * - permitida() é revalidada antes de cada execução, nunca só na exposição;
 * - a query parte do Eloquent, para herdar Global Scopes;
 * - nunca aceitar SQL, nome de tabela ou de coluna vindo do modelo — só filtros tipados.
 */
interface Ferramenta
{
    /**
     * Identificador enviado à API. snake_case, estável: ele aparece no histórico
     * da conversa e renomear invalida conversas em andamento.
     */
    public function nome(): string;

    /**
     * Quando usar esta ferramenta, em linguagem natural. É o que faz o modelo
     * escolher certo — descrição vaga é a causa nº 1 de ferramenta ignorada.
     * Explicite aqui as ambiguidades do seu domínio.
     */
    public function descricao(): string;

    /**
     * Propriedades aceitas, no formato JSON Schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public function propriedades(): array;

    /**
     * Nomes das propriedades obrigatórias.
     *
     * @return array<int, string>
     */
    public function obrigatorios(): array;

    /**
     * O usuário autenticado pode usar esta ferramenta?
     */
    public function permitida(): bool;

    /**
     * Executa e devolve dados prontos para virar tool_result. Em caso de erro
     * previsível, devolva ['erro' => 'mensagem em pt-BR'] em vez de lançar exceção:
     * o modelo sabe explicar o erro ao usuário.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executar(array $input): array;
}
