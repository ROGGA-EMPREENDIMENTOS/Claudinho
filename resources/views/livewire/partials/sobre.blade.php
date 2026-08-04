{{-- Janela "Sobre": o que é o assistente, como ele trabalha e em que versão está.
     Blade puro com Alpine, sem componente Livewire próprio: o conteúdo é o mesmo do
     primeiro render até fechar a página, então abrir não tem por que ir ao servidor.
     O modal de configurações é Livewire porque lá há formulário e segredo em banco. --}}
@php($sobre = $this->sobre())

{{-- Mesmo desenho do modal de configurações, pelos mesmos motivos: style="display:none"
     em vez de x-cloak (não pisca antes do Alpine e não depende de CSS da aplicação), e
     .window no listener porque o botão vive no card, que é irmão deste bloco. A
     comparação de dono é o que evita dois chats na mesma página abrirem duas janelas. --}}
<div x-data="{ aberto: false }"
    x-on:claudinho-abrir-sobre.window="if (! $event.detail?.dono || $event.detail.dono === @js($this->getId())) aberto = true"
    x-on:keydown.escape.window="aberto = false">

    <div style="display: none" x-show="aberto" class="fixed inset-0 z-50 flex items-center justify-center p-4"
        role="dialog" aria-modal="true" aria-labelledby="claudinho-sobre-titulo-{{ $this->getId() }}">

        <div class="absolute inset-0 bg-gray-900/50" x-on:click="aberto = false" aria-hidden="true"></div>

        <div
            class="relative flex flex-col w-full max-w-md overflow-hidden bg-white border border-gray-200 rounded-lg shadow-xl max-h-[90vh] dark:bg-gray-900 dark:border-gray-800">

            {{-- A marca em SVG inline (x-claudinho::marca) e não o PNG do header: aqui ela
                 é o assunto da janela, e ficar quebrada por falta de
                 `vendor:publish --tag=claudinho-assets` seria o pior lugar para isso. --}}
            <section class="flex items-start justify-between gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-800">
                <span class="flex items-center min-w-0 gap-3">
                    <x-claudinho::marca altura="h-10" />

                    <span class="flex flex-col min-w-0">
                        <h2 id="claudinho-sobre-titulo-{{ $this->getId() }}"
                            class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            Sobre o Claudinho
                        </h2>

                        <span class="text-xs text-gray-500 truncate dark:text-gray-400">
                            {{ config('claudinho.titulo', 'Assistente de IA') }}
                        </span>
                    </span>
                </span>

                <button type="button" x-on:click="aberto = false" title="Fechar" aria-label="Fechar"
                    class="inline-flex items-center justify-center w-8 h-8 text-gray-500 transition rounded-md shrink-0 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </section>

            <div class="flex flex-col min-h-0 gap-4 p-4 overflow-y-auto">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Assistente de chat em linguagem natural sobre os dados desta aplicação, com as respostas
                    geradas pelo Claude, da Anthropic.
                </p>

                {{-- Escrito para o usuário do chat, não para quem instala: o que ele precisa
                     saber antes de agir com base numa resposta. O aviso de erro fica por
                     último e sem meio-termo — é o único item que muda o que a pessoa faz. --}}
                <section class="flex flex-col gap-2">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Como ele trabalha</h3>

                    <ul class="flex flex-col gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                        <li class="flex items-start gap-1.5">
                            <span class="mt-1.5 w-1 h-1 rounded-full bg-gray-400 shrink-0 dark:bg-gray-500"
                                aria-hidden="true"></span>
                            <span>Consulta os dados do próprio sistema pelas consultas que a aplicação registrou.
                                Ele não escreve SQL nem alcança tabela que ninguém tenha aberto para ele.</span>
                        </li>

                        <li class="flex items-start gap-1.5">
                            <span class="mt-1.5 w-1 h-1 rounded-full bg-gray-400 shrink-0 dark:bg-gray-500"
                                aria-hidden="true"></span>
                            <span>Só vê o que você vê: cada consulta passa pelas suas permissões, as mesmas das
                                telas do sistema.</span>
                        </li>

                        <li class="flex items-start gap-1.5">
                            <span class="mt-1.5 w-1 h-1 rounded-full bg-gray-400 shrink-0 dark:bg-gray-500"
                                aria-hidden="true"></span>
                            <span>Alteração de dados para e espera sua confirmação. Nada muda sem clique seu.</span>
                        </li>

                        <li class="flex items-start gap-1.5">
                            <span class="mt-1.5 w-1 h-1 rounded-full bg-amber-500 shrink-0" aria-hidden="true"></span>
                            <span class="text-amber-700 dark:text-amber-500">Pode errar, inclusive ao resumir e ao
                                somar. Confira no sistema o número em que for decidir algo.</span>
                        </li>
                    </ul>
                </section>

                <hr class="border-gray-100 dark:border-gray-800">

                {{-- <dl> e não tabela: é par rótulo/valor. Serve de diagnóstico — é o que
                     se pede a quem abre um chamado, e sem isto vira "está atualizado?". --}}
                <section class="flex flex-col gap-2">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Versões</h3>

                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                        @foreach ($sobre['ambiente'] as $rotulo => $versao)
                            <dt class="text-gray-500 dark:text-gray-400">{{ $rotulo }}</dt>
                            <dd class="font-mono text-gray-700 break-all dark:text-gray-300">{{ $versao }}</dd>
                        @endforeach

                        @if (filled($sobre['modelo']))
                            <dt class="text-gray-500 dark:text-gray-400">Modelo</dt>
                            <dd class="font-mono text-gray-700 break-all dark:text-gray-300">{{ $sobre['modelo'] }}</dd>
                        @endif
                    </dl>
                </section>

                <hr class="border-gray-100 dark:border-gray-800">

                <section class="flex flex-col gap-2">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Desenvolvimento</h3>

                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                        <dt class="text-gray-500 dark:text-gray-400">Pacote</dt>
                        <dd class="font-mono text-gray-700 break-all dark:text-gray-300">{{ $sobre['pacote'] }}</dd>

                        <dt class="text-gray-500 dark:text-gray-400">Licença</dt>
                        <dd class="text-gray-700 dark:text-gray-300">{{ $sobre['licenca'] }}</dd>

                        <dt class="text-gray-500 dark:text-gray-400">Desenvolvido por</dt>
                        <dd class="text-gray-700 dark:text-gray-300">{{ $sobre['desenvolvedor'] }}</dd>
                    </dl>
                </section>
            </div>

            {{-- A marca de quem desenvolve fecha a janela, não abre: quem chegou aqui
                 procurava versão ou o que o assistente faz. --}}
            <section class="flex items-center justify-between gap-3 px-4 py-3 border-t border-gray-100 dark:border-gray-800">
                <x-claudinho::rogga altura="h-5" />

                <button type="button" x-on:click="aberto = false"
                    class="px-3 py-2 text-sm font-medium text-gray-700 transition bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1 dark:text-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">
                    Fechar
                </button>
            </section>
        </div>
    </div>
</div>
