<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rogga\Claudinho\Claude;
use Rogga\Claudinho\Contracts\Acao;
use Rogga\Claudinho\FerramentaRegistry;
use Rogga\Claudinho\Grafico\Especificacao;
use Throwable;

class Chat extends Component
{
    /**
     * Conversa no formato da API (blocos de conteúdo, incluindo tool_use/tool_result).
     *
     * Locked porque é o que é enviado à API: o checksum do snapshot já barra
     * adulteração do payload, mas não uma chamada legítima de `$wire.set()`.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $conversa = [];

    /**
     * Ações propostas pelo modelo aguardando decisão do usuário — cada item traz
     * o `id` do tool_use, o nome, o input e a frase de confirmação.
     *
     * Locked por segurança, não por higiene: é exatamente este input que vai ser
     * executado se o usuário aprovar.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $pendentes = [];

    /**
     * tool_results já prontos da rodada em pausa. Só vão para a conversa quando a
     * última pendência for decidida: a API exige que todo tool_use da mensagem do
     * assistente seja respondido de uma vez, na mensagem seguinte.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $resultados = [];

    /** Volta do loop em que a pausa aconteceu, para max_iteracoes seguir valendo. */
    #[Locked]
    public int $iteracao = 0;

    public string $pergunta = '';

    public bool $respondendo = false;

    /**
     * Modo flutuante: em vez do card na largura da página, um botão fixo num canto
     * que abre o painel. Vem por parâmetro e não por config porque é decisão de
     * onde o componente foi colocado — a mesma aplicação pode ter a tela dedicada
     * e o botão no layout global.
     */
    #[Locked]
    public bool $flutuante = false;

    public function mount(): void
    {
        $permissao = config('claudinho.permissao');

        if (filled($permissao)) {
            abort_unless(Auth::user()?->can($permissao), 403);
        }
    }

    public function render()
    {
        return view('claudinho::livewire.chat');
    }

    public function enviar(): void
    {
        // Pergunta nova enquanto há ação pendente deixaria um tool_use sem
        // tool_result no histórico, e a API rejeita a conversa inteira.
        if ($this->pendentes !== []) {
            return;
        }

        $this->validate([
            'pergunta' => ['required', 'string', 'max:4000'],
        ]);

        $this->conversa[] = [
            'role' => 'user',
            'content' => trim($this->pergunta),
        ];

        $this->pergunta = '';
        $this->iteracao = 0;
        $this->respondendo = true;
    }

    public function responder(): void
    {
        if (! $this->respondendo) {
            return;
        }

        $claude = new Claude;
        $registro = app(FerramentaRegistry::class);
        $definicoes = $registro->definicoes();
        $maxIteracoes = (int) config('claudinho.max_iteracoes', 5);

        try {
            do {
                $texto = '';
                $toolUses = [];

                foreach ($claude->stream($this->historico(), $this->systemPrompt(), $definicoes) as $evento) {
                    if ($evento['tipo'] === 'texto') {
                        $texto .= $evento['conteudo'];
                        $this->stream(to: 'resposta', content: $evento['conteudo']);
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

                $this->conversa[] = ['role' => 'assistant', 'content' => $blocos];

                if ($toolUses === []) {
                    break;
                }

                foreach ($toolUses as $tool) {
                    $ferramenta = $registro->obter($tool['nome']);

                    // Ação vai para a fila em vez de executar. A permissão é
                    // checada aqui só para não propor ao usuário o que o registro
                    // vai recusar de qualquer forma na hora de executar.
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
                    $this->stream(to: 'resposta', content: "\n\n[{$verbo} {$tool['nome']}...]\n\n");

                    $this->resultados[] = $this->resultado(
                        $tool['id'],
                        $registro->executar($tool['nome'], $tool['input'])
                    );
                }

                // Pausa. Sai sem gravar os tool_results — quem retoma o loop é
                // resolver(), depois da última decisão do usuário. A volta conta
                // aqui, senão cada confirmação daria uma volta de brinde no
                // max_iteracoes. Ainda sobra a volta em que o modelo comenta o
                // resultado, porque o do/while executa o corpo antes de testar —
                // e essa é justamente a que o usuário precisa ouvir.
                if ($this->pendentes !== []) {
                    $this->iteracao++;
                    $this->respondendo = false;
                    $this->avisarQueRespondeu();

                    return;
                }

                $this->conversa[] = ['role' => 'user', 'content' => $this->resultados];
                $this->resultados = [];

                $this->iteracao++;
            } while ($this->iteracao < $maxIteracoes);
        } catch (Throwable $th) {
            $this->respondendo = false;
            $this->fecharToolUsesAbertos('A execução falhou: '.$th->getMessage());
            $this->falhar($th->getMessage());

            return;
        }

        $this->respondendo = false;
        $this->avisarQueRespondeu();
    }

    /**
     * O painel flutuante fechado precisa marcar que chegou resposta — inclusive
     * quando o loop parou pedindo confirmação, que é o caso mais urgente. No modo
     * inline ninguém escuta, e o evento não custa nada.
     */
    private function avisarQueRespondeu(): void
    {
        $this->dispatch('claudinho-resposta-pronta');
    }

    /**
     * Aprova a ação pendente e retoma o loop.
     */
    public function confirmar(string $id): void
    {
        $this->resolver($id, aprovada: true);
    }

    /**
     * Recusa a ação pendente. Não é o mesmo que cancelar a pergunta: o modelo
     * recebe a recusa como resultado e volta a falar, para o usuário ter uma
     * resposta em vez de um card que desaparece.
     */
    public function recusar(string $id): void
    {
        $this->resolver($id, aprovada: false);
    }

    /**
     * Tirar da fila antes de executar é o que evita efeito dobrado por clique
     * repetido ou por retry da requisição: a segunda chamada não acha o id.
     */
    private function resolver(string $id, bool $aprovada): void
    {
        $indice = null;

        foreach ($this->pendentes as $chave => $pendente) {
            if ($pendente['id'] === $id) {
                $indice = $chave;

                break;
            }
        }

        if ($indice === null) {
            return;
        }

        $pendente = $this->pendentes[$indice];

        unset($this->pendentes[$indice]);
        $this->pendentes = array_values($this->pendentes);

        $this->resultados[] = $this->resultado($id, $this->executarPendente($pendente, $aprovada));

        // Uma rodada pode ter proposto mais de uma ação; a conversa só volta a
        // ser válida quando todas tiverem resultado.
        if ($this->pendentes !== []) {
            return;
        }

        $this->conversa[] = ['role' => 'user', 'content' => $this->resultados];
        $this->resultados = [];

        // O wire:init da bolha dispara responder() de novo, e o loop continua da
        // iteração em que parou.
        $this->respondendo = true;
    }

    /**
     * @param  array<string, mixed>  $pendente
     * @return array<string, mixed>
     */
    private function executarPendente(array $pendente, bool $aprovada): array
    {
        if (! $aprovada) {
            return [
                'recusada' => true,
                'motivo' => 'O usuário não autorizou esta ação. Nada foi alterado.',
            ];
        }

        try {
            // executar() revalida a permissão: entre a proposta e o clique o gate
            // pode ter mudado.
            return app(FerramentaRegistry::class)->executar($pendente['nome'], $pendente['input']);
        } catch (Throwable $th) {
            // Exceção aqui não pode virar tool_result faltando: sem o par a
            // conversa fica inutilizável e a alteração some do registro, mesmo
            // tendo possivelmente sido aplicada em parte.
            $this->falhar($th->getMessage());

            return ['erro' => 'A ação falhou e pode ter ficado pela metade: '.$th->getMessage()];
        }
    }

    /**
     * A API rejeita tool_use sem o tool_result correspondente, então uma falha no
     * meio do loop deixava a conversa inutilizável até o "Limpar conversa" — e,
     * com ações, deixava alteração aplicada sem registro nenhum. Fechar as
     * pendências com erro mantém a conversa válida e deixa o modelo explicar.
     */
    private function fecharToolUsesAbertos(string $motivo): void
    {
        $this->pendentes = [];

        $ultima = $this->conversa === [] ? null : $this->conversa[array_key_last($this->conversa)];

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

        $this->conversa[] = ['role' => 'user', 'content' => $this->resultados];
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

    /**
     * Notifica a falha sem obrigar o pacote a depender do Filament: usa as
     * notificações dele quando existirem e, de qualquer forma, emite um evento
     * que a aplicação pode ouvir para exibir do jeito que preferir.
     */
    private function falhar(string $mensagem): void
    {
        $titulo = 'Não foi possível obter a resposta';

        if (class_exists(\Filament\Notifications\Notification::class)) {
            \Filament\Notifications\Notification::make()
                ->title($titulo)
                ->body($mensagem)
                ->danger()
                ->send();
        }

        $this->dispatch('claudinho-erro', titulo: $titulo, mensagem: $mensagem);
    }

    public function limpar(): void
    {
        $this->conversa = [];
        $this->pendentes = [];
        $this->resultados = [];
        $this->iteracao = 0;
        $this->pergunta = '';
        $this->respondendo = false;
    }

    /**
     * Achata a conversa para exibição: texto, gráficos e o rótulo das consultas e
     * alterações. Blocos tool_result (JSON cru) não vão para a tela.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mensagensVisiveis(): array
    {
        $registro = app(FerramentaRegistry::class);
        $situacoes = $this->situacoes();
        $visiveis = [];

        foreach ($this->conversa as $mensagem) {
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
                        'html' => $mensagem['role'] === 'assistant' ? $this->markdown($texto) : null,
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
                        'texto' => $this->rotulo($nome, (array) ($bloco['input'] ?? []), $acao, $situacao),
                    ];
                }
            }
        }

        return $visiveis;
    }

    public function temConversa(): bool
    {
        return $this->conversa !== [];
    }

    /**
     * Gate da engrenagem. Só decide se o botão e o componente de configurações
     * aparecem — quem barra a ação é o próprio componente, a cada chamada.
     */
    public function podeAdministrar(): bool
    {
        $permissao = config('claudinho.permissao_admin', 'claudinho_admin');

        return blank($permissao) || (bool) Auth::user()?->can($permissao);
    }

    /**
     * html_input strip é obrigatório: a resposta do modelo carrega dados vindos do
     * banco e não pode virar HTML executável.
     */
    private function markdown(string $texto): string
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
     * @return array<string, string>
     */
    private function situacoes(): array
    {
        $situacoes = [];

        foreach ($this->conversa as $mensagem) {
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
    private function rotulo(string $nome, array $input, bool $acao, string $situacao): string
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

    /**
     * Limita o histórico enviado à API, sem deixar tool_result órfão no início
     * (a API rejeita tool_result cujo tool_use não está no histórico).
     *
     * @return array<int, array<string, mixed>>
     */
    private function historico(): array
    {
        $historico = array_slice($this->conversa, -(int) config('claudinho.limite_historico', 20));

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

    /**
     * Contexto e glossário vêm da aplicação; as regras invariantes são do pacote.
     */
    private function systemPrompt(): string
    {
        $usuario = Auth::user()?->name ?? 'usuário';
        $hoje = now()->format('d/m/Y');
        $contexto = trim((string) config('claudinho.contexto', ''));
        $temAcoes = app(FerramentaRegistry::class)->temAcoes();

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

        return implode("\n\n", array_filter($partes));
    }
}
