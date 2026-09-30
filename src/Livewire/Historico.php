<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rogga\Claudinho\Contracts\ResolvedorDeUsuario;
use Rogga\Claudinho\Exibicao;
use Rogga\Claudinho\Models\ConversaExterna;

/**
 * O histórico das conversas gravadas: a lista de identificadores, da mais recente
 * para a mais antiga, e a conversa inteira de quem for escolhido.
 *
 * Componente separado do Chat de propósito, pelo mesmo motivo das configurações: o
 * Livewire serializa as propriedades públicas em toda requisição, e o histórico de
 * TERCEIROS não pode viajar junto de cada mensagem que alguém digita no chat.
 *
 * O que ele mostra são as conversas do canal externo (WhatsApp e afins), que são as
 * que têm estado gravado — a conversa da tela vive no componente e morre com a
 * sessão. É por isso que a chave da lista é o identificador do canal, e não um
 * título: do outro lado não há quem dê nome à conversa.
 *
 * Isto aqui expõe a conversa de OUTRAS pessoas, com os dados que elas consultaram.
 * O gate padrão é o de administração justamente por isso: quem pode abrir o chat
 * não pode, por consequência, ler o de todo mundo.
 */
class Historico extends Component
{
    /**
     * Id do Chat que abriu este painel, para o X devolver o fechamento ao dono certo
     * quando há mais de um chat na página.
     */
    #[Locked]
    public string $dono = '';

    /** Filtro por número ou nome — a lista passa das dezenas em pouco tempo. */
    public string $busca = '';

    /**
     * A conversa aberta; null é a lista.
     *
     * Locked: é o id que vai ao banco buscar conversa alheia. O gate é revalidado em
     * toda ação de qualquer forma, mas não há motivo para deixar o alvo editável
     * pelo cliente.
     */
    #[Locked]
    public ?int $conversaId = null;

    /**
     * Nomes já resolvidos nesta requisição, por canal+identificador+id.
     *
     * O resolver da aplicação vai ao banco, e o unique da tabela garante que cada
     * linha da lista é um número diferente — o cache não serve para a lista, serve
     * para a conversa aberta, que monta a linha dela uma segunda vez.
     *
     * @var array<string, string>
     */
    private array $usuarios = [];

    /**
     * O resolver já instanciado. `app()` por linha instanciaria de novo tudo que o
     * construtor dele injeta, e quem escreveu aquela classe não contava com isso.
     */
    private ?ResolvedorDeUsuario $resolvedor = null;

    /**
     * A última busca por nome varreu a janela inteira?
     *
     * Existe para a tela poder dizer até onde procurou. Cap silencioso lê como
     * "não existe essa pessoa", que é conclusão diferente de "não achei entre as
     * 200 mais recentes" — e é justamente a diferença que faz alguém desistir de
     * procurar uma conversa que está lá.
     */
    private bool $varreuTudo = false;

    /**
     * O relógio aparece no header do chat?
     *
     * Estático porque quem pergunta são dois componentes — o Chat, para desenhar o
     * botão, e este, para recusar a leitura. Duas cópias da regra dariam o botão
     * que abre um painel vazio de 403.
     *
     * `historico.permissao` vazio NÃO libera geral: cai no gate de administração,
     * que é o mais restritivo dos dois. Ler a conversa dos outros é operação de
     * administração, e um config publicado antes da 2.0 — que não tem a chave
     * nenhuma — precisa herdar o lado seguro.
     */
    public static function disponivel(): bool
    {
        if (! (bool) config('claudinho.historico.habilitado', true)) {
            return false;
        }

        $permissao = config('claudinho.historico.permissao')
            ?: config('claudinho.permissao_admin', 'claudinho_admin');

        return blank($permissao) || (bool) Auth::user()?->can($permissao);
    }

    public function mount(): void
    {
        $this->autoriza();
    }

    public function render()
    {
        return view('claudinho::livewire.historico');
    }

    /**
     * A lista, da alteração mais recente para a mais antiga.
     *
     * `updated_at` e não `expira_em`: o que a pessoa procura é a conversa em que
     * mexeu por último, e o vencimento anda sozinho com a inatividade configurada.
     *
     * Sem tabela é lista vazia, e não erro: o pacote pode estar instalado numa
     * aplicação que ainda não rodou o migrate, e a tela precisa dizer isso em vez
     * de estourar 500.
     *
     * A busca tem dois caminhos porque os dois campos moram em lugares diferentes, e
     * o custo de cada um é outro:
     *
     *   número  está na tabela — o LIKE resolve, e não há teto de varredura;
     *   nome    não está em lugar nenhum do pacote. Quem sabe é o resolver da
     *           aplicação, uma chamada por conversa. Varre as mais recentes até o
     *           `varredura` e filtra fora do SQL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conversas(): array
    {
        $this->autoriza();

        $this->varreuTudo = false;

        if (! $this->temTabela()) {
            return [];
        }

        $termo = trim($this->busca);
        $limite = max(1, (int) config('claudinho.historico.limite', 30));

        if ($termo === '') {
            return $this->linhas(ConversaExterna::query()->recentes()->limit($limite)->get());
        }

        if (self::ehNumero($termo)) {
            // Pelos dígitos, e não pelo texto digitado: o identificador é gravado como
            // o canal manda, e quem procura cola do jeito que tem em mãos — "(47)
            // 99911-0130" não casaria com `47999110130` se fosse comparado inteiro.
            return $this->linhas(
                ConversaExterna::query()
                    ->where('identificador', 'like', '%'.self::digitos($termo).'%')
                    ->recentes()
                    ->limit($limite)
                    ->get()
            );
        }

        $varredura = max(1, (int) config('claudinho.historico.varredura', 200));

        $conversas = ConversaExterna::query()->recentes()->limit($varredura)->get();

        $this->varreuTudo = $conversas->count() >= $varredura;

        // O nome é resolvido antes de montar a linha: a linha traz prévia e contagem,
        // que custam ler o JSON inteiro, e não se paga isso por conversa descartada.
        $achadas = $conversas->filter(fn (ConversaExterna $conversa): bool => mb_stripos(
            $this->usuario((string) $conversa->canal, (string) $conversa->identificador, (int) $conversa->user_id),
            $termo
        ) !== false);

        return $this->linhas($achadas->take($limite));
    }

    /**
     * A busca por nome parou no teto da varredura? A tela diz até onde procurou.
     */
    public function varreuTudo(): bool
    {
        return $this->varreuTudo;
    }

    /**
     * Só dígitos e a pontuação com que se escreve telefone. Aí é busca por número, e
     * número está no banco.
     *
     * Termo com letra vira busca por nome mesmo que traga dígitos: ninguém digita
     * "joão 47" procurando um telefone.
     */
    private static function ehNumero(string $termo): bool
    {
        return preg_match('/^[\d\s()+.\-]+$/u', $termo) === 1 && self::digitos($termo) !== '';
    }

    private static function digitos(string $termo): string
    {
        return (string) preg_replace('/\D+/u', '', $termo);
    }

    /**
     * @param  Collection<int, ConversaExterna>  $conversas
     * @return array<int, array<string, mixed>>
     */
    private function linhas(Collection $conversas): array
    {
        return $conversas->map(fn (ConversaExterna $conversa): array => $this->linha($conversa))
            ->values()
            ->all();
    }

    /**
     * A tabela existe? Cabe a resposta "não" porque as migrations do pacote são
     * carregadas mas não rodadas sozinhas.
     *
     * Banco fora do ar também responde "não", em vez de derrubar a página: o painel
     * abre DENTRO do chat, e uma exceção aqui levaria junto a conversa que a pessoa
     * estava tendo. O erro vai para o log pelo report padrão do rescue, que é o que
     * separa isto de engolir a falha.
     */
    public function temTabela(): bool
    {
        return (bool) rescue(fn (): bool => Schema::hasTable((new ConversaExterna)->getTable()), false);
    }

    public function abrir(int $id): void
    {
        $this->autoriza();

        $this->conversaId = $id;
    }

    public function voltar(): void
    {
        $this->conversaId = null;
    }

    /**
     * Fecha o painel inteiro. Quem esconde é o Chat, dono da largura — daí o evento
     * em vez de uma propriedade daqui.
     */
    public function fechar(): void
    {
        $this->dispatch('claudinho-historico-fechar', dono: $this->dono);
    }

    /**
     * A conversa aberta, já achatada para a tela. null quando não há nenhuma
     * escolhida — ou quando a escolhida sumiu do banco entre o clique e a leitura,
     * que é o que a faxina do `claudinho:limpar-conversas` faz.
     *
     * @return array<string, mixed>|null
     */
    public function conversa(): ?array
    {
        if ($this->conversaId === null) {
            return null;
        }

        $this->autoriza();

        if (! $this->temTabela()) {
            return null;
        }

        $conversa = ConversaExterna::query()->find($this->conversaId);

        if ($conversa === null) {
            return null;
        }

        $estado = (array) $conversa->estado;

        return $this->linha($conversa) + [
            'mensagens' => Exibicao::mensagens((array) ($estado['mensagens'] ?? [])),
            'pendentes' => (array) ($estado['pendentes'] ?? []),
        ];
    }

    /**
     * Uma linha da lista.
     *
     * A prévia e a contagem saem do estado, que já veio na consulta: a alternativa
     * seria uma segunda ida ao banco por linha aberta, e o JSON é justamente o que
     * a tela veio ler.
     *
     * @return array<string, mixed>
     */
    private function linha(ConversaExterna $conversa): array
    {
        $estado = (array) $conversa->estado;
        $mensagens = (array) ($estado['mensagens'] ?? []);

        return [
            'id' => (int) $conversa->id,
            'canal' => (string) $conversa->canal,
            'identificador' => (string) $conversa->identificador,
            'usuario' => $this->usuario((string) $conversa->canal, (string) $conversa->identificador, (int) $conversa->user_id),
            'atualizada_em' => $conversa->updated_at,
            'relativa' => $conversa->updated_at?->diffForHumans() ?? '',
            'falas' => Exibicao::falas($mensagens),
            'previa' => Exibicao::ultimaFala($mensagens),
            'ativa' => ! $conversa->venceu(),
            'pendente' => (array) ($estado['pendentes'] ?? []) !== [],
        ];
    }

    /**
     * O nome de quem respondeu por aquele identificador.
     *
     * Pergunta ao MESMO resolver que o endpoint usa para autenticar a conversa: é o
     * único que sabe mapear telefone para usuário nesta aplicação. O provedor de
     * autenticação padrão não serve sozinho — quem atende pelo WhatsApp costuma
     * viver em outro guard que não o `web`, e ali o id simplesmente não é achado.
     *
     * O id devolvido é conferido contra o gravado na conversa. Resolver que passou a
     * apontar para outra pessoa devolveria o nome de quem NÃO teve aquela conversa —
     * e num histórico que existe para auditar, atribuir a conversa à pessoa errada é
     * pior do que não dar nome nenhum.
     *
     * Sem resolver configurado, com ele quebrado ou com o número que ele não conhece
     * mais, sobra o id. A linha continua identificada pelo canal e pelo
     * identificador, que é o que se procura ali.
     */
    private function usuario(string $canal, string $identificador, int $id): string
    {
        $chave = $canal.'|'.$identificador.'|'.$id;

        if (array_key_exists($chave, $this->usuarios)) {
            return $this->usuarios[$chave];
        }

        $usuario = rescue(fn (): ?Authenticatable => $this->resolvedor()?->resolver($canal, $identificador), null);

        if ($usuario === null || (int) $usuario->getAuthIdentifier() !== $id) {
            return $this->usuarios[$chave] = "usuário #{$id}";
        }

        $nome = self::nome((string) ($usuario->name ?? ''));

        return $this->usuarios[$chave] = $nome !== '' ? $nome : "usuário #{$id}";
    }

    /**
     * O nome em caixa alta, que é como a lista mostra.
     *
     * Uniformizar é o ponto: o cadastro de origem grava parte dos nomes gritando e
     * parte não, e numa coluna estreita a mistura lê como se alguns estivessem
     * marcados — ênfase que ninguém quis dar. Em caixa alta todos pesam igual, e o
     * nome fica distinto do número logo acima, que é a outra coisa que se lê ali.
     *
     * Espaço repetido do cadastro entra na conta: `MAXWELL  F.` sairia com o buraco
     * no meio.
     *
     * Não vale para o `usuário #681` do resolver que não respondeu — aquilo não é
     * nome, é a ausência de um, e gritar a ausência só chamaria atenção para ela.
     */
    public static function nome(string $nome): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $nome)), 'UTF-8');
    }

    /**
     * O resolver da aplicação, ou null quando não há um configurado — o que é o
     * normal em quem nunca ligou o canal externo, e por isso não é erro.
     */
    private function resolvedor(): ?ResolvedorDeUsuario
    {
        if ($this->resolvedor !== null) {
            return $this->resolvedor;
        }

        $classe = (string) config('claudinho.api.resolvedor', '');

        if ($classe === '' || ! is_a($classe, ResolvedorDeUsuario::class, allow_string: true)) {
            return null;
        }

        return $this->resolvedor = app($classe);
    }

    private function autoriza(): void
    {
        abort_unless(static::disponivel(), 403);
    }
}
