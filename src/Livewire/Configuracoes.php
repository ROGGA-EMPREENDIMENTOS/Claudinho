<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rogga\Claudinho\Models\Configuracao;
use Throwable;

/**
 * Cadastro das configurações que mudam sem deploy. Componente separado do Chat de
 * propósito: o Livewire serializa as propriedades públicas em toda requisição, e
 * estado de administração não deve viajar junto de cada mensagem do chat.
 */
class Configuracoes extends Component
{
    /**
     * Id do Chat que abriu este modal.
     *
     * Existe porque a mesma página pode ter mais de um chat — o botão flutuante no
     * layout global e o card numa tela dedicada, por exemplo. Cada um renderiza o
     * seu modal, e sem isto uma clicada na engrenagem abria TODOS: o primeiro X
     * fechava o de cima e revelava o de baixo, dando a impressão de precisar clicar
     * duas vezes.
     *
     * Vazio significa "atende qualquer chamada", para quem monte este componente
     * sozinho, fora de um chat.
     */
    #[Locked]
    public string $dono = '';

    public string $modelo = '';

    /**
     * Só escrita. A chave gravada nunca é carregada aqui — propriedade pública do
     * Livewire vai para o HTML e volta em cada requisição, e o segredo não pode
     * fazer esse caminho. Para exibir, existe o chaveEmUso(), que só devolve máscara.
     */
    public string $chaveNova = '';

    /** O botão flutuante aparece? Só tem efeito onde a aplicação colocou o componente. */
    public bool $flutuante = true;

    /** O endpoint aceita requisições? */
    public bool $api = false;

    /**
     * Token recém-gerado, mostrado UMA vez.
     *
     * Ao contrário da chave do Claude, este segredo precisa ser lido: quem opera
     * tem de copiá-lo para o gateway. Some na próxima interação, e depois só resta
     * a máscara — perdeu, gera outro.
     */
    public string $tokenGerado = '';

    public function mount(): void
    {
        $this->autoriza();

        $this->modelo = (string) Configuracao::valor('model', config('claudinho.model'));
        $this->flutuante = Configuracao::booleano('flutuante_ativo', (bool) config('claudinho.flutuante.ativo', true));
        $this->api = Configuracao::booleano('api_ativa', (bool) config('claudinho.api.habilitado', false));
    }

    /**
     * Gera o token do chamador. Gerado e não digitado: é segredo de máquina, e
     * senha escolhida por gente aqui seria fraca sem necessidade.
     */
    public function gerarToken(): void
    {
        $this->autoriza();

        $this->tokenGerado = 'clau_'.Str::random(48);

        Configuracao::definir('api_token', $this->tokenGerado);

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    public function revogarToken(): void
    {
        $this->autoriza();

        Configuracao::definir('api_token', null);

        $this->tokenGerado = '';

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * De onde o token do chamador vem, sem revelá-lo.
     *
     * @return array{origem: 'tela'|'env'|'ausente', dica: string|null}
     */
    public function tokenEmUso(): array
    {
        $daTela = Configuracao::valor('api_token');

        if (filled($daTela)) {
            return ['origem' => 'tela', 'dica' => $this->mascara($daTela)];
        }

        $doEnv = config('claudinho.api.token');

        if (filled($doEnv)) {
            return ['origem' => 'env', 'dica' => $this->mascara((string) $doEnv)];
        }

        return ['origem' => 'ausente', 'dica' => null];
    }

    public function render()
    {
        return view('claudinho::livewire.configuracoes');
    }

    public function salvar(): void
    {
        // mount() roda uma vez; cada ação precisa revalidar por conta própria.
        $this->autoriza();

        $this->validate([
            'modelo' => ['required', 'string', 'max:100'],
            // Sem validar o prefixo sk-ant-: quem usa gateway ou proxy tem chave
            // de outro formato e não deve ficar travado aqui.
            'chaveNova' => ['nullable', 'string', 'min:20', 'max:200'],
        ], attributes: [
            'modelo' => 'modelo',
            'chaveNova' => 'chave da API',
        ]);

        Configuracao::definir('model', $this->modelo);
        Configuracao::definirBooleano('flutuante_ativo', $this->flutuante);
        Configuracao::definirBooleano('api_ativa', $this->api);

        if (filled($this->chaveNova)) {
            Configuracao::definir('api_key', trim($this->chaveNova));
        }

        $this->chaveNova = '';
        // O token só aparece na resposta em que foi gerado.
        $this->tokenGerado = '';

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * Limpar não apaga o registro: grava vazio, e valor vazio cai no env de novo.
     */
    public function limparChave(): void
    {
        $this->autoriza();

        Configuracao::definir('api_key', null);

        $this->chaveNova = '';

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * De onde a chave em uso está vindo, sem revelar a chave.
     *
     * @return array{origem: 'tela'|'env'|'ausente', dica: string|null}
     */
    public function chaveEmUso(): array
    {
        $daTela = Configuracao::valor('api_key');

        if (filled($daTela)) {
            return ['origem' => 'tela', 'dica' => $this->mascara($daTela)];
        }

        $doEnv = config('claudinho.api_key');

        if (filled($doEnv)) {
            return ['origem' => 'env', 'dica' => $this->mascara((string) $doEnv)];
        }

        return ['origem' => 'ausente', 'dica' => null];
    }

    /**
     * O que ainda falta para o endpoint atender, em linguagem de quem opera.
     *
     * Três dos quatro itens se resolvem nesta tela. O quarto é o resolvedor de
     * usuário, que é uma CLASSE e por isso não tem como vir de formulário: é ele
     * que responde de quem é a permissão sobre os dados de cada número.
     *
     * @return array{ativa: bool, pronta: bool, url: string, itens: array<int, array{ok: bool, texto: string}>}
     */
    public function situacaoApi(): array
    {
        $prefixo = trim((string) config('claudinho.api.prefixo', 'claudinho'), '/');
        $resolvedor = (string) config('claudinho.api.resolvedor', '');
        $temToken = $this->tokenEmUso()['origem'] !== 'ausente';
        $temTabela = $this->tabelaDeConversas();

        $itens = [
            [
                'ok' => $this->api,
                'texto' => $this->api
                    ? 'Atendimento ligado.'
                    : 'Atendimento desligado: o endpoint responde 503.',
            ],
            [
                'ok' => $temToken,
                'texto' => $temToken
                    ? 'Token do chamador definido.'
                    : 'Sem token: gere um abaixo, senão o endpoint responde 503.',
            ],
            [
                // Único item que só o código resolve: é uma classe, não tem como vir
                // de um formulário.
                'ok' => $resolvedor !== '',
                'texto' => $resolvedor !== ''
                    ? 'Resolvedor de usuário: '.class_basename($resolvedor).'.'
                    : 'Falta a classe resolvedora em claudinho.api.resolvedor — sem ela o '
                        .'endpoint não sabe de quem é a permissão.',
            ],
            [
                'ok' => $temTabela,
                'texto' => $temTabela
                    ? 'Tabela de conversas migrada.'
                    : 'Falta rodar php artisan migrate (tabela claudinho_conversas).',
            ],
        ];

        return [
            'ativa' => $this->api,
            'pronta' => ! in_array(false, array_column($itens, 'ok'), true),
            'url' => url($prefixo.'/conversa'),
            'itens' => $itens,
        ];
    }

    /**
     * Igual ao todas(): nada aqui pode derrubar a tela por causa de banco.
     */
    private function tabelaDeConversas(): bool
    {
        try {
            return Schema::hasTable('claudinho_conversas');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public function modelosDisponiveis(): array
    {
        $modelos = (array) config('claudinho.modelos', []);

        // Modelo gravado fora da lista (mudou o config depois) continua selecionável,
        // senão o select mudaria o modelo em produção sem ninguém pedir.
        if (filled($this->modelo) && ! array_key_exists($this->modelo, $modelos)) {
            $modelos = [$this->modelo => $this->modelo.' (fora da lista do config)'] + $modelos;
        }

        return $modelos;
    }

    public function podeAdministrar(): bool
    {
        $permissao = config('claudinho.permissao_admin', 'claudinho_admin');

        return blank($permissao) || (bool) Auth::user()?->can($permissao);
    }

    private function autoriza(): void
    {
        abort_unless($this->podeAdministrar(), 403);
    }

    /**
     * Primeiros e últimos caracteres só para dar confirmação visual de qual chave
     * está gravada — nunca o suficiente para reconstruir o segredo.
     */
    private function mascara(string $chave): string
    {
        if (mb_strlen($chave) <= 12) {
            return str_repeat('•', mb_strlen($chave));
        }

        return mb_substr($chave, 0, 8).str_repeat('•', 8).mb_substr($chave, -4);
    }
}
