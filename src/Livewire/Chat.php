<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rogga\Claudinho\Conversa;
use Rogga\Claudinho\FerramentaRegistry;
use Rogga\Claudinho\Grafico\Especificacao;
use Rogga\Claudinho\Models\Configuracao;
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

    /**
     * O botão flutuante deve aparecer? Só faz sentido perguntar no modo flutuante —
     * no inline a aplicação decidiu mostrar o chat pondo o componente na página, e
     * não cabe a uma tela de configuração escondê-lo.
     *
     * Desligado, o componente segue montado e sem renderizar nada: quem estava no
     * meio de uma conversa perde o botão, não a sessão.
     */
    public function flutuanteVisivel(): bool
    {
        return $this->flutuante
            && Configuracao::booleano('flutuante_ativo', (bool) config('claudinho.flutuante.ativo', true));
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

        $conversa = $this->motor();
        $conversa->perguntar($this->pergunta);
        $this->gravar($conversa);

        $this->pergunta = '';
        $this->respondendo = true;
    }

    public function responder(): void
    {
        if (! $this->respondendo) {
            return;
        }

        // O motor é criado a cada requisição a partir do estado serializado: o
        // Livewire não guarda objeto entre requisições, e o loop precisa ser o mesmo
        // que o endpoint HTTP roda.
        $conversa = $this->motor();

        try {
            $conversa->responder();
        } catch (Throwable $th) {
            // O motor já fechou os tool_use abertos antes de propagar, então gravar o
            // estado aqui é o que mantém a conversa válida para a próxima pergunta.
            $this->gravar($conversa);
            $this->respondendo = false;
            $this->falhar($th->getMessage());

            return;
        }

        $this->gravar($conversa);
        $this->respondendo = false;
        $this->avisarQueRespondeu();
    }

    private function motor(): Conversa
    {
        return Conversa::de([
            'mensagens' => $this->conversa,
            'pendentes' => $this->pendentes,
            'resultados' => $this->resultados,
            'iteracao' => $this->iteracao,
        ], aoStreamar: fn (string $texto) => $this->stream(to: 'resposta', content: $texto));
    }

    private function gravar(Conversa $conversa): void
    {
        $estado = $conversa->estado();

        $this->conversa = $estado['mensagens'];
        $this->pendentes = $estado['pendentes'];
        $this->resultados = $estado['resultados'];
        $this->iteracao = $estado['iteracao'];
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

    private function resolver(string $id, bool $aprovada): void
    {
        $conversa = $this->motor();

        $livre = $conversa->resolver($id, $aprovada, aoFalhar: fn (Throwable $th) => $this->falhar($th->getMessage()));

        $this->gravar($conversa);

        // O wire:init da bolha dispara responder() de novo, e o loop continua da
        // iteração em que parou. Só quando a última pendência da rodada foi decidida:
        // antes disso a conversa ainda tem tool_use sem tool_result.
        if ($livre) {
            $this->respondendo = true;
        }
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

}
