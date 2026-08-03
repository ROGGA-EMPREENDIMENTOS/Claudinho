<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Rogga\Claudinho\Contracts\Acao;

/**
 * Base para ações: herda da FerramentaBase o schema e o gate, e endurece o que
 * muda quando a ferramenta escreve em vez de ler.
 *
 * Continua faltando implementar nome(), descricao(), propriedades(), executar()
 * e confirmacao().
 */
abstract class AcaoBase extends FerramentaBase implements Acao
{
    /**
     * Deixe true a menos que o efeito seja pequeno e reversível. Ligar e
     * desligar isto é a única decisão de risco que o pacote delega inteira à
     * aplicação.
     */
    protected bool $confirmar = true;

    public function exigeConfirmacao(): bool
    {
        return $this->confirmar;
    }

    /**
     * O default permissivo do FerramentaBase (permissao null libera) existe para
     * o gráfico, que não toca em dado. Numa ação, esquecer de declarar o gate
     * seria escrita liberada para qualquer usuário autenticado — então aqui a
     * ausência de permissão nega em vez de liberar.
     */
    public function permitida(): bool
    {
        return $this->permissao !== null && parent::permitida();
    }
}
