{{-- Quatro arquivos porque PNG tem cor fixa: o par claro/escuro troca a tinta pelo creme,
     e o par ícone/lockup troca a assinatura completa pela marca só, que é o que cabe no
     header em telas estreitas. Depende de `vendor:publish --tag=claudinho-assets`. --}}
@props(['altura' => 'h-7'])

{{-- Até sm: só a marca. --}}
<span class="inline-flex shrink-0 sm:hidden">
    <img src="{{ asset('vendor/claudinho/claudinho-icone-claro.png') }}" alt="Claudinho"
        class="{{ $altura }} w-auto dark:hidden">
    <img src="{{ asset('vendor/claudinho/claudinho-icone-escuro.png') }}" alt="Claudinho"
        class="{{ $altura }} hidden w-auto dark:block">
</span>

{{-- De sm para cima: assinatura horizontal. --}}
<span class="hidden shrink-0 sm:inline-flex">
    <img src="{{ asset('vendor/claudinho/claudinho-lockup-claro.png') }}" alt="Claudinho"
        class="{{ $altura }} w-auto dark:hidden">
    <img src="{{ asset('vendor/claudinho/claudinho-lockup-escuro.png') }}" alt="Claudinho"
        class="{{ $altura }} hidden w-auto dark:block">
</span>
