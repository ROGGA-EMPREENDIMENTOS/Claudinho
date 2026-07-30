{{-- Ícones em SVG inline de propósito: o pacote não deve exigir blade-heroicons na aplicação. --}}
<div class="flex flex-col w-full max-w-4xl mx-auto bg-white border border-gray-200 rounded-lg shadow-sm">
    <section class="flex items-center justify-between gap-3 px-4 py-2 border-b border-gray-100">
        <span class="text-sm text-gray-500">
            {{ config('claudinho.titulo', 'Assistente de IA') }}
        </span>

        @if ($this->temConversa())
            <button type="button" wire:click="limpar" wire:confirm="Limpar toda a conversa?"
                title="Apaga o histórico desta conversa"
                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm font-medium text-gray-600 transition bg-white border border-gray-300 rounded-md shrink-0 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                <span class="hidden sm:inline">Limpar conversa</span>
                <span class="sr-only sm:hidden">Limpar conversa</span>
            </button>
        @endif
    </section>

    <section class="flex flex-col gap-3 p-4 overflow-y-auto min-h-[24rem] max-h-[60vh]">
        @forelse ($this->mensagensVisiveis() as $indice => $mensagem)
            @if ($mensagem['autor'] === 'user')
                <article wire:key="msg-{{ $indice }}" class="flex justify-end">
                    <div class="px-3 py-2 text-sm whitespace-pre-wrap rounded-lg max-w-[80%] bg-sky-50 text-sky-900">
                        {{ $mensagem['texto'] }}
                    </div>
                </article>
            @elseif ($mensagem['tipo'] === 'grafico')
                <article wire:key="msg-{{ $indice }}" class="flex justify-start">
                    <div class="w-full px-3 py-2 rounded-lg max-w-[80%] bg-gray-50">
                        <x-claudinho::grafico :spec="$mensagem['spec']" />
                    </div>
                </article>
            @elseif ($mensagem['autor'] === 'sistema')
                <article wire:key="msg-{{ $indice }}" class="flex justify-start">
                    <div
                        class="inline-flex items-center gap-1.5 px-2 py-1 text-xs text-gray-500 border border-gray-100 rounded-md bg-gray-50">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75" />
                        </svg>
                        {{ $mensagem['texto'] }}
                    </div>
                </article>
            @else
                <article wire:key="msg-{{ $indice }}" class="flex justify-start">
                    <div class="px-3 py-2 overflow-x-auto text-sm rounded-lg max-w-[80%] bg-gray-50 text-gray-800">
                        <div
                            class="prose-sm prose max-w-none prose-table:my-2 prose-th:px-2 prose-th:py-1 prose-td:px-2 prose-td:py-1 prose-p:my-1 prose-ul:my-1 prose-headings:my-2">
                            {!! $mensagem['html'] !!}
                        </div>
                    </div>
                </article>
            @endif
        @empty
            <article class="m-auto text-sm text-center text-gray-400">
                {{ config('claudinho.placeholder_vazio', 'Faça uma pergunta para começar.') }}
            </article>
        @endforelse

        @if ($respondendo)
            <article wire:key="resposta-em-andamento" wire:init="responder" class="flex justify-start">
                <div class="px-3 py-2 text-sm whitespace-pre-wrap rounded-lg max-w-[80%] bg-gray-50 text-gray-800">
                    <span wire:stream="resposta"></span>
                    <span class="text-gray-400 animate-pulse">▌</span>
                </div>
            </article>
        @endif
    </section>

    <form wire:submit="enviar" class="flex items-center gap-2 p-4 border-t border-gray-100">
        <section class="grow">
            <textarea wire:model="pergunta" rows="2" placeholder="Digite sua pergunta..." @disabled($respondendo)
                x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                class="w-full text-sm border-gray-300 rounded-md resize-none focus:border-sky-500 focus:ring-sky-500 disabled:bg-gray-50"></textarea>

            @error('pergunta')
                <span class="text-xs text-red-600">{{ $message }}</span>
            @enderror
        </section>

        <button type="submit" @disabled($respondendo)
            class="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-medium text-white transition rounded-md shrink-0 bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:opacity-50">
            <span wire:loading.remove wire:target="enviar" class="inline-flex items-center gap-1.5">
                Enviar
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </span>

            <span wire:loading wire:target="enviar" class="inline-flex items-center gap-1.5">
                Enviando
                <svg class="w-4 h-4 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
            </span>
        </button>
    </form>
</div>
