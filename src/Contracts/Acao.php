<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Contracts;

/**
 * Uma ferramenta que altera dados.
 *
 * É interface separada, e não um método na Ferramenta, por dois motivos: não
 * quebra quem já implementa Ferramenta, e o chat decide pausar o loop por
 * `instanceof Acao` — não existe ação que escape da confirmação por ter
 * esquecido de sobrescrever um método.
 *
 * Invariantes que toda implementação deve respeitar:
 * - o gate nunca é dispensado: ação sem permissão declarada não executa;
 * - executar() roda no máximo uma vez por tool_use, depois da aprovação do
 *   usuário — nunca dentro de uma consulta especulativa do modelo;
 * - confirmacao() descreve o efeito por inteiro: é o único texto que separa o
 *   usuário de uma alteração irreversível;
 * - erro previsível volta como ['erro' => '...'], como em qualquer Ferramenta.
 *   Se o efeito puder ficar aplicado pela metade, diga isso na mensagem.
 */
interface Acao extends Ferramenta
{
    /**
     * O que o usuário lê antes de aprovar, montado a partir do input do modelo.
     * Nomeie o efeito e os registros afetados: "Cancelar o pedido 4821 do
     * cliente Acme" é confirmável; "Executar cancelar_pedido" não é.
     *
     * @param  array<string, mixed>  $input
     */
    public function confirmacao(array $input): string;

    /**
     * Pausar o loop e pedir aprovação? Devolva false somente para alteração de
     * efeito pequeno e reversível — o rótulo na conversa continua registrando
     * que houve alteração, e a descrição enviada ao modelo continua avisando.
     */
    public function exigeConfirmacao(): bool;
}
