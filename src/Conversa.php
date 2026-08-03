<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Closure;
use Illuminate\Support\Facades\Auth;
use Rogga\Claudinho\Contracts\Acao;
use Throwable;

/**
 * O loop de tool use, sem UI nenhuma.
 *
 * Existe porque há dois consumidores com necessidades diferentes — o componente
 * Livewire, que faz streaming e pausa mostrando um card, e o endpoint HTTP, que
 * devolve texto — e o loop é a parte mais delicada do pacote: quem grava
 * tool_result para cada tool_use, quem conta as iterações, quem decide o que vai
 * para a fila de confirmação. Duplicar isso seria garantir que as duas cópias
 * divergissem justamente onde um erro corrompe a conversa.
 *
 * O estado é array puro de propósito: o Livewire serializa em toda requisição e o
 * endpoint grava no banco. Nada aqui guarda referência a objeto.
 */
class Conversa
{
    /**
     * Conversa no formato da API (blocos de conteúdo, incluindo tool_use/tool_result).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $mensagens = [];

    /**
     * Ações propostas pelo modelo aguardando decisão de quem está conversando.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $pendentes = [];

    /**
     * tool_results prontos da rodada em pausa. Só vão para a conversa quando a
     * última pendência for decidida: a API exige que todo tool_use da mensagem do
     * assistente seja respondido de uma vez, na mensagem seguinte.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $resultados = [];

    /** Volta do loop em que a pausa aconteceu, para max_iteracoes seguir valendo. */
    private int $iteracao = 0;

    /**
     * Ferramentas de escrita entram nesta conversa? Desligar é a válvula do
     * endpoint HTTP: canal externo pode operar somente-leitura sem a aplicação
     * precisar desregistrar as ações, que continuam valendo na tela.
     */
    private bool $comAcoes = true;

    /**
     * Instruções extra do canal, acrescentadas ao fim do system prompt. Existe
     * porque as regras do pacote presumem tela: tabela markdown, que é o que o chat
     * renderiza bem, chega ilegível no WhatsApp.
     */
    private string $instrucoes = '';

    /**
     * @param  (Closure(string): void)|null  $aoStreamar  Recebe cada pedaço de texto do
     *                                                    modelo, mais os rótulos de
     *                                                    "consultando X". Quem não passa
     *                                                    nada simplesmente não vê nada:
     *                                                    o texto vai para as mensagens de
     *                                                    todo jeito.
     */
    public function __construct(private ?Closure $aoStreamar = null) {}

    public function semAcoes(): self
    {
        $this->comAcoes = false;

        return $this;
    }

    public function comInstrucoes(string $instrucoes): self
    {
        $this->instrucoes = trim($instrucoes);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    public static function de(array $estado, ?Closure $aoStreamar = null): self
    {
        $conversa = new self($aoStreamar);

        $conversa->mensagens = (array) ($estado['mensagens'] ?? []);
        $conversa->pendentes = (array) ($estado['pendentes'] ?? []);
        $conversa->resultados = (array) ($estado['resultados'] ?? []);
        $conversa->iteracao = (int) ($estado['iteracao'] ?? 0);

        return $conversa;
    }

    /**
     * @return array{mensagens: array<int, array<string, mixed>>, pendentes: array<int, array<string, mixed>>, resultados: array<int, array<string, mixed>>, iteracao: int}
     */
    public function estado(): array
    {
        return [
            'mensagens' => $this->mensagens,
            'pendentes' => $this->pendentes,
            'resultados' => $this->resultados,
            'iteracao' => $this->iteracao,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mensagens(): array
    {
        return $this->mensagens;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendentes(): array
    {
        return $this->pendentes;
    }

    public function pausada(): bool
    {
        return $this->pendentes !== [];
    }

    public function vazia(): bool
    {
        return $this->mensagens === [];
    }

    public function limpar(): void
    {
        $this->mensagens = [];
        $this->pendentes = [];
        $this->resultados = [];
        $this->iteracao = 0;
    }

    /**
     * Entra a pergunta de quem está conversando. Zera o contador de iterações
     * porque max_iteracoes é por pergunta, não por conversa.
     */
    public function perguntar(string $texto): void
    {
        $this->mensagens[] = ['role' => 'user', 'content' => trim($texto)];
        $this->iteracao = 0;
    }

    /**
     * Roda o loop até o modelo parar de pedir ferramenta, estourar max_iteracoes ou
     * pausar esperando confirmação de uma ação.
     *
     * Em caso de falha, fecha os tool_use abertos ANTES de propagar: a API rejeita
     * tool_use sem o tool_result correspondente, e uma conversa nesse estado fica
     * inutilizável para sempre. Quem chama decide como avisar do erro.
     *
     * @throws Throwable
     */
    public function responder(): void
    {
        $claude = new Claude;
        $registro = app(FerramentaRegistry::class);
        $definicoes = $this->definicoes($registro);
        $maxIteracoes = (int) config('claudinho.max_iteracoes', 5);

        try {
            do {
                $texto = '';
                $toolUses = [];

                foreach ($claude->stream($this->historico(), $this->systemPrompt(), $definicoes) as $evento) {
                    if ($evento['tipo'] === 'texto') {
                        $texto .= $evento['conteudo'];
                        $this->streamar($evento['conteudo']);
                    }

                    if ($evento['tipo'] === 'tool_use') {
                        $toolUses[] = $evento;
                    }
                }

                $blocos = [];

                if (filled(trim($texto))) {
                    $blocos[] = ['type' => 'text', 'text' => $texto];
                }

                foreach ($toolUses as $tool) {
                    $blocos[] = [
                        'type' => 'tool_use',
                        'id' => $tool['id'],
                        'name' => $tool['nome'],
                        'input' => $tool['input'],
                    ];
                }

                if ($blocos === []) {
                    break;
                }

                $this->mensagens[] = ['role' => 'assistant', 'content' => $blocos];

                if ($toolUses === []) {
                    break;
                }

                foreach ($toolUses as $tool) {
                    $ferramenta = $registro->obter($tool['nome']);

                    // Canal somente-leitura recusa aqui também, e não só ao montar
                    // as definições: tirar a ferramenta da lista impede de ser
                    // OFERECIDA, não de ser pedida. Modelo que insista no nome, ou
                    // histórico rehidratado de quando o canal aceitava, chegariam
                    // até a execução. Duas barreiras, como nas permissões.
                    if (! $this->comAcoes && $ferramenta instanceof Acao) {
                        $this->resultados[] = $this->resultado($tool['id'], [
                            'erro' => 'Este canal não altera dados. A operação não foi executada.',
                        ]);

                        continue;
                    }

                    // Ação vai para a fila em vez de executar. A permissão é
                    // checada aqui só para não propor o que o registro vai recusar
                    // de qualquer forma na hora de executar.
                    if ($ferramenta instanceof Acao && $ferramenta->exigeConfirmacao() && $ferramenta->permitida()) {
                        $this->pendentes[] = [
                            'id' => $tool['id'],
                            'nome' => $tool['nome'],
                            'input' => $tool['input'],
                            'confirmacao' => $ferramenta->confirmacao($tool['input']),
                        ];

                        continue;
                    }

                    $verbo = $ferramenta instanceof Acao ? 'executando' : 'consultando';
                    $this->streamar("\n\n[{$verbo} {$tool['nome']}...]\n\n");

                    $this->resultados[] = $this->resultado(
                        $tool['id'],
                        $registro->executar($tool['nome'], $tool['input'])
                    );
                }

                // Pausa. Sai sem gravar os tool_results — quem retoma o loop é
                // resolver(), depois da última decisão. A volta conta aqui, senão
                // cada confirmação daria uma volta de brinde no max_iteracoes.
                // Ainda sobra a volta em que o modelo comenta o resultado, porque o
                // do/while executa o corpo antes de testar — e essa é justamente a
                // que quem perguntou precisa ouvir.
                if ($this->pausada()) {
                    $this->iteracao++;

                    return;
                }

                $this->mensagens[] = ['role' => 'user', 'content' => $this->resultados];
                $this->resultados = [];

                $this->iteracao++;
            } while ($this->iteracao < $maxIteracoes);
        } catch (Throwable $th) {
            $this->fecharToolUsesAbertos('A execução falhou: '.$th->getMessage());

            throw $th;
        }
    }

    /**
     * Decide uma pendência e devolve se a conversa está livre para continuar — o
     * que só acontece quando a última pendência da rodada foi decidida.
     *
     * Tirar da fila antes de executar é o que evita efeito dobrado por clique
     * repetido, mensagem duplicada ou retry da requisição: a segunda chamada não
     * acha o id.
     *
     * @param  (Closure(Throwable): void)|null  $aoFalhar  Avisado quando a ação
     *                                                     estoura. O erro vira
     *                                                     tool_result de todo jeito.
     */
    public function resolver(string $id, bool $aprovada, ?Closure $aoFalhar = null): bool
    {
        $indice = null;

        foreach ($this->pendentes as $chave => $pendente) {
            if ($pendente['id'] === $id) {
                $indice = $chave;

                break;
            }
        }

        if ($indice === null) {
            return false;
        }

        $pendente = $this->pendentes[$indice];

        unset($this->pendentes[$indice]);
        $this->pendentes = array_values($this->pendentes);

        $this->resultados[] = $this->resultado($id, $this->executarPendente($pendente, $aprovada, $aoFalhar));

        if ($this->pausada()) {
            return false;
        }

        $this->mensagens[] = ['role' => 'user', 'content' => $this->resultados];
        $this->resultados = [];

        return true;
    }

    /**
     * Recusa tudo que está pendente de uma vez. Devolve se a conversa ficou livre.
     */
    public function recusarTudo(): bool
    {
        $livre = false;

        foreach (array_column($this->pendentes, 'id') as $id) {
            $livre = $this->resolver($id, aprovada: false);
        }

        return $livre;
    }

    /**
     * O texto que o modelo produziu nesta rodada: tudo desde a última pergunta.
     *
     * Não é só o último bloco — numa rodada com ferramenta o modelo costuma falar
     * antes e depois de consultar, e quem lê pelo WhatsApp precisa das duas partes.
     */
    public function respostaFinal(): string
    {
        $textos = [];

        foreach (array_reverse($this->mensagens) as $mensagem) {
            // A pergunta é a única mensagem de usuário com conteúdo em texto puro;
            // as outras são blocos de tool_result.
            if ($mensagem['role'] === 'user' && ! is_array($mensagem['content'])) {
                break;
            }

            if ($mensagem['role'] !== 'assistant' || ! is_array($mensagem['content'])) {
                continue;
            }

            foreach (array_reverse($mensagem['content']) as $bloco) {
                if (($bloco['type'] ?? null) === 'text' && filled(trim((string) ($bloco['text'] ?? '')))) {
                    $textos[] = trim((string) $bloco['text']);
                }
            }
        }

        return implode("\n\n", array_reverse($textos));
    }

    /**
     * Contexto e glossário vêm da aplicação; as regras invariantes são do pacote.
     */
    public function systemPrompt(): string
    {
        $usuario = Auth::user()?->name ?? 'usuário';
        $hoje = now()->format('d/m/Y');
        $contexto = trim((string) config('claudinho.contexto', ''));
        $temAcoes = $this->comAcoes && app(FerramentaRegistry::class)->temAcoes();

        $partes = [$contexto, "Você está conversando com {$usuario}. Hoje é {$hoje}."];

        $glossario = array_filter((array) config('claudinho.glossario', []));

        if ($glossario !== []) {
            $partes[] = "Regras de negócio desta aplicação:\n- ".implode("\n- ", $glossario);
        }

        // Afirmar "somente-leitura" quando existe ação exposta seria o próprio
        // prompt desautorizando as ferramentas que acabamos de declarar.
        $partes[] = $temAcoes
            ? 'Você tem ferramentas de consulta ao banco e ferramentas que alteram dados. '
                .'As que alteram estão marcadas na própria descrição.'
            : 'Você tem ferramentas de consulta somente-leitura ao banco.';

        $partes[] = <<<'REGRAS'
        Use as consultas sempre que a resposta depender de dados do sistema — não responda de
        memória e não peça permissão para consultar, apenas consulte.

        Cada ferramenta já devolve apenas os dados que este usuário tem permissão de ver: o
        filtro é aplicado no banco, não por você. Portanto:
        - Nunca afirme ou sugira que existem registros além dos retornados.
        - Se um resultado vier vazio, diga que não há registro entre os dados retornados —
          não conclua nada sobre o que você não consultou.
        - Se uma ferramenta devolver o campo "erro", explique o erro em português claro e,
          se for caso de permissão, diga que o acesso não está liberado para este usuário.
        - Quando vier "truncado": true, informe o total real, diga quantos está mostrando e
          sugira um filtro mais estreito.

        Nunca invente nomes, datas, quantidades ou situações de registros. Use exatamente os
        valores devolvidos pelas ferramentas.

        Responda sempre em português do Brasil. Seja direto e conciso: a primeira frase
        responde a pergunta, o detalhamento vem depois. Para listas com mais de 3 itens,
        use tabela markdown. Não repita a pergunta antes de responder.
        REGRAS;

        if ($temAcoes) {
            $partes[] = <<<'ACOES'
            Sobre as ferramentas que alteram dados:
            - Não peça permissão por texto e não descreva o que "vai" fazer: chame a
              ferramenta. Quem pede a confirmação é a interface, mostrando o efeito ao
              usuário, e nada é alterado antes dele aprovar.
            - Nunca chame uma alteração para descobrir dado ou para testar. Consulte primeiro
              e altere só com identificadores que você já confirmou existirem.
            - Uma alteração por vez, a menos que o usuário tenha pedido as várias. Se estiver
              ambíguo QUAIS registros ele quer alterar, pergunte antes de chamar.
            - Resultado com "recusada": true significa que o usuário não autorizou e nada foi
              alterado. Confirme isso a ele e não tente de novo sem ele pedir.
            - Resultado com "erro" pode ter deixado efeito parcial: diga o que falhou e sugira
              conferir o registro. Não repita a chamada por conta própria.
            ACOES;
        }

        if (config('claudinho.grafico.habilitado', true)) {
            $partes[] = 'Quando o usuário pedir visão gráfica, ou quando a comparação entre categorias '
                .'ficar mais clara visualmente, use gerar_grafico — sempre depois de consultar os dados, '
                .'nunca com números estimados. Você não gera imagens nem código de gráfico: gerar_grafico '
                .'é o único jeito de desenhar, e o desenho aparece na conversa.';
        }

        // Por último de propósito: instrução mais recente vence, e é assim que o
        // canal consegue revogar a regra de tabela markdown que vem acima.
        $partes[] = $this->instrucoes;

        return implode("\n\n", array_filter($partes));
    }

    /**
     * Filtrar aqui, e não no registro, mantém o registro com uma verdade só: o que
     * a aplicação declarou. Quem restringe é a conversa, que sabe por onde ela está
     * acontecendo. Sem as definições, a ação não pode nem ser pedida.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definicoes(FerramentaRegistry $registro): array
    {
        $definicoes = $registro->definicoes();

        if ($this->comAcoes) {
            return $definicoes;
        }

        return array_values(array_filter(
            $definicoes,
            fn (array $definicao) => ! $registro->ehAcao((string) $definicao['name'])
        ));
    }

    /**
     * @param  array<string, mixed>  $pendente
     * @param  (Closure(Throwable): void)|null  $aoFalhar
     * @return array<string, mixed>
     */
    private function executarPendente(array $pendente, bool $aprovada, ?Closure $aoFalhar): array
    {
        if (! $aprovada) {
            return [
                'recusada' => true,
                'motivo' => 'O usuário não autorizou esta ação. Nada foi alterado.',
            ];
        }

        try {
            // executar() revalida a permissão: entre a proposta e a decisão o gate
            // pode ter mudado.
            return app(FerramentaRegistry::class)->executar($pendente['nome'], $pendente['input']);
        } catch (Throwable $th) {
            // Exceção aqui não pode virar tool_result faltando: sem o par a conversa
            // fica inutilizável e a alteração some do registro, mesmo tendo
            // possivelmente sido aplicada em parte.
            if ($aoFalhar !== null) {
                $aoFalhar($th);
            }

            return ['erro' => 'A ação falhou e pode ter ficado pela metade: '.$th->getMessage()];
        }
    }

    /**
     * A API rejeita tool_use sem o tool_result correspondente, então uma falha no
     * meio do loop deixaria a conversa inutilizável — e, com ações, alteração
     * aplicada sem registro nenhum. Fechar as pendências com erro mantém a conversa
     * válida e deixa o modelo explicar.
     */
    private function fecharToolUsesAbertos(string $motivo): void
    {
        $this->pendentes = [];

        $ultima = $this->mensagens === [] ? null : $this->mensagens[array_key_last($this->mensagens)];

        if (($ultima['role'] ?? null) !== 'assistant' || ! is_array($ultima['content'] ?? null)) {
            return;
        }

        $respondidos = array_column($this->resultados, 'tool_use_id');

        foreach ($ultima['content'] as $bloco) {
            if (($bloco['type'] ?? null) !== 'tool_use' || in_array($bloco['id'], $respondidos, true)) {
                continue;
            }

            $this->resultados[] = $this->resultado((string) $bloco['id'], ['erro' => $motivo]);
        }

        if ($this->resultados === []) {
            return;
        }

        $this->mensagens[] = ['role' => 'user', 'content' => $this->resultados];
        $this->resultados = [];
    }

    /**
     * @param  array<string, mixed>  $conteudo
     * @return array<string, mixed>
     */
    private function resultado(string $toolUseId, array $conteudo): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function streamar(string $texto): void
    {
        if ($this->aoStreamar !== null) {
            ($this->aoStreamar)($texto);
        }
    }

    /**
     * Limita o histórico enviado à API, sem deixar tool_result órfão no início
     * (a API rejeita tool_result cujo tool_use não está no histórico).
     *
     * @return array<int, array<string, mixed>>
     */
    private function historico(): array
    {
        $historico = array_slice($this->mensagens, -(int) config('claudinho.limite_historico', 20));

        while ($historico !== [] && ($historico[0]['role'] !== 'user' || $this->contemToolResult($historico[0]))) {
            array_shift($historico);
        }

        return array_values($historico);
    }

    /**
     * @param  array<string, mixed>  $mensagem
     */
    private function contemToolResult(array $mensagem): bool
    {
        if (! is_array($mensagem['content'])) {
            return false;
        }

        foreach ($mensagem['content'] as $bloco) {
            if (($bloco['type'] ?? null) === 'tool_result') {
                return true;
            }
        }

        return false;
    }
}
