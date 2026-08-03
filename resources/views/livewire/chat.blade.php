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

    @if ($flutuante && $this->flutuanteVisivel())
        @php($config = (array) config('claudinho.flutuante', []))
        @php($aEsquerda = ($config['posicao'] ?? 'direita') === 'esquerda')
        @php($rotulo = $config['rotulo'] ?? 'Abrir o assistente')

        {{-- Classes de lado escritas por inteiro, e não interpoladas: o scanner do
             Tailwind lê o arquivo como texto e não resolve `sm:{{ $var }}-6`. --}}
        <div x-data="{
            aberto: @js((bool) ($config['aberto'] ?? false)),
            naoLido: false,

            abrir() {
                this.aberto = true;
                this.naoLido = false;

                this.$nextTick(() => {
                    // Escondido, a área de mensagens tem scrollHeight 0 e o auto-scroll
                    // não roda — então ao abrir precisa ser mandado para o fim de novo.
                    this.$dispatch('claudinho-rolar');
                    this.$refs.painel?.querySelector('textarea:not([disabled])')?.focus();
                });
            },

            fechar() {
                this.aberto = false;
            },
        }"
            {{-- Deixa a aplicação abrir o chat de qualquer lugar: um item de menu, um
                 botão de "precisa de ajuda?", o que ela quiser. --}}
            x-on:claudinho-abrir.window="abrir()"
            {{-- Resposta (ou erro, ou ação esperando confirmação) que chegou com o painel
                 fechado acende o ponto no botão. É o que evita a pergunta ficar sem
                 retorno visível quando o usuário fecha e continua trabalhando. --}}
            x-on:claudinho-resposta-pronta.window="if (! aberto) naoLido = true"
            x-on:claudinho-erro.window="if (! aberto) naoLido = true"
            x-on:keydown.escape.window="if (aberto) fechar()">

            {{-- z-40 e não z-50: o modal de configurações é z-50 e precisa ficar acima. --}}
            <div x-ref="painel" x-show="aberto" style="display: none"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-2"
                role="dialog" aria-label="{{ config('claudinho.titulo', 'Assistente de IA') }}"
                {{-- Tela inteira no mobile: um painel de 26rem em 360px de largura não
                     serve para conversar. Do sm: para cima, ancorado no canto. --}}
                @class([
                    'fixed z-40 flex inset-0 sm:inset-auto sm:bottom-24 sm:w-[26rem] sm:h-[38rem] sm:max-h-[calc(100dvh-9rem)]',
                    'sm:left-6' => $aEsquerda,
                    'sm:right-6' => !$aEsquerda,
                ])>
                @include('claudinho::livewire.partials.card', ['flutuante' => true])
            </div>

            {{-- Escondido enquanto aberto: no mobile o painel cobre a tela e o botão
                 ficaria flutuando sobre a conversa. Para fechar há o X e o Esc. --}}
            {{-- Superfície neutra, e não o sky-600 do botão de enviar: a marca tem três
                 cores próprias e foi desenhada para fundo claro ou creme. Sobre azul
                 saturado só a cabeça leria bem, e as antenas terracota brigariam — seria
                 recolorir a marca. O anel sky devolve a proeminência que a cor daria. --}}
            {{-- Fora do <button> porque style não é conteúdo de frase válido — mesmo
                 motivo do pensando. O ciclo é longo e a piscada é curta de propósito: o
                 botão fica na tela o tempo todo, e é o intervalo parado que separa
                 "tem alguém aí" de ruído permanente. --}}
            <style>
                @keyframes claudinho-piscada {

                    0%,
                    96%,
                    100% {
                        transform: scaleY(1)
                    }

                    97.5%,
                    98.5% {
                        transform: scaleY(.08)
                    }
                }

                .claudinho-piscada {
                    transform-box: fill-box;
                    transform-origin: center;
                    animation: claudinho-piscada 8s infinite;
                }

                @media (prefers-reduced-motion: reduce) {
                    .claudinho-piscada {
                        animation: none;
                    }
                }
            </style>

            <button type="button" x-show="! aberto" x-on:click="abrir()" x-bind:aria-expanded="aberto ? 'true' : 'false'"
                title="{{ $rotulo }}" aria-label="{{ $rotulo }}"
                {{-- #d3754c é o terracota da própria marca (antenas e olho), em hex literal
                     e não num laranja aproximado do Tailwind: a borda existe para amarrar o
                     botão ao ícone, então tem de ser o mesmo tom. Sem variante dark: a marca
                     também não troca essa cor entre os temas.

                     1px a 50% é acento, não contorno — fica em 1,7:1 sobre branco, abaixo do
                     3:1 de elemento de interface. É aceitável porque não é o anel que
                     identifica o botão: o círculo branco, a sombra e a marca dentro fazem
                     isso. O que precisa saltar é o FOCO, e esse continua sky-500 em 2px,
                     intocado — anel laranja sobre borda laranja não se veria. --}}
                @class([
                    'fixed z-40 bottom-6 inline-flex items-center justify-center w-14 h-14 transition bg-white rounded-full shadow-lg ring-1 ring-[#d3754c]/50 hover:bg-gray-50 hover:ring-[#d3754c] hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 dark:bg-gray-900 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900',
                    'left-6' => $aEsquerda,
                    'right-6' => !$aEsquerda,
                ])>
                {{-- `piscando`, não `animado`: o pisca contínuo dos dois olhos mais o
                     cabelo balançando é ruído permanente num canto da tela. A piscadela
                     de um olho a cada 8s dá vida sem competir com a página. --}}
                <x-claudinho::marca altura="h-8" piscando />

                {{-- style inline porque o ponto nasce escondido e o x-cloak exigiria CSS
                     publicado pela aplicação. --}}
                <span x-show="naoLido" style="display: none"
                    class="absolute top-0 right-0 block w-3.5 h-3.5 bg-red-500 border-2 border-white rounded-full dark:border-gray-900">
                    <span class="sr-only">Há resposta nova</span>
                </span>
            </button>
        </div>
    @elseif (! $flutuante)
        @include('claudinho::livewire.partials.card', ['flutuante' => false])
    @endif

    {{-- Fora do painel de propósito (ver o comentário no card): o modal é fixed e não
         pode ter ancestral com transform. Nem renderiza para quem não tem a permissão —
         o componente ainda revalida o gate em cada ação, já que HTML ausente não é
         autorização. --}}
    @if ($this->podeAdministrar())
        {{-- key própria: dois chats na página são dois modais distintos, e sem ela o
             Livewire trataria os dois como o mesmo componente. --}}
        @livewire('claudinho.configuracoes', ['dono' => $this->getId()],
            key('claudinho-configuracoes-'.$this->getId()))
    @endif
</div>
