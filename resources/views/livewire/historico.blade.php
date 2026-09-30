{{-- Painel do histórico, ao lado da conversa. Quem decide a posição e a altura é o
     chat.blade.php, dono do layout; a LARGURA vem daqui, das colunas, porque só este
     componente sabe se há uma conversa aberta.

     Duas colunas a partir do xl: a lista continua à vista enquanto se lê uma conversa,
     que é o que permite pular de uma para outra sem voltar. Abaixo do xl não há
     largura para três painéis na tela (lista + conversa + chat), e aí a conversa toma
     o lugar da lista, com o caminho de volta no cabeçalho.

     As colunas NÃO encolhem. São o mínimo para ler um número e uma conversa, e quem
     cede espaço numa linha apertada é o chat, que tem teto de largura justamente
     porque lá sobra. --}}
@php($conversa = $this->conversa())
@php($linhas = $this->conversas())

<div class="flex w-full h-full min-h-0 gap-3 lg:w-auto">

    {{-- ─────────────── LISTA ─────────────── --}}
    <section @class([
        'flex flex-col min-h-0 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-gray-900 dark:border-gray-800',
        'w-full lg:w-[16rem] lg:shrink-0' => $conversa === null,
        // Com conversa aberta ela ocupa o lugar, e a lista só volta onde cabem as duas.
        'hidden xl:flex xl:w-[16rem] xl:shrink-0' => $conversa !== null,
    ])>
        <header class="flex items-center justify-between gap-2 px-3 py-2 border-b border-gray-100 shrink-0 dark:border-gray-800">
            <h2 class="flex items-center min-w-0 gap-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>

                <span class="truncate">Histórico</span>
            </h2>

            <button type="button" wire:click="fechar" title="Fechar o histórico" aria-label="Fechar o histórico"
                class="inline-flex items-center justify-center w-8 h-8 text-gray-500 transition rounded-md shrink-0 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                    aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </header>

        @if (! $this->temTabela())
            {{-- As migrations do pacote são carregadas, não rodadas: sem esta linha o
                 painel abriria vazio e pareceria "nunca ninguém conversou". --}}
            <p class="p-4 text-xs text-amber-800 dark:text-amber-200">
                A tabela de conversas ainda não existe. Rode <code class="font-mono">php artisan migrate</code> para o
                histórico começar a ser gravado.
            </p>
        @else
            <div class="flex flex-col gap-1 px-3 py-2 border-b border-gray-100 shrink-0 dark:border-gray-800">
                {{-- text-base no mobile pelo mesmo motivo do campo de pergunta: abaixo de
                     16px o Safari do iOS dá zoom sozinho ao focar e não desfaz. --}}
                <input type="search" wire:model.live.debounce.300ms="busca" placeholder="Buscar número ou nome..."
                    aria-label="Buscar número ou nome no histórico"
                    class="w-full text-base border-gray-300 rounded-md sm:text-sm focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500">

                {{-- Até onde a busca por nome chegou. Sem isto, o teto da varredura leria
                     como "essa pessoa não existe" — que é conclusão bem diferente de "não
                     achei entre as mais recentes". --}}
                @if ($this->varreuTudo())
                    <span class="text-[11px] text-gray-400 dark:text-gray-500">
                        Procurando por nome nas {{ (int) config('claudinho.historico.varredura', 200) }} conversas
                        mais recentes. Pelo número a busca alcança todas.
                    </span>
                @endif
            </div>

            {{-- min-h-0 é o que permite ao flex encolher e a rolagem acontecer AQUI, e
                 não na página: sem ele a lista cresce com o conteúdo e empurra o painel. --}}
            <ul class="flex flex-col min-h-0 overflow-y-auto grow divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($linhas as $linha)
                    @php($aberta = $conversa !== null && $conversa['id'] === $linha['id'])

                    <li wire:key="conversa-{{ $linha['id'] }}">
                        <button type="button" wire:click="abrir({{ $linha['id'] }})"
                            aria-current="{{ $aberta ? 'true' : 'false' }}"
                            @class([
                                'w-full px-3 py-2.5 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sky-500',
                                'hover:bg-gray-50 dark:hover:bg-gray-800' => !$aberta,
                                // A aberta fica marcada: com as duas colunas à vista, é o que
                                // diz qual linha da lista virou a conversa do lado.
                                'bg-sky-50 border-l-2 border-sky-500 dark:bg-sky-950/50' => $aberta,
                            ])>
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="text-sm font-medium text-gray-900 truncate dark:text-gray-100">
                                    {{ $linha['identificador'] }}
                                </span>

                                {{-- O relativo é o que a pessoa lê; a data exata fica no title,
                                     para quando ela precisar comparar com um horário de fora. --}}
                                <span class="text-xs text-gray-400 shrink-0 dark:text-gray-500"
                                    title="{{ $linha['atualizada_em']?->format('d/m/Y H:i:s') }}">
                                    {{ $linha['relativa'] }}
                                </span>
                            </span>

                            <span class="flex flex-wrap items-center gap-1.5 mt-1">
                                <span
                                    class="inline-flex items-center px-1.5 py-0.5 text-[11px] font-medium text-gray-600 rounded bg-gray-100 dark:text-gray-300 dark:bg-gray-800">
                                    {{ $linha['canal'] }}
                                </span>

                                <span class="text-xs text-gray-500 truncate dark:text-gray-400">
                                    {{ $linha['usuario'] }}
                                </span>

                                {{-- Em andamento é o que muda o que se faz com a informação:
                                     uma conversa viva ainda vai receber mensagem, e o que está
                                     pendente lá ainda pode ser confirmado do outro lado. --}}
                                @if ($linha['pendente'])
                                    <span
                                        class="inline-flex items-center px-1.5 py-0.5 text-[11px] font-medium rounded text-amber-800 bg-amber-100 dark:text-amber-200 dark:bg-amber-950">
                                        aguardando confirmação
                                    </span>
                                @elseif ($linha['ativa'])
                                    <span
                                        class="inline-flex items-center px-1.5 py-0.5 text-[11px] font-medium rounded text-emerald-800 bg-emerald-100 dark:text-emerald-200 dark:bg-emerald-950">
                                        em andamento
                                    </span>
                                @endif
                            </span>

                            @if ($linha['previa'] !== '')
                                <span class="block mt-1 text-xs text-gray-500 line-clamp-2 dark:text-gray-400">
                                    {{ $linha['previa'] }}
                                </span>
                            @endif
                        </button>
                    </li>
                @empty
                    <li class="p-4 text-xs text-center text-gray-400 dark:text-gray-500">
                        @if (trim($busca) !== '')
                            Nenhuma conversa com "{{ $busca }}".
                        @else
                            {{-- Dizer de ONDE vem o histórico evita a conclusão errada de que ele
                                 está quebrado: a conversa desta tela não é gravada em lugar nenhum. --}}
                            Nenhuma conversa gravada ainda. O histórico guarda as conversas dos canais
                            externos (WhatsApp e afins) — a desta tela vive só enquanto a página está aberta.
                        @endif
                    </li>
                @endforelse
            </ul>
        @endif
    </section>

    {{-- ─────────────── CONVERSA ─────────────── --}}
    @if ($conversa !== null)
        <section
            class="flex flex-col w-full min-h-0 bg-white border border-gray-200 rounded-lg shadow-sm lg:w-[22rem] lg:shrink-0 dark:bg-gray-900 dark:border-gray-800">
            <header class="flex items-center justify-between gap-2 px-3 py-2 border-b border-gray-100 shrink-0 dark:border-gray-800">
                {{-- Abaixo do xl a lista está escondida, e o cabeçalho inteiro é o caminho
                     de volta para ela. Dali para cima ela está do lado, e voltar não
                     significa nada — o que resta é fechar a coluna. --}}
                <button type="button" wire:click="voltar"
                    class="inline-flex items-center gap-1.5 min-w-0 px-1.5 py-1 -ml-1.5 text-sm font-medium text-gray-900 transition rounded-md xl:hidden hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-sky-500 dark:text-gray-100 dark:hover:bg-gray-800">
                    <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>

                    <span class="truncate">{{ $conversa['identificador'] }}</span>
                </button>

                <h3 class="hidden min-w-0 text-sm font-medium text-gray-900 truncate xl:block dark:text-gray-100">
                    {{ $conversa['identificador'] }}
                </h3>

                <button type="button" wire:click="voltar" title="Fechar esta conversa"
                    aria-label="Fechar esta conversa"
                    class="hidden xl:inline-flex items-center justify-center w-8 h-8 text-gray-500 transition rounded-md shrink-0 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <section class="px-3 py-2 text-xs text-gray-500 border-b border-gray-100 shrink-0 dark:border-gray-800 dark:text-gray-400">
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $conversa['canal'] }}</span>
                · {{ $conversa['usuario'] }}
                · {{ $conversa['atualizada_em']?->format('d/m/Y H:i') }}

                @if ($conversa['ativa'])
                    · <span class="text-emerald-700 dark:text-emerald-400">em andamento</span>
                @else
                    · encerrada por inatividade
                @endif
            </section>

            <section class="flex flex-col min-h-0 gap-3 p-3 overflow-y-auto grow">
                @include('claudinho::livewire.partials.mensagens', [
                    'mensagens' => $conversa['mensagens'],
                    'prefixo' => 'historico-'.$conversa['id'],
                    'vazio' => 'Esta conversa ainda não tem mensagem nenhuma.',
                ])

                {{-- A pendência aparece como aviso, e não como o card de confirmar/cancelar do
                     chat: quem decide está do outro lado, no aplicativo de mensagens, e um
                     botão aqui executaria a alteração em nome dele. --}}
                @foreach ($conversa['pendentes'] as $pendente)
                    <article wire:key="historico-pendente-{{ $pendente['id'] }}"
                        class="px-3 py-2 text-xs border rounded-lg border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-100">
                        <span class="font-medium">Aguardando a confirmação de quem está conversando:</span>
                        {{ $pendente['confirmacao'] ?? $pendente['nome'] }}
                    </article>
                @endforeach
            </section>
        </section>
    @endif
</div>
