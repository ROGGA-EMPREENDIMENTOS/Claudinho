<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

/**
 * Decide se uma mensagem de texto livre autoriza uma alteração.
 *
 * Classe separada porque é o ponto mais arriscado do endpoint: aqui, um acerto
 * frouxo vira escrita não autorizada. Isolada, ela é testável sem banco, sem HTTP
 * e sem a API — e é o único lugar a olhar quando a pergunta for "o que exatamente
 * aprova?".
 *
 * A regra é casamento EXATO sobre o texto normalizado. Casar por conteúdo seria
 * pior que inútil: "não, não confirmo" contém "confirmo" e autorizaria o oposto do
 * que a pessoa escreveu. A resposta do endpoint diz literalmente o que digitar,
 * então exigir a palavra sozinha é justo.
 */
class Confirmacao
{
    /**
     * @param  array<int, string>|null  $palavras  Padrão: as do config.
     */
    public static function aprovada(string $mensagem, ?array $palavras = null): bool
    {
        $palavras = $palavras ?? (array) config('claudinho.api.palavras_confirmacao', ['sim']);

        $aceitas = array_filter(array_map(
            fn ($palavra): string => self::normalizar((string) $palavra),
            $palavras
        ));

        $normalizada = self::normalizar($mensagem);

        // Mensagem vazia não aprova, e a lista vazia também não: sem isso, config
        // mal preenchido transformaria qualquer mensagem em autorização.
        return $normalizada !== '' && in_array($normalizada, $aceitas, true);
    }

    /**
     * Minúsculas, sem acento e sem pontuação — para "Sim!" e "SIM." contarem como
     * "sim", que é o que a pessoa quis dizer, sem abrir a porta para frases.
     *
     * A tabela de acentos é explícita em vez de iconv/intl: as vogais do pt-BR
     * bastam aqui, e o pacote não deve exigir extensão para isso.
     */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        // Colapsa espaço repetido depois de tirar a pontuação, senão "sim ." viraria
        // "sim " e deixaria de casar.
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9\s]/', '', $texto)));
    }
}
