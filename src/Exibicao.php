<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Illuminate\Support\Str;
use Rogga\Claudinho\Grafico\Especificacao;

/**
 * A conversa da API traduzida para o que a tela mostra.
 *
 * Existe porque há dois lugares que desenham a MESMA conversa — o chat, a partir do
 * estado que o Livewire carrega, e o histórico, a partir do estado gravado no banco
 * pelo canal externo. O que separa "consultou" de "alterou", e "executou" de "o
 * usuário recusou", é justamente a parte que não pode divergir entre os dois: num
 * registro de alteração, o rótulo errado é o que faz alguém concluir que nada mudou.
 *
 * Tudo estático e sem estado de propósito: o Livewire serializa o componente a cada
 * requisição, e isto aqui é transformação pura de array.
 */
class Exibicao
{
    /**
     * Achata a conversa para exibição: texto, gráficos e o rótulo das consultas e
     * alterações. Blocos tool_result (JSON cru) não vão para a tela.
     *
     * @param  array<int, array<string, mixed>>  $mensagens
     * @return array<int, array<string, mixed>>
     */
    public static function mensagens(array $mensagens): array
    {
        $registro = app(FerramentaRegistry::class);
        $situacoes = self::situacoes($mensagens);
        $visiveis = [];

        foreach ($mensagens as $mensagem) {
            $blocos = is_array($mensagem['content'])
                ? $mensagem['content']
                : [['type' => 'text', 'text' => $mensagem['content']]];

            foreach ($blocos as $bloco) {
                $tipo = $bloco['type'] ?? null;

                if ($tipo === 'text' && filled(trim((string) ($bloco['text'] ?? '')))) {
                    $texto = trim((string) $bloco['text']);

                    $visiveis[] = [
                        'autor' => $mensagem['role'],
                        'tipo' => 'texto',
                        'texto' => $texto,
                        // Só a resposta do modelo vira markdown; o que o usuário digitou fica escapado.
                        'html' => $mensagem['role'] === 'assistant' ? self::markdown($texto) : null,
                    ];
                }

                if ($tipo === 'tool_use' && ($bloco['name'] ?? '') === 'gerar_grafico') {
                    $spec = Especificacao::validar((array) ($bloco['input'] ?? []))['spec'];

                    if ($spec !== null) {
                        $visiveis[] = [
                            'autor' => 'sistema',
                            'tipo' => 'grafico',
                            'texto' => $spec['titulo'],
                            'spec' => $spec,
                        ];
                    }

                    continue;
                }

                if ($tipo === 'tool_use') {
                    $nome = (string) ($bloco['name'] ?? '');
                    $acao = $registro->ehAcao($nome);
                    $situacao = $situacoes[(string) ($bloco['id'] ?? '')] ?? 'pendente';

                    $visiveis[] = [
                        'autor' => 'sistema',
                        'tipo' => $acao ? 'acao' : 'consulta',
                        'situacao' => $situacao,
                        'texto' => self::rotulo($nome, (array) ($bloco['input'] ?? []), $acao, $situacao),
                    ];
                }
            }
        }

        return $visiveis;
    }

    /**
     * A última coisa dita na conversa, em texto puro, para a linha da lista do
     * histórico.
     *
     * De trás para a frente porque a lista é ordenada por atividade: quem procura
     * uma conversa ali dentro se lembra do fim dela, não do começo. Rótulo de
     * ferramenta não conta — "Consultou buscar_pedido (pedido: 12)" não identifica
     * conversa nenhuma para quem está procurando.
     *
     * @param  array<int, array<string, mixed>>  $mensagens
     */
    public static function ultimaFala(array $mensagens, int $limite = 120): string
    {
        foreach (array_reverse($mensagens) as $mensagem) {
            $blocos = is_array($mensagem['content'])
                ? $mensagem['content']
                : [['type' => 'text', 'text' => $mensagem['content']]];

            foreach (array_reverse($blocos) as $bloco) {
                if (($bloco['type'] ?? null) !== 'text') {
                    continue;
                }

                $texto = trim((string) ($bloco['text'] ?? ''));

                if (filled($texto)) {
                    return Str::limit(preg_replace('/\s+/u', ' ', $texto) ?? $texto, $limite);
                }
            }
        }

        return '';
    }

    /**
     * Quantas falas a conversa tem, contando só o que aparece na tela como bolha.
     *
     * Conta o que uma pessoa chamaria de mensagem: a pergunta e a resposta. A volta
     * de tool_result é uma mensagem para a API e não é nada para quem leu a
     * conversa, e somá-la faria uma troca de duas linhas aparecer como seis.
     *
     * Conta os blocos direto, em vez de passar pelo mensagens(): aquele roda o
     * markdown em cada texto, e uma lista de trinta conversas pagaria trinta
     * renderizações completas para mostrar trinta números.
     *
     * @param  array<int, array<string, mixed>>  $mensagens
     */
    public static function falas(array $mensagens): int
    {
        $falas = 0;

        foreach ($mensagens as $mensagem) {
            $blocos = is_array($mensagem['content'])
                ? $mensagem['content']
                : [['type' => 'text', 'text' => $mensagem['content']]];

            foreach ($blocos as $bloco) {
                if (($bloco['type'] ?? null) === 'text' && filled(trim((string) ($bloco['text'] ?? '')))) {
                    $falas++;
                }
            }
        }

        return $falas;
    }

    /**
     * html_input strip é obrigatório: a resposta do modelo carrega dados vindos do
     * banco e não pode virar HTML executável.
     */
    public static function markdown(string $texto): string
    {
        return Str::markdown($texto, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Situação de cada tool_use, lida do tool_result que veio depois. É o que
     * separa "executou" de "o usuário recusou" na conversa: sem isso os dois
     * apareceriam com o mesmo rótulo, o que num histórico de alteração é grave.
     *
     * @param  array<int, array<string, mixed>>  $mensagens
     * @return array<string, string>
     */
    private static function situacoes(array $mensagens): array
    {
        $situacoes = [];

        foreach ($mensagens as $mensagem) {
            if (! is_array($mensagem['content'])) {
                continue;
            }

            foreach ($mensagem['content'] as $bloco) {
                if (($bloco['type'] ?? null) !== 'tool_result') {
                    continue;
                }

                $conteudo = json_decode((string) ($bloco['content'] ?? ''), true);
                $conteudo = is_array($conteudo) ? $conteudo : [];

                $situacoes[(string) ($bloco['tool_use_id'] ?? '')] = match (true) {
                    ($conteudo['recusada'] ?? false) === true => 'recusada',
                    isset($conteudo['erro']) => 'erro',
                    default => 'concluida',
                };
            }
        }

        return $situacoes;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function rotulo(string $nome, array $input, bool $acao, string $situacao): string
    {
        $argumentos = [];

        foreach ($input as $chave => $valor) {
            if (blank($valor)) {
                continue;
            }

            $argumentos[] = $chave.': '.(is_scalar($valor) ? $valor : json_encode($valor, JSON_UNESCAPED_UNICODE));
        }

        $alvo = $nome.($argumentos === [] ? '' : ' ('.implode(', ', $argumentos).')');

        if (! $acao) {
            return 'Consultou '.$alvo;
        }

        return match ($situacao) {
            'recusada' => 'Alteração não autorizada pelo usuário: '.$alvo,
            'erro' => 'Alteração falhou: '.$alvo,
            'pendente' => 'Aguardando confirmação: '.$alvo,
            default => 'Alterou dados: '.$alvo,
        };
    }
}
