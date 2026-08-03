<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
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
    public string $modelo = '';

    /**
     * Só escrita. A chave gravada nunca é carregada aqui — propriedade pública do
     * Livewire vai para o HTML e volta em cada requisição, e o segredo não pode
     * fazer esse caminho. Para exibir, existe o chaveEmUso(), que só devolve máscara.
     */
    public string $chaveNova = '';

    /** O botão flutuante aparece? Só tem efeito onde a aplicação colocou o componente. */
    public bool $flutuante = true;

    /** O endpoint aceita requisições? Só tem efeito onde a rota foi publicada. */
    public bool $api = true;

    public function mount(): void
    {
        $this->autoriza();

        $this->modelo = (string) Configuracao::valor('model', config('claudinho.model'));
        $this->flutuante = Configuracao::booleano('flutuante_ativo', (bool) config('claudinho.flutuante.ativo', true));
        $this->api = Configuracao::booleano('api_ativa', true);
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
     * Situação do endpoint neste ambiente, para a tela não oferecer um interruptor
     * que não liga nada.
     *
     * Os dois níveis são de propósito. Publicar a rota é decisão de deploy
     * (`api.habilitado`), porque criar um endpoint HTTP com acesso a dados não é
     * coisa que se faça por formulário web. Ligar e desligar o atendimento é
     * decisão de operação, e essa sim fica aqui.
     *
     * @return array{publicada: bool, ativa: bool, url: string|null, itens: array<int, array{ok: bool, texto: string}>}
     */
    public function situacaoApi(): array
    {
        $publicada = (bool) config('claudinho.api.habilitado', false);
        $prefixo = trim((string) config('claudinho.api.prefixo', 'claudinho'), '/');
        $resolvedor = (string) config('claudinho.api.resolvedor', '');

        $itens = [
            [
                'ok' => $publicada,
                'texto' => $publicada
                    ? 'Rota publicada neste ambiente.'
                    : 'Rota não publicada: defina CLAUDINHO_API=true no .env.',
            ],
            [
                'ok' => filled(config('claudinho.api.token')),
                'texto' => filled(config('claudinho.api.token'))
                    ? 'Token do chamador configurado.'
                    : 'Sem CLAUDINHO_API_TOKEN: o endpoint responde 503.',
            ],
            [
                'ok' => $resolvedor !== '',
                'texto' => $resolvedor !== ''
                    ? 'Resolvedor de usuário: '.class_basename($resolvedor).'.'
                    : 'Sem resolvedor: o endpoint não sabe de quem é a permissão.',
            ],
            [
                'ok' => $this->tabelaDeConversas(),
                'texto' => $this->tabelaDeConversas()
                    ? 'Tabela de conversas migrada.'
                    : 'Falta rodar php artisan migrate (tabela claudinho_conversas).',
            ],
        ];

        return [
            'publicada' => $publicada,
            'ativa' => Configuracao::booleano('api_ativa', true),
            'url' => $publicada ? url($prefixo.'/conversa') : null,
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
