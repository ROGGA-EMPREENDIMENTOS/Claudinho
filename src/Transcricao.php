<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Rogga\Claudinho\Models\Configuracao;

/**
 * A configuração da transcrição de áudio pela API do Google Speech-to-Text: se
 * está ligada, com que chave e em que idioma.
 *
 * Existe pelo mesmo motivo do {@see Canal}: cada valor pode vir do config ou da
 * tela, e a precedência entre os dois precisa ser respondida em UM lugar. Aqui o
 * motivo é ainda mais forte, porque um dos valores é segredo — a chave nunca deve
 * ser lida direto de `config()` por quem for chamar a API, senão a versão gravada
 * em tela é ignorada sem ninguém perceber.
 *
 * Campo vazio em tela é o mesmo que não gravado: cai no config. É como a chave do
 * Claude já funciona, e é o que permite voltar ao `.env` sem lembrar o valor dele.
 */
class Transcricao
{
    /**
     * O interruptor. É a intenção de quem opera, e não a promessa de que vai
     * funcionar — para isso existe o ativa() abaixo.
     */
    public static function habilitada(): bool
    {
        return Configuracao::booleano(
            'transcricao_habilitada',
            (bool) config('claudinho.transcricao.habilitado', false)
        );
    }

    /**
     * Transcreve de verdade?
     *
     * Ligado sem chave não é "meio ligado": é desligado com aparência de ligado.
     * Separar os dois é o que permite à tela avisar em vez de deixar o áudio
     * falhar em silêncio, um a um, em produção.
     */
    public static function ativa(): bool
    {
        return self::habilitada() && self::chave() !== null;
    }

    /**
     * A chave que vale agora: a gravada em tela, ou a do config como padrão.
     *
     * null e não string vazia porque a diferença importa em quem chama — chave
     * vazia enviada ao Google vira um 400 genérico, e o motivo real ("não tem
     * chave nenhuma") se perderia no log.
     */
    public static function chave(): ?string
    {
        $daTela = Configuracao::valor('transcricao_chave');

        if (filled($daTela)) {
            return trim((string) $daTela);
        }

        $doConfig = trim((string) config('claudinho.transcricao.chave', ''));

        return $doConfig === '' ? null : $doConfig;
    }

    /**
     * Idioma esperado do áudio, em BCP-47. Só do arquivo: não é decisão de
     * operação, e por isso não tem campo na tela.
     */
    public static function idioma(): string
    {
        $idioma = trim((string) config('claudinho.transcricao.idioma', 'pt-BR'));

        return $idioma === '' ? 'pt-BR' : $idioma;
    }

    public static function timeout(): int
    {
        return max(1, (int) config('claudinho.transcricao.timeout', 30));
    }
}
