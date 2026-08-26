<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Rogga\Claudinho\Models\Configuracao;

/**
 * As regras que valem nas conversas do canal externo: prazos, o que aprova uma
 * alteração, as instruções do canal e de onde se aceita baixar mídia.
 *
 * Existe para haver UM lugar que responde "o que está valendo agora". Cada uma
 * delas pode vir do config ou da tela, e quem pergunta são quatro pontos
 * diferentes — o controller, o registro da conversa, o Recebedor e a própria tela
 * de configurações. Espalhada por `config()` em quatro arquivos, mudar de ideia
 * sobre a precedência significaria achar os quatro.
 *
 * Campo vazio em tela é o mesmo que não gravado: cai no config. É como a chave da
 * API e o contexto já funcionam, e é o que permite desfazer uma edição sem ter de
 * lembrar qual era o valor do arquivo.
 */
class Canal
{
    /** As ferramentas que ALTERAM dados valem neste canal? */
    public static function acoes(): bool
    {
        return Configuracao::booleano('api_acoes', (bool) config('claudinho.api.acoes', true));
    }

    /** Silêncio maior que isto começa conversa nova. */
    public static function minutosInatividade(): int
    {
        return self::minutos('api_minutos_inatividade', 'claudinho.api.minutos_inatividade', 30);
    }

    /** Prazo da confirmação pendente. */
    public static function minutosConfirmacao(): int
    {
        return self::minutos('api_minutos_confirmacao', 'claudinho.api.minutos_confirmacao', 5);
    }

    /**
     * As palavras que aprovam uma alteração, como foram escritas — quem compara é
     * o Confirmacao, que normaliza os dois lados.
     *
     * @return array<int, string>
     */
    public static function palavras(): array
    {
        $daTela = Configuracao::valor('api_palavras_confirmacao');

        if (filled($daTela)) {
            return self::lista((string) $daTela);
        }

        return array_values(array_filter(array_map(
            fn ($palavra): string => trim((string) $palavra),
            (array) config('claudinho.api.palavras_confirmacao', ['sim'])
        )));
    }

    /** Acrescentado ao fim do system prompt, só nas conversas deste canal. */
    public static function instrucoes(): string
    {
        $daTela = Configuracao::valor('api_instrucoes');

        return trim(filled($daTela) ? (string) $daTela : (string) config('claudinho.api.instrucoes', ''));
    }

    /**
     * De onde este servidor aceita baixar mídia. Lista vazia é o modo aberto: só
     * endereço público, decidido no Recebedor.
     *
     * @return array<int, string>
     */
    public static function hostsDeMidia(): array
    {
        $daTela = Configuracao::valor('api_midias_hosts');

        $hosts = filled($daTela)
            ? self::lista((string) $daTela)
            : (array) config('claudinho.api.midias.hosts', []);

        return array_values(array_unique(array_filter(array_map(
            fn ($host): string => self::host((string) $host),
            $hosts
        ))));
    }

    /**
     * Uma linha por item, mas vírgula e ponto e vírgula também separam: o campo é
     * um textarea, e quem cola de outro lugar cola do jeito que estava lá.
     *
     * @return array<int, string>
     */
    public static function lista(string $texto): array
    {
        $itens = preg_split('/[\r\n,;]+/u', $texto) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $itens))));
    }

    /**
     * Só o host, minúsculo. Aceita o que a pessoa tem em mãos — a URI assinada
     * inteira, com esquema, caminho e porta —, porque é isso que está no painel do
     * gateway ou no log; guardar `https://mmg.whatsapp.net/v/t62...` faria a
     * comparação do Recebedor falhar sem explicação, já que lá só o host é
     * comparado.
     */
    public static function host(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));

        if (str_contains($valor, '://')) {
            $valor = (string) parse_url($valor, PHP_URL_HOST);
        }

        // Caminho, porta e credencial, para o caso de terem vindo sem esquema —
        // sem ele o parse_url acima devolveria vazio.
        $valor = explode('/', $valor)[0];
        $valor = explode(':', $valor)[0];
        $valor = last(explode('@', $valor));

        return trim($valor, '. ');
    }

    /**
     * Volta da lista para o texto do campo. Usado ao salvar, para o que fica
     * gravado ser exatamente o que vai ser comparado depois.
     *
     * @param  array<int, string>  $itens
     */
    public static function texto(array $itens): string
    {
        return implode("\n", $itens);
    }

    private static function minutos(string $chave, string $config, int $padrao): int
    {
        $daTela = Configuracao::valor($chave);

        $minutos = filled($daTela) ? (int) $daTela : (int) config($config, $padrao);

        // O piso é o mesmo que o controller e o registro da conversa já aplicavam:
        // prazo zero ou negativo venceria a pendência antes de a resposta chegar ao
        // outro lado, e toda confirmação seria recusada por vencimento.
        return max(1, $minutos);
    }
}
