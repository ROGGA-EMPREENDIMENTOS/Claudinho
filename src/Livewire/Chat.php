<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Rogga\Claudinho\Claude;
use Rogga\Claudinho\FerramentaRegistry;
use Rogga\Claudinho\Grafico\Especificacao;
use Throwable;

class Chat extends Component
{
    /**
     * Conversa no formato da API (blocos de conteúdo, incluindo tool_use/tool_result).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $conversa = [];

    public string $pergunta = '';

    public bool $respondendo = false;

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
        $this->validate([
            'pergunta' => ['required', 'string', 'max:4000'],
        ]);

        $this->conversa[] = [
            'role' => 'user',
            'content' => trim($this->pergunta),
        ];

        $this->pergunta = '';
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
        $iteracao = 0;

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

                $resultados = [];

                foreach ($toolUses as $tool) {
                    $this->stream(to: 'resposta', content: "\n\n[consultando {$tool['nome']}...]\n\n");

                    $resultados[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $tool['id'],
                        'content' => json_encode(
                            $registro->executar($tool['nome'], $tool['input']),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                    ];
                }

                $this->conversa[] = ['role' => 'user', 'content' => $resultados];

                $iteracao++;
            } while ($iteracao < $maxIteracoes);
        } catch (Throwable $th) {
            $this->respondendo = false;
            $this->falhar($th->getMessage());

            return;
        }

        $this->respondendo = false;
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
        $this->pergunta = '';
        $this->respondendo = false;
    }

    /**
     * Achata a conversa para exibição: texto, gráficos e o rótulo das consultas.
     * Blocos tool_result (JSON cru) não vão para a tela.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mensagensVisiveis(): array
    {
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
                    $visiveis[] = [
                        'autor' => 'sistema',
                        'tipo' => 'consulta',
                        'texto' => $this->rotuloConsulta(
                            (string) ($bloco['name'] ?? ''),
                            (array) ($bloco['input'] ?? [])
                        ),
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
     * @param  array<string, mixed>  $input
     */
    private function rotuloConsulta(string $nome, array $input): string
    {
        $argumentos = [];

        foreach ($input as $chave => $valor) {
            if (blank($valor)) {
                continue;
            }

            $argumentos[] = $chave.': '.(is_scalar($valor) ? $valor : json_encode($valor, JSON_UNESCAPED_UNICODE));
        }

        return 'Consultou '.$nome.($argumentos === [] ? '' : ' ('.implode(', ', $argumentos).')');
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

        $partes = [$contexto, "Você está conversando com {$usuario}. Hoje é {$hoje}."];

        $glossario = array_filter((array) config('claudinho.glossario', []));

        if ($glossario !== []) {
            $partes[] = "Regras de negócio desta aplicação:\n- ".implode("\n- ", $glossario);
        }

        $partes[] = <<<'REGRAS'
        Você tem ferramentas de consulta somente-leitura ao banco. Use-as sempre que a
        resposta depender de dados do sistema — não responda de memória e não peça permissão
        para consultar, apenas consulte.

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

        if (config('claudinho.grafico.habilitado', true)) {
            $partes[] = 'Quando o usuário pedir visão gráfica, ou quando a comparação entre categorias '
                .'ficar mais clara visualmente, use gerar_grafico — sempre depois de consultar os dados, '
                .'nunca com números estimados. Você não gera imagens nem código de gráfico: gerar_grafico '
                .'é o único jeito de desenhar, e o desenho aparece na conversa.';
        }

        return implode("\n\n", array_filter($partes));
    }
}
