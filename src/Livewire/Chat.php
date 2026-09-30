<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Rogga\Claudinho\Claudinho;
use Rogga\Claudinho\Conversa;
use Rogga\Claudinho\Exibicao;
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

    /**
     * O painel de histórico está aberto ao lado da conversa?
     *
     * Mora aqui, e não no Alpine como o modal de configurações, porque não é só
     * mostrar e esconder: o painel divide a largura com o card, e quem desenha essa
     * largura é o Blade. Estado no cliente deixaria o servidor mandar sempre o
     * layout de painel fechado, e o card só encolheria depois do primeiro morph.
     *
     * O componente do histórico nem é montado enquanto está fechado — é o que evita
     * uma consulta ao banco em toda página que só tem o botão flutuante no layout.
     *
     * Locked porque abrir é o que monta o painel: sem isto, um `$wire.set()` legítimo
     * de quem não tem a permissão montaria o componente só para tomar 403 no mount, e
     * o erro chegaria no meio da conversa de quem estava usando o chat. Nada vaza nos
     * dois casos — o painel se recusa a montar —, mas um deles não estraga a tela.
     */
    #[Locked]
    public bool $historicoAberto = false;

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
     * Achata a conversa para exibição. Quem traduz é o Exibicao, compartilhado com
     * o histórico: as duas telas desenham a mesma conversa e não podem divergir no
     * que separa "alterou" de "o usuário recusou".
     *
     * @return array<int, array<string, mixed>>
     */
    public function mensagensVisiveis(): array
    {
        return Exibicao::mensagens($this->conversa);
    }

    public function temConversa(): bool
    {
        return $this->conversa !== [];
    }

    /**
     * O que a janela "Sobre" mostra.
     *
     * O modelo entra na lista de versões e não numa seção própria porque é a mesma
     * pergunta que as outras linhas respondem: com o que este chat está funcionando.
     * Vem do mesmo lugar que o Claude lê — o gravado em tela, com o config como
     * padrão — senão a janela mostraria o do .env enquanto as respostas vêm de outro.
     *
     * Aparece para qualquer usuário, e não só para quem administra: qual modelo
     * responde é transparência sobre a resposta, não configuração do sistema. O que
     * é segredo (chave e token) não passa por aqui.
     *
     * @return array{modelo: string, ambiente: array<string, string>, pacote: string, licenca: string, desenvolvedor: string}
     */
    public function sobre(): array
    {
        return [
            'modelo' => (string) Configuracao::valor('model', config('claudinho.model')),
            'ambiente' => Claudinho::ambiente(),
            'pacote' => Claudinho::PACOTE,
            'licenca' => Claudinho::LICENCA,
            'desenvolvedor' => Claudinho::DESENVOLVEDOR,
        ];
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
     * Gate do relógio. Só decide se o botão e o painel aparecem — quem barra a
     * leitura é o próprio componente do histórico, a cada chamada.
     */
    public function podeVerHistorico(): bool
    {
        return Historico::disponivel();
    }

    /**
     * Abre e fecha o painel. Revalida o gate porque `wire:click` é chamada do
     * cliente: botão ausente no HTML não é autorização.
     */
    public function alternarHistorico(): void
    {
        abort_unless($this->podeVerHistorico(), 403);

        $this->historicoAberto = ! $this->historicoAberto;
    }

    /**
     * O X do próprio painel. Vem por evento porque quem fecha é o outro componente,
     * e o dono é comparado porque a mesma página pode ter dois chats — sem isso, o
     * X de um fecharia o painel do outro junto.
     */
    #[On('claudinho-historico-fechar')]
    public function fecharHistorico(string $dono = ''): void
    {
        if ($dono === '' || $dono === $this->getId()) {
            $this->historicoAberto = false;
        }
    }
}
