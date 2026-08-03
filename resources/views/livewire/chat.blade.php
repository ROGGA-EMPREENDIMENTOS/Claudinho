{{-- Ícones em SVG inline de propósito: o pacote não deve exigir blade-heroicons na aplicação. --}}
@php($temaNoDocumento = config('claudinho.tema.alvo', 'componente') === 'documento')

<div x-data="{
    tema: localStorage.getItem('claudinho-tema') || 'sistema',
    sistemaEscuro: window.matchMedia('(prefers-color-scheme: dark)').matches,

    init() {
        // Tema do sistema pode mudar com a página aberta; matchMedia não é reativo.
        window.matchMedia('(prefers-color-scheme: dark)')
            .addEventListener('change', evento => this.sistemaEscuro = evento.matches);
    },

    get escuro() {
        return this.tema === 'escuro' || (this.tema === 'sistema' && this.sistemaEscuro);
    },

    alternar() {
        this.tema = { sistema: 'claro', claro: 'escuro', escuro: 'sistema' }[this.tema];
        localStorage.setItem('claudinho-tema', this.tema);
    },
}"
    {{-- x-effect reage ao getter escuro, que depende de tema e sistemaEscuro. --}}
    x-effect="{{ $temaNoDocumento ? 'document.documentElement' : '$el' }}.classList.toggle('dark', escuro)">
    <script>
        (() => {
            @if ($temaNoDocumento)
                const alvo = document.documentElement;
            @else
                const alvo = document.currentScript.parentElement;
            @endif

            const escuro = () => {
                const escolhido = localStorage.getItem('claudinho-tema') || 'sistema';

                return escolhido === 'escuro'
                    || (escolhido === 'sistema' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            };

            const aplicar = () => alvo.classList.toggle('dark', escuro());

            aplicar();

            // O morph do Livewire ressincroniza os atributos da raiz a partir do HTML do
            // servidor, que não conhece a escolha do usuário — sem isto, mandar uma mensagem
            // apaga a classe e o chat volta para o claro. Observar o atributo em vez de usar
            // hook do Livewire porque os nomes de hook mudam entre a v3 e a v4, e o pacote
            // suporta as duas. O callback roda como microtask, antes do paint, então a
            // reposição não pisca. Comparar antes de aplicar é o que evita laço com a
            // própria alteração.
            new MutationObserver(() => {
                if (alvo.classList.contains('dark') !== escuro()) {
                    aplicar();
                }
            }).observe(alvo, { attributes: true, attributeFilter: ['class'] });
        })();
    </script>

    <div
        class="flex flex-col w-full max-w-4xl mx-auto bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-gray-900 dark:border-gray-800">

        <section class="flex items-center justify-between gap-3 px-4 py-2 border-b border-gray-100 dark:border-gray-800">
            <span class="flex items-center min-w-0 gap-2.5">
                @if (config('claudinho.logo', true))
                    <x-claudinho::logo />

                    <span class="hidden w-px h-4 bg-gray-200 rounded-full dark:bg-gray-700 shrink-0 sm:block"></span>
                @endif

                <span class="text-sm text-gray-500 truncate dark:text-gray-400">
                    {{ config('claudinho.titulo', 'Assistente de IA') }}
                </span>
            </span>

            <span class="flex items-center gap-2 shrink-0">
                @if (config('claudinho.tema.seletor', true))
                    {{-- Três estados num botão só: o ícone mostra o atual, o title diz o próximo.
                         Ciclar (em vez de alternar entre dois) é o que preserva o "seguir o
                         sistema" depois de uma escolha manual. --}}
                    <button type="button" x-on:click="alternar()"
                        x-bind:title="{ sistema: 'Tema: seguindo o sistema. Clique para claro.', claro: 'Tema: claro. Clique para escuro.', escuro: 'Tema: escuro. Clique para seguir o sistema.' }[tema]"
                        x-bind:aria-label="{ sistema: 'Tema seguindo o sistema', claro: 'Tema claro', escuro: 'Tema escuro' }[tema]"
                        class="inline-flex items-center justify-center w-8 h-8 text-gray-500 transition bg-white border border-gray-300 rounded-md shrink-0 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1 dark:text-gray-400 dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-white dark:focus:ring-offset-gray-900">
                        <svg x-show="tema === 'sistema'" style="display: none" class="w-4 h-4 shrink-0" fill="none"
                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25Z" />
                        </svg>

                        <svg x-show="tema === 'claro'" style="display: none" class="w-4 h-4 shrink-0" fill="none"
                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>

                        <svg x-show="tema === 'escuro'" style="display: none" class="w-4 h-4 shrink-0" fill="none"
                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                        </svg>
                    </button>
                @endif

                @if ($this->temConversa())
                    <button type="button" wire:click="limpar" wire:confirm="Limpar toda a conversa?"
                        title="Apaga o histórico desta conversa"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm font-medium text-gray-600 transition bg-white border border-gray-300 rounded-md shrink-0 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1 dark:text-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-white dark:focus:ring-offset-gray-900">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                        <span class="hidden sm:inline">Limpar conversa</span>
                        <span class="sr-only sm:hidden">Limpar conversa</span>
                    </button>
                @endif

                @if ($this->podeAdministrar())
                    <button type="button" x-on:click="$dispatch('claudinho-abrir-configuracoes')" title="Configurações"
                        aria-label="Configurações"
                        class="inline-flex items-center justify-center w-8 h-8 text-gray-500 transition bg-white border border-gray-300 rounded-md shrink-0 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1 dark:text-gray-400 dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-white dark:focus:ring-offset-gray-900">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.077-.124.072-.044.146-.086.22-.128.331-.183.581-.495.643-.869l.214-1.28Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                    </button>
                @endif
            </span>
        </section>

        {{-- Rola sozinho, mas só enquanto o usuário está no fim: se ele subiu para reler algo,
             o texto que chega em streaming não pode arrancar a tela de baixo dele. --}}
        <section x-data="{
            presoNoFim: true,
            observador: null,
            init() {
                this.aoFim();

                // childList/subtree pega mensagem nova; characterData pega token do wire:stream.
                this.observador = new MutationObserver(() => this.presoNoFim && this.aoFim());
                this.observador.observe(this.$el, { childList: true, subtree: true, characterData: true });
            },
            destroy() {
                this.observador?.disconnect();
            },
            aoFim() {
                this.$el.scrollTop = this.$el.scrollHeight;
            },
        }" x-on:scroll="presoNoFim = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 80"
            x-on:claudinho-rolar.window="presoNoFim = true; $nextTick(() => aoFim())"
            class="flex flex-col gap-3 p-4 overflow-y-auto min-h-[24rem] max-h-[60vh]">
            @forelse ($this->mensagensVisiveis() as $indice => $mensagem)
                @if ($mensagem['autor'] === 'user')
                    <article wire:key="msg-{{ $indice }}" class="flex justify-end">
                        <div
                            class="px-3 py-2 text-sm whitespace-pre-wrap rounded-lg max-w-[80%] bg-sky-50 text-sky-900 dark:bg-sky-950 dark:text-sky-100">
                            {{ $mensagem['texto'] }}
                        </div>
                    </article>
                @elseif ($mensagem['tipo'] === 'grafico')
                    <article wire:key="msg-{{ $indice }}" class="flex justify-start">
                        <div class="w-full px-3 py-2 rounded-lg max-w-[80%] bg-gray-50 dark:bg-gray-800">
                            <x-claudinho::grafico :spec="$mensagem['spec']" />
                        </div>
                    </article>
                @elseif ($mensagem['autor'] === 'sistema')
                    {{-- Alteração não pode ficar com a mesma cor de consulta: o rótulo é o
                         registro visível de que algo mudou no sistema. --}}
                    @php($acao = $mensagem['tipo'] === 'acao')
                    <article wire:key="msg-{{ $indice }}" class="flex justify-start">
                        <div @class([
                            'inline-flex items-center gap-1.5 px-2 py-1 text-xs border rounded-md',
                            'text-gray-500 border-gray-100 bg-gray-50 dark:text-gray-400 dark:border-gray-700 dark:bg-gray-800' => !$acao,
                            'text-amber-800 border-amber-200 bg-amber-50 dark:text-amber-200 dark:border-amber-800/60 dark:bg-amber-950/40' => $acao && $mensagem['situacao'] === 'concluida',
                            'text-gray-500 border-gray-200 bg-gray-50 line-through dark:text-gray-400 dark:border-gray-700 dark:bg-gray-800' => $acao && $mensagem['situacao'] === 'recusada',
                            'text-red-700 border-red-200 bg-red-50 dark:text-red-300 dark:border-red-900/60 dark:bg-red-950/40' => $acao && $mensagem['situacao'] === 'erro',
                            'text-amber-700 border-amber-200 bg-amber-50 dark:text-amber-300 dark:border-amber-800/60 dark:bg-amber-950/40' => $acao && $mensagem['situacao'] === 'pendente',
                        ])>
                            @if ($acao)
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                </svg>
                            @else
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75" />
                                </svg>
                            @endif
                            {{ $mensagem['texto'] }}
                        </div>
                    </article>
                @else
                    <article wire:key="msg-{{ $indice }}" class="flex justify-start">
                        <div
                            class="px-3 py-2 overflow-x-auto text-sm rounded-lg max-w-[80%] bg-gray-50 text-gray-800 dark:bg-gray-800 dark:text-gray-100">
                            <div
                                class="prose-sm prose max-w-none dark:prose-invert prose-table:my-2 prose-th:px-2 prose-th:py-1 prose-td:px-2 prose-td:py-1 prose-p:my-1 prose-ul:my-1 prose-headings:my-2">
                                {!! $mensagem['html'] !!}
                            </div>
                        </div>
                    </article>
                @endif
            @empty
                <article class="m-auto text-sm text-center text-gray-400 dark:text-gray-500">
                    {{ config('claudinho.placeholder_vazio', 'Faça uma pergunta para começar.') }}
                </article>
            @endforelse

            {{-- Ação proposta pelo modelo. É o único ponto do chat em que nada acontece sem
                 um clique: o loop está pausado aqui, com o tool_use ainda sem resultado. --}}
            @foreach ($pendentes as $pendente)
                <article wire:key="pendente-{{ $pendente['id'] }}" class="flex justify-start">
                    <div
                        class="w-full max-w-[80%] px-3 py-2.5 text-sm border rounded-lg border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-100">
                        <p class="flex items-start gap-2 font-medium">
                            <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            <span>{{ $pendente['confirmacao'] }}</span>
                        </p>

                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300/80">
                            Esta ferramenta altera dados do sistema. Nada foi alterado ainda.
                        </p>

                        <div class="flex items-center gap-2 mt-2.5">
                            <button type="button" wire:click="confirmar('{{ $pendente['id'] }}')"
                                wire:loading.attr="disabled" wire:target="confirmar,recusar"
                                class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-white transition rounded-md bg-amber-600 hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1 disabled:opacity-50 dark:focus:ring-offset-gray-900">
                                Confirmar e executar
                            </button>

                            <button type="button" wire:click="recusar('{{ $pendente['id'] }}')"
                                wire:loading.attr="disabled" wire:target="confirmar,recusar"
                                class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-gray-700 transition bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-1 disabled:opacity-50 dark:text-gray-200 dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </article>
            @endforeach

            @if ($respondendo)
                <article wire:key="resposta-em-andamento" wire:init="responder" class="flex justify-start">
                    {{-- O Claudinho animado fica dentro da bolha, à esquerda: antes do primeiro
                         token ele é o conteúdo da bolha, depois vira o avatar do texto que chega. --}}
                    <div
                        class="flex items-start gap-2.5 px-3 py-2 text-sm rounded-lg max-w-[80%] bg-gray-50 text-gray-800 dark:bg-gray-800 dark:text-gray-100">
                        <x-claudinho::pensando class="mt-0.5" />

                        <span wire:stream="resposta" class="whitespace-pre-wrap"></span>
                    </div>
                </article>
            @endif
        </section>

        {{-- Enviar é intenção explícita: volta para o fim mesmo se o usuário tinha subido. --}}
        {{-- Bloquear enquanto há ação pendente não é só UI: pergunta nova deixaria um
             tool_use sem tool_result e a API rejeitaria a conversa inteira. O componente
             barra de novo no servidor, porque HTML desabilitado não é validação. --}}
        @php($bloqueado = $respondendo || $pendentes !== [])
        <form wire:submit="enviar" x-on:submit="$dispatch('claudinho-rolar')"
            class="flex items-center gap-2 p-4 border-t border-gray-100 dark:border-gray-800">
            <section class="grow">
                <textarea wire:model="pergunta" rows="2" @disabled($bloqueado)
                    placeholder="{{ $pendentes !== [] ? 'Confirme ou cancele a alteração acima para continuar...' : 'Digite sua pergunta...' }}"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                    class="w-full text-sm border-gray-300 rounded-md resize-none focus:border-sky-500 focus:ring-sky-500 disabled:bg-gray-50 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500 dark:disabled:bg-gray-800/50"></textarea>

                @error('pergunta')
                    <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span>
                @enderror
            </section>

            {{-- Só ícone: o aria-label é o que sobra de nome acessível, então não pode sair. --}}
            <button type="submit" @disabled($bloqueado) title="Enviar" aria-label="Enviar"
                class="inline-flex items-center justify-center w-10 h-10 text-white transition rounded-md shrink-0 bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:opacity-50 dark:focus:ring-offset-gray-900">
                <span wire:loading.remove wire:target="enviar" class="inline-flex">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </span>

                <span wire:loading wire:target="enviar" class="inline-flex">
                    <svg class="w-5 h-5 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                </span>
            </button>
        </form>

        {{-- Fica no fim do DOM porque o modal é fixed: a posição aqui não afeta o layout.
             Nem renderiza para quem não tem a permissão — o componente ainda revalida o
             gate em cada ação, já que HTML ausente não é autorização. --}}
        @if ($this->podeAdministrar())
            @livewire('claudinho.configuracoes')
        @endif
    </div>
</div>
