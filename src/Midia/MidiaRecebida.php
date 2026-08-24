<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Midia;

/**
 * Uma foto ou um vídeo que chegou pelo canal externo, já baixado e conferido.
 *
 * Os bytes vêm junto, e não um caminho: quem decide onde o arquivo mora é a
 * aplicação (ver DestinoDeMidia). O pacote guardar primeiro para a aplicação
 * copiar depois seria escrever duas vezes o mesmo arquivo, e ainda obrigaria o
 * pacote a ter opinião sobre disco, pasta e prazo de limpeza.
 */
final readonly class MidiaRecebida
{
    /**
     * @param  string  $tipo  MIME dos BYTES, não o que o gateway declarou
     * @param  string  $conteudo  O arquivo
     * @param  string  $nome  Nome sugerido, com a extensão do tipo real
     * @param  string|null  $descricao  O que o modelo enxergou. null em vídeo, em
     *                                  formato que a API não lê e quando a visão falhou
     * @param  string|null  $legenda  O texto que veio escrito junto do anexo
     */
    public function __construct(
        public string $tipo,
        public string $conteudo,
        public string $nome,
        public ?string $descricao = null,
        public ?string $legenda = null,
    ) {}

    public function ehVideo(): bool
    {
        return str_starts_with($this->tipo, 'video/');
    }

    public function bytes(): int
    {
        // O '8bit' não é enfeite: mb_strlen sem encoding conta caracteres UTF-8,
        // e sobre um JPEG devolve um número que não é o tamanho de nada.
        return mb_strlen($this->conteudo, '8bit');
    }
}
