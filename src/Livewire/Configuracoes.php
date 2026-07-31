<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Rogga\Claudinho\Models\Configuracao;

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

    public function mount(): void
    {
        $this->autoriza();

        $this->modelo = (string) Configuracao::valor('model', config('claudinho.model'));
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
