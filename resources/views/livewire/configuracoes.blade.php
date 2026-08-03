@php($chave = $this->chaveEmUso())
@php($api = $this->situacaoApi())

{{-- style="display:none" em vez de x-cloak: o x-show do Alpine remove a propriedade
     ao abrir, e assim o modal não pisca antes do Alpine carregar nem depende de
     nenhuma regra de CSS da aplicação. --}}
<div x-data="{ aberto: false, salvo: false }" x-on:claudinho-abrir-configuracoes.window="aberto = true"
    x-on:claudinho-configuracoes-salvas="salvo = true; setTimeout(() => salvo = false, 2500)"
    x-on:keydown.escape.window="aberto = false">

    <div style="display: none" x-show="aberto" class="fixed inset-0 z-50 flex items-center justify-center p-4"
        role="dialog" aria-modal="true" aria-labelledby="claudinho-configuracoes-titulo">

        <div class="absolute inset-0 bg-gray-900/50" x-on:click="aberto = false" aria-hidden="true"></div>

        {{-- max-h + overflow no corpo: o modal cresceu com os canais e a documentação,
             e sem isto os botões saem da tela em notebook. --}}
        <div
            class="relative flex flex-col w-full max-w-lg overflow-hidden bg-white border border-gray-200 rounded-lg shadow-xl max-h-[90vh] dark:bg-gray-900 dark:border-gray-800">
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

            <form wire:submit="salvar" class="flex flex-col min-h-0 gap-4 p-4 overflow-y-auto">
                <section class="flex flex-col gap-1.5">
                    <label for="claudinho-modelo" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Modelo
                    </label>

                    <select wire:model="modelo" id="claudinho-modelo"
                        class="w-full text-sm border-gray-300 rounded-md focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700">
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
                        class="w-full font-mono text-sm border-gray-300 rounded-md focus:border-sky-500 focus:ring-sky-500 dark:text-gray-100 dark:bg-gray-800 dark:border-gray-700 dark:placeholder-gray-500">

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
                            <span class="text-amber-700 dark:text-amber-500">Nenhuma chave configurada — o chat carrega,
                                mas falha na primeira pergunta.</span>
                        @endif
                    </span>
                </section>

                <hr class="border-gray-100 dark:border-gray-800">

                <section class="flex flex-col gap-3">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Canais</h3>

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

                    <label class="flex items-start gap-2.5 {{ $api['publicada'] ? 'cursor-pointer' : 'cursor-not-allowed opacity-60' }}">
                        <input wire:model="api" type="checkbox" @disabled(! $api['publicada'])
                            class="mt-0.5 rounded border-gray-300 text-sky-600 shrink-0 focus:ring-sky-500 disabled:opacity-50 dark:bg-gray-800 dark:border-gray-700">

                        <span class="flex flex-col gap-0.5">
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                Atendimento pela API (WhatsApp e outros canais)
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($api['publicada'])
                                    Desligado, o endpoint responde 503 e nenhuma conversa externa é atendida.
                                @else
                                    Indisponível: a rota não está publicada neste ambiente. Publicar um endpoint HTTP
                                    é decisão de deploy, não de tela — veja abaixo o que falta.
                                @endif
                            </span>
                        </span>
                    </label>
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

                        @if ($api['url'])
                            <div class="flex flex-col gap-1">
                                <span class="font-medium text-gray-700 dark:text-gray-300">Enviar uma mensagem</span>
                                <pre class="p-2 overflow-x-auto font-mono text-gray-700 rounded bg-gray-50 dark:bg-gray-800 dark:text-gray-300">curl -X POST {{ $api['url'] }} \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"canal":"whatsapp","identificador":"5547999998888","mensagem":"quantas obras ativas?"}'</pre>
                            </div>
                        @endif

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
                            <span class="font-mono">aguardando_confirmacao</span> e só a resposta exatamente
                            <span class="font-mono">SIM</span> aprova. Qualquer outra cancela.
                        </p>

                        <p class="text-gray-500 dark:text-gray-400">
                            Quem decide de qual usuário é a permissão em cada número é o resolvedor da aplicação —
                            gates e escopo por obra valem igual ao chat em tela. O passo a passo completo está no
                            README do pacote, seção <span class="font-mono">Endpoint para canais externos</span>.
                        </p>
                    </div>
                </section>

                <section class="flex items-center justify-end gap-2 pt-1">
                    <span x-show="salvo" style="display: none"
                        class="mr-auto text-xs font-medium text-green-700 dark:text-green-400">
                        Configurações salvas.
                    </span>

                    <button type="button" x-on:click="aberto = false"
                        class="px-3 py-2 text-sm font-medium text-gray-700 transition bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1 dark:text-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">
                        Cancelar
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
