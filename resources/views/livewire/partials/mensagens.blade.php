{{-- As bolhas de uma conversa. Compartilhado pelo chat e pelo histórico: as duas
     telas desenham a MESMA conversa, e o que separa "alterou" de "o usuário recusou"
     é a parte que não pode divergir entre elas.

     Recebe o que o Exibicao já achatou — aqui não há regra nenhuma sobre o que
     aparece, só como aparece.

     @param  array   $mensagens  Saída do Exibicao::mensagens().
     @param  string  $prefixo    Prefixo do wire:key; dois usos na mesma página não
                                 podem repetir chave.
     @param  string  $vazio      A frase de quando não há mensagem nenhuma. --}}
        @forelse ($mensagens as $indice => $mensagem)
            @if ($mensagem['autor'] === 'user')
                <article wire:key="{{ $prefixo }}-{{ $indice }}" class="flex justify-end">
                    <div
                        class="px-3 py-2 text-sm whitespace-pre-wrap rounded-lg max-w-[80%] bg-sky-50 text-sky-900 dark:bg-sky-950 dark:text-sky-100">
                        {{ $mensagem['texto'] }}
                    </div>
                </article>
            @elseif ($mensagem['tipo'] === 'grafico')
                <article wire:key="{{ $prefixo }}-{{ $indice }}" class="flex justify-start">
                    <div class="w-full px-3 py-2 rounded-lg max-w-[80%] bg-gray-50 dark:bg-gray-800">
                        <x-claudinho::grafico :spec="$mensagem['spec']" />
                    </div>
                </article>
            @elseif ($mensagem['autor'] === 'sistema')
                {{-- Alteração não pode ficar com a mesma cor de consulta: o rótulo é o
                     registro visível de que algo mudou no sistema. --}}
                @php($acao = $mensagem['tipo'] === 'acao')
                <article wire:key="{{ $prefixo }}-{{ $indice }}" class="flex justify-start">
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
                <article wire:key="{{ $prefixo }}-{{ $indice }}" class="flex justify-start">
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
                {{ $vazio }}
            </article>
        @endforelse
