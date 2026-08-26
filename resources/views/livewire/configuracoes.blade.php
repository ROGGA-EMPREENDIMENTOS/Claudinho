@php($chave = $this->chaveEmUso())
@php($api = $this->situacaoApi())
@php($glossario = $this->glossarioEmUso())

{{-- style="display:none" em vez de x-cloak: o x-show do Alpine remove a propriedade
     ao abrir, e assim o modal não pisca antes do Alpine carregar nem depende de
     nenhuma regra de CSS da aplicação. --}}
{{-- .window é necessário: a engrenagem vive no card, que é irmão deste modal, então
     o evento não passa por aqui ao subir. O preço é que TODO modal da página escuta,
     e por isso a comparação de dono abaixo — sem ela, dois chats na mesma página
     abriam dois modais empilhados. --}}
<div x-data="{ aberto: false, salvo: false }"
    x-on:claudinho-abrir-configuracoes.window="if (! $event.detail?.dono || $event.detail.dono === @js($dono)) aberto = true"
    x-on:claudinho-configuracoes-salvas="salvo = true; setTimeout(() => salvo = false, 2500)"
    x-on:keydown.escape.window="aberto = false">

    <div style="display: none" x-show="aberto" class="fixed inset-0 z-50 flex items-center justify-center p-4"
        role="dialog" aria-modal="true" aria-labelledby="claudinho-configuracoes-titulo">

        <div class="absolute inset-0 bg-gray-900/50" x-on:click="aberto = false" aria-hidden="true"></div>

        {{-- max-w-2xl e não lg: o glossário é texto corrido de várias linhas por item, e
             numa coluna estreita cada regra vira um parágrafo alto demais para comparar
             com a de baixo. --}}
        {{-- max-h + overflow no corpo: o modal cresceu com os canais e a documentação,
             e sem isto os botões saem da tela em notebook. --}}
        <div
            class="relative flex flex-col w-full max-w-2xl overflow-hidden bg-white border border-gray-200 rounded-lg shadow-xl max-h-[90vh] dark:bg-gray-900 dark:border-gray-800">
            <section class="flex items-center justify-between gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-800">
                <h2 id="claudinho-configuracoes-titulo" class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    Configurações do Claudinho
                </h2>

                <button type="button" x-on:click="aberto = false" title="Fechar" aria-label="Fechar"
                    class="inline-flex items-center justify-center w-8 h-8 text-gray-500 transition rounded-md shrink-0 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </section>

            {{-- Fora das abas de propósito: é a única condição em que o chat está
                 quebrado, e escondê-la atrás de uma aba que ninguém abriu seria deixar
                 de avisar justamente quem ainda não configurou nada. --}}
            @if ($chave['origem'] === 'ausente')
                <p class="px-4 py-2 text-xs border-b text-amber-800 border-amber-200 bg-amber-50 dark:text-amber-200 dark:border-amber-800/60 dark:bg-amber-950/40">
                    Nenhuma chave configurada — o chat carrega, mas falha na primeira pergunta.

                    <button type="button" wire:click="$set('aba', 'conexao')"
                        class="font-medium underline underline-offset-2">Definir agora</button>
                </p>
            @endif

            {{-- Abas no servidor: toda ação do glossário volta ao Livewire, e com a aba
                 só no Alpine cada regra salva devolveria o usuário para a primeira. --}}
            <nav class="flex gap-1 px-3 border-b border-gray-100 dark:border-gray-800" aria-label="Seções">
                @foreach (['assistente' => 'Assistente', 'conexao' => 'Modelo e chave', 'canais' => 'Canais'] as $id => $rotulo)
                    <button type="button" wire:click="$set('aba', '{{ $id }}')"
                        aria-current="{{ $aba === $id ? 'page' : 'false' }}"
                        class="px-3 py-2 -mb-px text-sm font-medium transition border-b-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 rounded-t
                            {{ $aba === $id
                                ? 'border-sky-600 text-sky-700 dark:border-sky-400 dark:text-sky-400'
                                : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' }}">
                        {{ $rotulo }}
                    </button>
                @endforeach
            </nav>

            <form wire:submit="salvar" class="flex flex-col min-h-0 gap-4 p-4 overflow-y-auto">

                @if ($aba === 'assistente')
                    <section class="flex flex-col gap-1.5">
                        <label for="claudinho-contexto" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Contexto
                        </label>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Quem é o assistente nesta aplicação. Abre o system prompt, antes de tudo o que o
                            pacote impõe.
                        </span>

                        {{-- text-base no mobile pelo mesmo motivo do campo de pergunta: abaixo
                             de 16px o Safari do iOS dá zoom ao focar e não desfaz. --}}
                        <textarea wire:model="contexto" id="claudinho-contexto" rows="3"
                            class="w-full text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700"></textarea>

                        @error('contexto')
                            <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                        @enderror

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            @if ($this->contextoVemDaTela())
                                Definido aqui. Esvaziar e salvar volta ao texto do <span class="font-mono">config</span>.
                            @else
                                Vindo do <span class="font-mono">config/claudinho.php</span>. Editar aqui passa a
                                valer no lugar dele.
                            @endif
                            Vale no botão <em>Salvar</em>, no rodapé.
                        </span>
                    </section>

                    <hr class="border-gray-100 dark:border-gray-800">

                    <section class="flex flex-col gap-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Glossário de negócio</h3>

                            @if ($glossario['cadastradas'] > 0)
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $glossario['ativas'] }} de {{ $glossario['cadastradas'] }}
                                    {{ $glossario['cadastradas'] === 1 ? 'regra ativa' : 'regras ativas' }}
                                </span>
                            @endif
                        </div>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Uma regra por item: o que não está no schema e o modelo não tem como adivinhar. É aqui
                            que o assistente aprende — ele não guarda nada de uma conversa para a outra.
                            <strong class="font-medium">As regras salvam na hora</strong>, sem passar pelo botão do
                            rodapé.
                        </span>

                        @if (! $this->tabelaDoGlossario())
                            <p class="p-2.5 text-xs border rounded-md text-amber-800 border-amber-300 bg-amber-50 dark:text-amber-200 dark:border-amber-700/60 dark:bg-amber-950/40">
                                Falta rodar <span class="font-mono">php artisan migrate</span> (tabela
                                <span class="font-mono">claudinho_glossario</span>). Até lá o glossário do
                                <span class="font-mono">config</span> continua valendo, mas não dá para editá-lo aqui.
                            </p>
                        @else
                            {{-- Dizer em voz alta de onde vem o glossário em uso: com regras
                                 cadastradas o config para de valer inteiro, e quem escreveu
                                 aquele arquivo merece saber antes de procurar a regra sumida. --}}
                            @if ($glossario['origem'] === 'config')
                                <div
                                    class="flex flex-col gap-1.5 p-2.5 text-xs border rounded-md border-sky-200 bg-sky-50 dark:border-sky-800/60 dark:bg-sky-950/40">
                                    <span class="text-sky-900 dark:text-sky-200">
                                        As {{ $glossario['no_config'] }} regras em uso vêm do
                                        <span class="font-mono">config/claudinho.php</span>. Importe para editá-las
                                        aqui — a partir da primeira regra cadastrada, o arquivo deixa de valer.
                                    </span>

                                    <button type="button" wire:click="importarDoConfig"
                                        wire:confirm="Copiar as regras do config para cá? Depois disso o glossário do arquivo deixa de ser usado, e quem manda é esta tela."
                                        class="self-start font-medium underline text-sky-700 underline-offset-2 hover:text-sky-900 dark:text-sky-400 dark:hover:text-sky-300">
                                        Importar as {{ $glossario['no_config'] }} do config
                                    </button>
                                </div>
                            @elseif ($glossario['importaveis'] > 0)
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span>
                                        O <span class="font-mono">config</span> tem {{ $glossario['importaveis'] }}
                                        {{ $glossario['importaveis'] === 1 ? 'regra que não está' : 'regras que não estão' }}
                                        aqui e por isso não {{ $glossario['importaveis'] === 1 ? 'está' : 'estão' }} em uso.
                                    </span>

                                    <button type="button" wire:click="importarDoConfig"
                                        class="font-medium underline text-sky-700 underline-offset-2 hover:text-sky-900 dark:text-sky-400 dark:hover:text-sky-300">
                                        Importar
                                    </button>
                                </div>
                            @endif

                            {{-- datalist e não select: o assunto é texto livre (criar um
                                 novo não pode exigir tela de cadastro), mas reaproveitar o
                                 que já existe tem de ser um clique — senão "OBRA" e "OBRAS"
                                 viram dois grupos na primeira semana. --}}
                            <datalist id="claudinho-temas">
                                @foreach ($this->temas() as $nome => $quantas)
                                    @if ($nome !== '')
                                        <option value="{{ $nome }}"></option>
                                    @endif
                                @endforeach
                            </datalist>

                            <div class="flex flex-col gap-1.5">
                                <label for="claudinho-regra-nova" class="sr-only">Nova regra</label>

                                <textarea wire:model="regraNova" id="claudinho-regra-nova" rows="2"
                                    placeholder="Ex.: users.obra_scoped vazio significa acesso a todas as obras."
                                    class="w-full text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500"></textarea>

                                @error('regraNova')
                                    <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                                @enderror

                                <div class="flex flex-wrap items-center gap-2">
                                    <label for="claudinho-tema-novo" class="sr-only">Assunto</label>

                                    <input wire:model="temaNovo" id="claudinho-tema-novo" list="claudinho-temas"
                                        placeholder="Assunto (OBRAS, PPC...)" autocomplete="off"
                                        class="w-48 text-base uppercase border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500">

                                    <button type="button" wire:click="adicionarRegra"
                                        class="px-3 py-1.5 text-sm font-medium text-white transition rounded-md bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1 disabled:opacity-50 dark:focus:ring-offset-gray-900">
                                        <span wire:loading.remove wire:target="adicionarRegra">Adicionar regra</span>
                                        <span wire:loading wire:target="adicionarRegra">Adicionando</span>
                                    </button>
                                </div>

                                @error('temaNovo')
                                    <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                                @enderror
                            </div>

                            @php($temas = $this->temas())

                            @if ($glossario['cadastradas'] > 5)
                                <input wire:model.live.debounce.300ms="busca" type="search"
                                    placeholder="Procurar em todas as regras" aria-label="Procurar em todas as regras"
                                    class="w-full text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500">
                            @endif

                            {{-- O índice de assuntos é a tela padrão do glossário. Escolher
                                 um assunto é como a pergunta nasce ("mostre o glossário de
                                 PPC"), e abrir as dezenas de regras de uma vez mandaria
                                 ~200 KB de HTML em toda ação do Livewire. --}}
                            @if (count($temas) > 1)
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($temas as $nome => $quantas)
                                        <button type="button"
                                            wire:click="$set('tema', {{ $tema === $nome ? 'null' : \Illuminate\Support\Js::from($nome) }})"
                                            aria-pressed="{{ $tema === $nome ? 'true' : 'false' }}"
                                            title="{{ $tema === $nome ? 'Clique de novo para voltar aos assuntos' : 'Ver as regras deste assunto' }}"
                                            class="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium transition border rounded-full {{ $tema === $nome ? 'border-sky-600 bg-sky-50 text-sky-800 dark:border-sky-500 dark:bg-sky-950 dark:text-sky-300' : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800' }}">
                                            {{ $nome === '' ? 'Sem assunto' : $nome }}
                                            <span class="opacity-60">{{ $quantas }}</span>
                                        </button>
                                    @endforeach
                                </div>

                                @if (! $this->mostrandoRegras())
                                    <p class="pb-1 text-xs text-gray-500 dark:text-gray-400">
                                        Escolha um assunto para ver e editar as regras dele, ou procure acima.
                                    </p>
                                @endif
                            @endif

                            @php($grupos = $this->mostrandoRegras() ? $this->regrasPorTema() : [])

                            @if ($grupos === [] && $this->mostrandoRegras())
                                <p class="py-3 text-xs text-center text-gray-500 dark:text-gray-400">
                                    @if (filled($busca))
                                        Nenhuma regra com esse trecho.
                                    @elseif ($glossario['cadastradas'] > 0)
                                        Nenhuma regra neste assunto.
                                    @else
                                        Nenhuma regra cadastrada. Enquanto não houver nenhuma, vale o glossário do
                                        <span class="font-mono">config</span>.
                                    @endif
                                </p>
                            @elseif ($grupos !== [])
                                @foreach ($grupos as $nomeDoTema => $regrasDoTema)
                                    {{-- O título do grupo é o mesmo que vai para o system
                                         prompt: o que se vê aqui é o que o modelo lê. --}}
                                    <h4 wire:key="tema-{{ $nomeDoTema ?: 'sem' }}"
                                        class="flex items-baseline gap-2 pt-1 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                                        {{ $nomeDoTema === '' ? 'Sem assunto' : $nomeDoTema }}
                                        <span class="font-normal normal-case text-gray-400 dark:text-gray-600">
                                            {{ count($regrasDoTema) }}
                                        </span>
                                    </h4>

                                    <ul class="flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
                                        @foreach ($regrasDoTema as $regra)
                                            <li wire:key="regra-{{ $regra->id }}" class="flex flex-col gap-1.5 py-2.5">
                                                @if ($emEdicao === $regra->id)
                                                    <textarea wire:model="textoEmEdicao" rows="4" aria-label="Editar regra"
                                                        class="w-full text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700"></textarea>

                                                    @error('textoEmEdicao')
                                                        <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                                                    @enderror

                                                    <div class="flex items-center gap-2">
                                                        <label for="claudinho-tema-{{ $regra->id }}" class="sr-only">Assunto</label>

                                                        {{-- Reclassificar é editar: mudar o assunto aqui é como uma
                                                             regra sai de "Sem assunto" e entra em PPC. --}}
                                                        <input wire:model="temaEmEdicao" id="claudinho-tema-{{ $regra->id }}"
                                                            list="claudinho-temas" placeholder="Assunto" autocomplete="off"
                                                            class="w-40 text-base uppercase border-gray-300 rounded-md sm:text-xs focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500">

                                                        <button type="button" wire:click="salvarRegra"
                                                            class="px-3 py-1 text-xs font-medium text-white transition rounded-md bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500">
                                                            Salvar regra
                                                        </button>

                                                        <button type="button" wire:click="cancelarEdicao"
                                                            class="px-2 py-1 text-xs font-medium text-gray-600 transition rounded-md hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800">
                                                            Cancelar
                                                        </button>
                                                    </div>
                                                @else
                                                    <div class="flex items-start justify-between gap-3">
                                                        {{-- Clicar no texto abre por inteiro: com dezenas de regras longas,
                                                             a lista sem corte vira rolagem sem fim, e abrir no Alpine não
                                                             custa requisição. --}}
                                                        <p x-data="{ inteiro: false }" x-on:click="inteiro = ! inteiro"
                                                            x-bind:class="inteiro || 'line-clamp-2'"
                                                            title="Clique para ver a regra inteira"
                                                            class="text-sm cursor-pointer {{ $regra->ativo ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 line-through dark:text-gray-600' }}">
                                                            {{ $regra->regra }}
                                                        </p>

                                                        <div class="flex items-center gap-1 shrink-0">
                                                            <button type="button" wire:click="alternarRegra({{ $regra->id }})"
                                                                title="{{ $regra->ativo ? 'Desativar (sai do prompt, continua aqui)' : 'Ativar' }}"
                                                                class="px-1.5 py-1 text-xs font-medium transition rounded {{ $regra->ativo ? 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' : 'text-sky-700 hover:bg-sky-50 dark:text-sky-400 dark:hover:bg-sky-950' }}">
                                                                {{ $regra->ativo ? 'Desativar' : 'Ativar' }}
                                                            </button>

                                                            <button type="button" wire:click="editarRegra({{ $regra->id }})"
                                                                class="px-1.5 py-1 text-xs font-medium text-gray-500 transition rounded hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800">
                                                                Editar
                                                            </button>

                                                            <button type="button" wire:click="removerRegra({{ $regra->id }})"
                                                                wire:confirm="Remover esta regra? Para tirá-la do prompt sem perder o texto, use Desativar."
                                                                class="px-1.5 py-1 text-xs font-medium text-gray-500 transition rounded hover:bg-red-50 hover:text-red-700 dark:text-gray-400 dark:hover:bg-red-950/50 dark:hover:text-red-400">
                                                                Remover
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <span class="text-[11px] text-gray-400 dark:text-gray-600">
                                                        @unless ($regra->ativo)
                                                            <span class="font-medium text-amber-700 dark:text-amber-500">Desativada</span> ·
                                                        @endunless
                                                        {{ filled($regra->autor) ? $regra->autor.' · ' : '' }}{{ $regra->updated_at?->format('d/m/Y') }}
                                                    </span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endforeach
                            @endif
                        @endif
                    </section>
                @endif

                @if ($aba === 'conexao')
                    <section class="flex flex-col gap-1.5">
                        <label for="claudinho-modelo" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Modelo
                        </label>

                        {{-- text-base no mobile pelo mesmo motivo do campo de pergunta: abaixo
                             de 16px o Safari do iOS dá zoom ao focar e não desfaz. --}}
                        <select wire:model="modelo" id="claudinho-modelo"
                            class="w-full text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700">
                            @foreach ($this->modelosDisponiveis() as $id => $descricao)
                                <option value="{{ $id }}">{{ $descricao }}</option>
                            @endforeach
                        </select>

                        @error('modelo')
                            <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                        @enderror
                    </section>

                    <section class="flex flex-col gap-1.5">
                        <label for="claudinho-chave" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Chave da API
                        </label>

                        <input wire:model="chaveNova" type="password" id="claudinho-chave" autocomplete="off"
                            placeholder="{{ $chave['origem'] === 'ausente' ? 'sk-ant-api03-...' : 'Deixe em branco para manter a atual' }}"
                            class="w-full font-mono text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500">

                        @error('chaveNova')
                            <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                        @enderror

                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($chave['origem'] === 'tela')
                                <span>Em uso, definida aqui: <span class="font-mono">{{ $chave['dica'] }}</span></span>

                                <button type="button" wire:click="limparChave"
                                    wire:confirm="Limpar a chave definida em tela? O sistema volta a usar a do .env, se houver."
                                    class="font-medium underline text-sky-700 underline-offset-2 hover:text-sky-900 dark:text-sky-400 dark:hover:text-sky-300">
                                    Limpar
                                </button>
                            @elseif ($chave['origem'] === 'env')
                                <span>Em uso, vinda do <span class="font-mono">.env</span>:
                                    <span class="font-mono">{{ $chave['dica'] }}</span>. Preencher acima passa a valer no
                                    lugar dela.</span>
                            @else
                                {{-- O aviso completo está no alto do modal, fora das abas. --}}
                                <span class="text-amber-700 dark:text-amber-500">Sem chave gravada aqui nem no
                                    <span class="font-mono">.env</span>.</span>
                            @endif
                        </span>
                    </section>
                @endif

                @if ($aba === 'canais')
                    <section class="flex flex-col gap-3">
                        <label class="flex items-start gap-2.5 cursor-pointer">
                            <input wire:model="flutuante" type="checkbox"
                                class="mt-0.5 rounded border-gray-300 text-sky-600 shrink-0 focus:ring-sky-500 dark:bg-gray-800 dark:border-gray-700">

                            <span class="flex flex-col gap-0.5">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Botão flutuante do chat</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    Some o botão do canto sem tirar o componente do layout. Não afeta o chat que a
                                    aplicação colocou dentro de uma página.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-2.5 cursor-pointer">
                            <input wire:model="api" type="checkbox"
                                class="mt-0.5 rounded border-gray-300 text-sky-600 shrink-0 focus:ring-sky-500 dark:bg-gray-800 dark:border-gray-700">

                            <span class="flex flex-col gap-0.5">
                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                    Atendimento pela API (WhatsApp e outros canais)
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    Desligado, o endpoint responde 503 e nenhuma conversa externa é atendida.
                                    Precisa de token — e de um resolvedor de usuário, que é código.
                                </span>
                            </span>
                        </label>
                    </section>

                    <section class="flex flex-col gap-1.5">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Token do chamador</span>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Autentica o gateway que fala com o endpoint — não é a chave do Claude nem a senha de
                            ninguém. É por ele que passa o <span class="font-mono">Authorization: Bearer</span>.
                        </span>

                        @if (filled($tokenGerado))
                            {{-- Aparece uma vez só. Ao contrário da chave do Claude, este segredo
                                 precisa ser lido: quem opera tem de copiá-lo para o gateway. --}}
                            <div
                                class="flex flex-col gap-1.5 p-2.5 border rounded-md border-amber-300 bg-amber-50 dark:border-amber-700/60 dark:bg-amber-950/40">
                                <span class="text-xs font-medium text-amber-800 dark:text-amber-200">
                                    Copie agora — este token não será mostrado de novo.
                                </span>

                                <code x-data="{ copiado: false }"
                                    x-on:click="navigator.clipboard?.writeText($refs.token.textContent.trim());
                                                copiado = true; setTimeout(() => copiado = false, 2000)"
                                    class="flex items-center justify-between gap-2 px-2 py-1.5 font-mono text-xs break-all bg-white border rounded cursor-pointer border-amber-200 text-amber-900 dark:bg-gray-900 dark:border-amber-800 dark:text-amber-100">
                                    <span x-ref="token">{{ $tokenGerado }}</span>

                                    <span x-text="copiado ? 'copiado' : 'copiar'"
                                        class="shrink-0 text-[10px] uppercase tracking-wide opacity-60"></span>
                                </code>
                            </div>
                        @endif

                        @php($token = $this->tokenEmUso())

                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($token['origem'] === 'tela')
                                <span>Em uso, gerado aqui: <span class="font-mono">{{ $token['dica'] }}</span></span>

                                <button type="button" wire:click="gerarToken"
                                    wire:confirm="Gerar um token novo? O atual para de funcionar na hora, e o gateway precisa ser atualizado."
                                    class="font-medium underline text-sky-700 underline-offset-2 hover:text-sky-900 dark:text-sky-400 dark:hover:text-sky-300">
                                    Gerar outro
                                </button>

                                <button type="button" wire:click="revogarToken"
                                    wire:confirm="Revogar o token? O endpoint para de atender até haver outro."
                                    class="font-medium underline text-sky-700 underline-offset-2 hover:text-sky-900 dark:text-sky-400 dark:hover:text-sky-300">
                                    Revogar
                                </button>
                            @elseif ($token['origem'] === 'env')
                                <span>Em uso, vindo do <span class="font-mono">.env</span>:
                                    <span class="font-mono">{{ $token['dica'] }}</span>.</span>

                                <button type="button" wire:click="gerarToken"
                                    wire:confirm="Gerar um token aqui? Ele passa a valer no lugar do que está no .env."
                                    class="font-medium underline text-sky-700 underline-offset-2 hover:text-sky-900 dark:text-sky-400 dark:hover:text-sky-300">
                                    Gerar um aqui
                                </button>
                            @else
                                <span class="text-amber-700 dark:text-amber-500">Nenhum token — o endpoint responde 503.</span>

                                <button type="button" wire:click="gerarToken"
                                    class="font-medium underline text-sky-700 underline-offset-2 hover:text-sky-900 dark:text-sky-400 dark:hover:text-sky-300">
                                    Gerar token
                                </button>
                            @endif
                        </span>
                    </section>

                    <hr class="border-gray-100 dark:border-gray-800">

                    @php($regras = $this->regrasDoCanal())
                    @php($padroes = $this->padroesDoCanal())
                    @php($midias = $this->midiasEmUso())

                    <section class="flex flex-col gap-4">
                        <div class="flex flex-col gap-0.5">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Regras do canal externo</h3>

                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Valem só nas conversas do endpoint — o chat em tela não passa por nenhuma delas.
                                <strong class="font-medium">Campo vazio usa o
                                    <span class="font-mono">config/claudinho.php</span></strong>, que é como se desfaz
                                uma edição sem precisar lembrar o valor de lá. Tudo aqui vale no botão <em>Salvar</em>,
                                no rodapé.
                            </span>
                        </div>

                        <label class="flex items-start gap-2.5 cursor-pointer">
                            <input wire:model="acoes" type="checkbox"
                                class="mt-0.5 rounded border-gray-300 text-sky-600 shrink-0 focus:ring-sky-500 dark:bg-gray-800 dark:border-gray-700">

                            <span class="flex flex-col gap-0.5">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Alterações de dados neste canal</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    Desligado, o canal fica somente-leitura sem desregistrar as ações, que continuam
                                    valendo no chat em tela. Ligado, toda alteração pede confirmação por escrito.
                                </span>
                            </span>
                        </label>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="flex flex-col gap-1.5">
                                <label for="claudinho-inatividade" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Conversa nova após
                                </label>

                                <div class="flex items-center gap-2">
                                    <input wire:model="minutosInatividade" id="claudinho-inatividade" type="number"
                                        min="1" max="1440" inputmode="numeric" placeholder="{{ $padroes['minutos_inatividade'] }}"
                                        class="w-24 text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700">

                                    <span class="text-xs text-gray-500 dark:text-gray-400">minutos de silêncio</span>
                                </div>

                                @error('minutosInatividade')
                                    <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                                @enderror

                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    Em uso: {{ $regras['minutos_inatividade'] }} min,
                                    {{ $this->gravadoEmTela('api_minutos_inatividade') ? 'definido aqui' : 'vindo do config' }}.
                                    O histórico anterior deixa de ser enviado ao modelo.
                                </span>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label for="claudinho-confirmacao" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Prazo da confirmação
                                </label>

                                <div class="flex items-center gap-2">
                                    <input wire:model="minutosConfirmacao" id="claudinho-confirmacao" type="number"
                                        min="1" max="1440" inputmode="numeric" placeholder="{{ $padroes['minutos_confirmacao'] }}"
                                        class="w-24 text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700">

                                    <span class="text-xs text-gray-500 dark:text-gray-400">minutos</span>
                                </div>

                                @error('minutosConfirmacao')
                                    <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                                @enderror

                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    Em uso: {{ $regras['minutos_confirmacao'] }} min,
                                    {{ $this->gravadoEmTela('api_minutos_confirmacao') ? 'definido aqui' : 'vindo do config' }}.
                                    Mais curto que o da conversa de propósito: um "sim" solto tempo depois não pode
                                    autorizar alteração já esquecida.
                                </span>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="claudinho-palavras" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Palavras que confirmam
                            </label>

                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Uma por linha, ou separadas por vírgula. A palavra tem de vir sozinha: o casamento é
                                exato, sem acento e sem pontuação — <span class="font-mono">Sim!</span> aprova,
                                <span class="font-mono">sim, pode cancelar</span> não. Qualquer resposta que não casa
                                cancela a alteração, porque pendência viva esperaria um "sim" que pode chegar em outro
                                assunto.
                            </span>

                            <textarea wire:model="palavras" id="claudinho-palavras" rows="2"
                                placeholder="{{ $padroes['palavras'] }}"
                                class="w-full text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700"></textarea>

                            @error('palavras')
                                <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                            @enderror

                            @if ($regras['palavras'] === [])
                                {{-- Lista vazia não aprova nada, por decisão do Confirmacao: melhor cancelar
                                     tudo do que deixar qualquer texto autorizar escrita. Só que, visto do
                                     WhatsApp, isso parece o assistente ignorando o "sim". --}}
                                <span class="text-xs text-amber-700 dark:text-amber-500">
                                    Nenhuma palavra em uso — toda alteração vai ser cancelada, porque não há resposta
                                    capaz de aprová-la.
                                </span>
                            @else
                                <span class="flex flex-wrap items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="mr-0.5">
                                        Em uso, {{ $this->gravadoEmTela('api_palavras_confirmacao') ? 'definidas aqui' : 'vindas do config' }}:
                                    </span>

                                    @foreach ($regras['palavras'] as $palavra)
                                        <span class="px-1.5 py-0.5 font-mono text-gray-700 rounded bg-gray-100 dark:bg-gray-800 dark:text-gray-300">{{ $palavra }}</span>
                                    @endforeach
                                </span>
                            @endif
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="claudinho-instrucoes" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Instruções deste canal
                            </label>

                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Acrescentadas ao fim do system prompt, só nas conversas do endpoint. É onde se desfaz
                                o que a tela faz bem e o aplicativo de mensagens não desenha: tabela, título, bloco de
                                código.
                            </span>

                            <textarea wire:model="instrucoes" id="claudinho-instrucoes" rows="3"
                                placeholder="{{ $padroes['instrucoes'] }}"
                                class="w-full text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700"></textarea>

                            @error('instrucoes')
                                <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                            @enderror

                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($regras['instrucoes'] === '')
                                    Nenhuma instrução em uso — o assistente responde formatando como no chat em tela.
                                @else
                                    {{ $this->gravadoEmTela('api_instrucoes') ? 'Definidas aqui.' : 'Vindas do config.' }}
                                @endif
                            </span>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="claudinho-hosts" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Hosts de mídia
                            </label>

                            {{-- O item de segurança da aba: a URI da foto vem DENTRO da mensagem, então
                                 qualquer um digita um endereço da rede interna no WhatsApp e o gateway
                                 repassa como texto. Lista preenchida é a barreira; vazia é postura de
                                 desenvolvimento, e quem opera precisa saber em qual das duas está. --}}
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Um por linha. De onde este servidor aceita baixar foto e vídeo — a URI vem dentro da
                                mensagem, ou seja, de fora. Pode colar a URI inteira: fica só o host.
                            </span>

                            <textarea wire:model="hostsDeMidia" id="claudinho-hosts" rows="2"
                                placeholder="{{ $padroes['hosts'] }}"
                                class="w-full font-mono text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700"></textarea>

                            @error('hostsDeMidia')
                                <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                            @enderror

                            @if ($midias['hosts'] === [])
                                <span class="text-xs text-amber-700 dark:text-amber-500">
                                    Sem lista: aceita baixar de qualquer endereço público que chegar na mensagem. A
                                    rede interna segue barrada e redirecionamento não é seguido, mas em produção
                                    preencha com o host do gateway.
                                </span>
                            @else
                                <span class="flex flex-wrap items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="mr-0.5">
                                        Em uso, {{ $this->gravadoEmTela('api_midias_hosts') ? 'definidos aqui' : 'vindos do config' }} —
                                        só destes:
                                    </span>

                                    @foreach ($midias['hosts'] as $host)
                                        <span class="px-1.5 py-0.5 font-mono text-gray-700 rounded bg-gray-100 dark:bg-gray-800 dark:text-gray-300">{{ $host }}</span>
                                    @endforeach
                                </span>
                            @endif

                            {{-- O resto da mídia continua no arquivo: tipo aceito e destino do arquivo são
                                 decisão de código (o destino é uma CLASSE), e ligar a funcionalidade faz o
                                 servidor baixar arquivo e pagar visão por imagem. Mas quem cadastra host
                                 precisa ver, ali do lado, se a mídia está sequer ligada. --}}
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($midias['habilitado'])
                                    Foto e vídeo ligados: até {{ $midias['max_por_mensagem'] }} por mensagem,
                                    {{ $midias['max_mb'] }} MB cada, {{ $midias['timeout'] }}s de download.
                                    {{ count($midias['tipos']) }}
                                    {{ count($midias['tipos']) === 1 ? 'tipo aceito' : 'tipos aceitos' }}
                                    ({{ implode(', ', $midias['tipos']) }}).
                                    @if ($midias['destino'])
                                        O arquivo vai para <span class="font-mono">{{ $midias['destino'] }}</span>.
                                    @else
                                        Sem destino configurado: a imagem é descrita e a descrição entra na conversa,
                                        mas o arquivo se perde.
                                    @endif
                                @else
                                    Foto e vídeo estão <strong class="font-medium">desligados</strong> em
                                    <span class="font-mono">api.midias.habilitado</span>, e estes hosts só passam a
                                    valer quando ligar. Ligar é decisão de quem instala: o servidor passa a baixar
                                    arquivo de endereço que vem na mensagem e a pagar uma chamada de visão por imagem.
                                @endif
                            </span>
                        </div>
                    </section>

                    <hr class="border-gray-100 dark:border-gray-800">

                    @php($voz = $this->transcricaoEmUso())
                    @php($chaveDeVoz = $this->chaveDeTranscricaoEmUso())

                    <section class="flex flex-col gap-3">
                        <div class="flex flex-col gap-0.5">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Transcrição de áudio
                            </h3>

                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                O áudio que chega pelo canal externo vira texto pela API do
                                <strong class="font-medium">Google Speech-to-Text</strong>, e é o texto que entra na
                                conversa — o Claude lê, mas não ouve. Não depende de foto e vídeo: são dois
                                interruptores independentes. Vale no botão <em>Salvar</em>, no rodapé.
                            </span>
                        </div>

                        <label class="flex items-start gap-2.5 cursor-pointer">
                            <input wire:model="transcricao" type="checkbox"
                                class="mt-0.5 rounded border-gray-300 text-sky-600 shrink-0 focus:ring-sky-500 dark:bg-gray-800 dark:border-gray-700">

                            <span class="flex flex-col gap-0.5">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Transcrever áudio recebido</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    Ligar manda o áudio de quem está conversando para um serviço de terceiro, fora da
                                    Anthropic, e cada minuto é cobrado pelo Google. Desligado, o assistente responde
                                    que não escuta áudio e pede o recado por escrito — sem baixar o arquivo.
                                </span>
                            </span>
                        </label>

                        <div class="flex flex-col gap-1.5">
                            <label for="claudinho-chave-voz" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Chave da API do Google
                            </label>

                            <input wire:model="chaveDeTranscricaoNova" type="password" id="claudinho-chave-voz"
                                autocomplete="off"
                                placeholder="{{ $chaveDeVoz['origem'] === 'ausente' ? 'AIza...' : 'Deixe em branco para manter a atual' }}"
                                class="w-full font-mono text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500">

                            @error('chaveDeTranscricaoNova')
                                <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                            @enderror

                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                @if ($chaveDeVoz['origem'] === 'tela')
                                    <span>Em uso, definida aqui:
                                        <span class="font-mono">{{ $chaveDeVoz['dica'] }}</span></span>

                                    <button type="button" wire:click="limparChaveDeTranscricao"
                                        wire:confirm="Limpar a chave do Google definida em tela? O sistema volta a usar a do .env, se houver."
                                        class="font-medium underline text-sky-700 underline-offset-2 hover:text-sky-900 dark:text-sky-400 dark:hover:text-sky-300">
                                        Limpar
                                    </button>
                                @elseif ($chaveDeVoz['origem'] === 'env')
                                    <span>Em uso, vinda do <span class="font-mono">.env</span>:
                                        <span class="font-mono">{{ $chaveDeVoz['dica'] }}</span>. Preencher acima passa a
                                        valer no lugar dela.</span>
                                @else
                                    <span>Nenhuma chave gravada aqui nem em
                                        <span class="font-mono">GOOGLE_SPEECH_API_KEY</span>. É credencial de projeto do
                                        Google Cloud, com a Speech-to-Text liberada — restrinja por IP e por API no
                                        console.</span>
                                @endif
                            </span>
                        </div>

                        {{-- Ligado sem chave não é "meio ligado": é desligado com aparência de
                             ligado. Sem esta linha, o áudio falharia em silêncio, um a um, e o
                             motivo só apareceria no log de quem tivesse acesso a ele. --}}
                        <span class="text-xs {{ $voz['habilitada'] && ! $voz['ativa'] ? 'text-amber-700 dark:text-amber-500' : 'text-gray-500 dark:text-gray-400' }}">
                            @if ($voz['habilitada'] && ! $voz['ativa'])
                                <strong class="font-medium">Ligada, mas sem chave</strong> — nenhum áudio vai ser
                                transcrito enquanto ela faltar.
                            @elseif ($voz['ativa'])
                                Em uso, {{ $voz['gravada_em_tela'] ? 'ligada aqui' : 'ligada pelo config' }}. Idioma
                                esperado: <span class="font-mono">{{ $voz['idioma'] }}</span>, de
                                <span class="font-mono">transcricao.idioma</span> — é decisão de instalação, e por isso
                                não tem campo aqui.
                            @else
                                Desligada, {{ $voz['gravada_em_tela'] ? 'aqui' : 'pelo config' }}. A chave pode ficar
                                gravada mesmo assim: ela só é usada quando o interruptor estiver ligado.
                            @endif
                        </span>

                        {{-- O teto de um minuto não é escolha nossa: é o do reconhecimento
                             SÍNCRONO da API, e o assíncrono exigiria o arquivo num bucket do
                             Cloud Storage — ou seja, obrigaria a aplicação a ter um. Quem opera
                             precisa saber disso antes de investigar "por que o áudio comprido
                             não funciona". --}}
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Até <strong class="font-medium">um minuto</strong> por áudio: acima disso o assistente
                            avisa e pede um mais curto, em vez de mandar reenviar o que seria recusado de novo.
                            Aceita gravação de voz do WhatsApp (Opus), MP3, AMR, WAV e FLAC — os hosts liberados são
                            os mesmos do campo acima.
                        </span>
                    </section>

                    {{-- Documentação embutida em vez de link: mostra a URL real deste ambiente e
                         serve de diagnóstico. Link para arquivo externo não diria o que falta. --}}
                    <section x-data="{ aberta: false }"
                        class="border border-gray-200 rounded-md dark:border-gray-700">
                        <button type="button" x-on:click="aberta = ! aberta" x-bind:aria-expanded="aberta ? 'true' : 'false'"
                            class="flex items-center justify-between w-full gap-2 px-3 py-2 text-sm font-medium text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-sky-500 dark:text-gray-300 dark:hover:bg-gray-800">
                            <span>Documentação da API</span>

                            <svg class="w-4 h-4 transition shrink-0" x-bind:class="aberta && 'rotate-180'" fill="none"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="aberta" style="display: none"
                            class="flex flex-col gap-3 px-3 pt-1 pb-3 text-xs border-t border-gray-100 dark:border-gray-800">

                            <ul class="flex flex-col gap-1">
                                @foreach ($api['itens'] as $item)
                                    <li class="flex items-start gap-1.5 {{ $item['ok'] ? 'text-gray-600 dark:text-gray-400' : 'text-amber-700 dark:text-amber-500' }}">
                                        <span class="font-mono shrink-0" aria-hidden="true">{{ $item['ok'] ? '✓' : '!' }}</span>
                                        <span class="sr-only">{{ $item['ok'] ? 'Pronto:' : 'Pendente:' }}</span>
                                        <span>{{ $item['texto'] }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="flex flex-col gap-1">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">Enviar uma mensagem</span>
                                    <pre class="p-2 overflow-x-auto font-mono text-gray-700 rounded bg-gray-50 dark:bg-gray-800 dark:text-gray-300">curl -X POST {{ $api['url'] }} \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"canal":"whatsapp","identificador":"5547999998888","mensagem":"quantas obras ativas?"}'</pre>
                                </div>

                            <div class="flex flex-col gap-1">
                                <span class="font-medium text-gray-700 dark:text-gray-300">A resposta</span>
                                <pre class="p-2 overflow-x-auto font-mono text-gray-700 rounded bg-gray-50 dark:bg-gray-800 dark:text-gray-300">{
  "resposta": "São 12 obras ativas.",
  "estado": "concluida",
  "confirmacao": null
}</pre>
                                <span class="text-gray-500 dark:text-gray-400">
                                    <span class="font-mono">resposta</span> já vem pronta para reenviar ao canal.
                                    <span class="font-mono">estado</span> é <span class="font-mono">concluida</span>,
                                    <span class="font-mono">aguardando_confirmacao</span> ou
                                    <span class="font-mono">erro</span>.
                                </span>
                            </div>

                            <p class="text-gray-500 dark:text-gray-400">
                                Alteração de dados pede confirmação por escrito: o endpoint devolve
                                <span class="font-mono">aguardando_confirmacao</span> e só as palavras listadas em
                                <em>Regras do canal externo</em> aprovam, sozinhas e por casamento exato. Qualquer
                                outra resposta cancela.
                            </p>

                            <p class="text-gray-500 dark:text-gray-400">
                                Quem decide de qual usuário é a permissão em cada número é o resolvedor da aplicação —
                                gates e escopo por obra valem igual ao chat em tela. O passo a passo completo está no
                                README do pacote, seção <span class="font-mono">Endpoint para canais externos</span>.
                            </p>
                        </div>
                    </section>
                @endif

                <section class="flex items-center justify-end gap-2 pt-1 mt-auto">
                    <span x-show="salvo" style="display: none"
                        class="mr-auto text-xs font-medium text-green-700 dark:text-green-400">
                        Configurações salvas.
                    </span>

                    <button type="button" x-on:click="aberto = false"
                        class="px-3 py-2 text-sm font-medium text-gray-700 transition bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1 dark:text-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">
                        Fechar
                    </button>

                    <button type="submit"
                        class="px-4 py-2 text-sm font-medium text-white transition rounded-md bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:opacity-50 dark:focus:ring-offset-gray-900">
                        <span wire:loading.remove wire:target="salvar">Salvar</span>
                        <span wire:loading wire:target="salvar">Salvando</span>
                    </button>
                </section>
            </form>
        </div>
    </div>
</div>
