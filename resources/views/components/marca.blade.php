{{-- A marca em SVG inline, e não PNG como no header: aqui ela precisa herdar o tema por
     CSS e não pode depender de `vendor:publish --tag=claudinho-assets` — o botão do chat
     flutuante é o elemento mais visível do modo, e imagem quebrada ali é pior que ícone
     genérico. A geometria foi medida do export original: cabeça 296x256 com raio 88,
     olhos de raio 26, antenas de traço 20.

     $animado põe as classes que o <style> do pensando anima. Sem ele as classes não
     entram, e é de propósito: aquele CSS é global, então um "pensando" em cena faria a
     marca do botão flutuante piscar e mexer o cabelo junto.

     $piscando é a versão discreta, para a marca que fica parada na tela: uma piscada de
     um olho só, de tempos em tempos. Classe própria, e não a do pensando, justamente
     para os dois não se misturarem quando houver um "pensando" em cena. Quem usa carrega
     o <style> junto (ver o botão flutuante no chat) — aqui não vem, porque dentro de um
     <button> o <style> não é conteúdo válido. --}}
@props(['altura' => 'h-8', 'animado' => false, 'piscando' => false])

<svg viewBox="0 0 296 373" {{ $attributes->merge(['class' => $altura.' w-auto shrink-0 overflow-visible']) }}
    aria-hidden="true">
    {{-- Antenas antes da cabeça: é a cabeça que esconde a base delas. --}}
    <g stroke="#d3754c" stroke-width="20" stroke-linecap="round">
        <line @class(['claudinho-cabelo claudinho-cabelo--esq' => $animado]) x1="94" y1="31.21" x2="118.81"
            y2="90" />
        <line @class(['claudinho-cabelo claudinho-cabelo--meio' => $animado]) x1="145.5" y1="10" x2="145.5"
            y2="90" />
        <line @class(['claudinho-cabelo claudinho-cabelo--dir' => $animado]) x1="197" y1="31.21" x2="172.19"
            y2="90" />
    </g>

    <g class="fill-[#191512] dark:fill-[#f2ece1]">
        <rect x="0" y="72" width="296" height="256" rx="88" />
        <path d="M73,280 L46.3,340.5 A14,14 0 0 0 56.2,359.9 L112.9,371.9 L167.45,280 Z" />
    </g>

    {{-- Só o olho terracota pisca no modo discreto: os dois juntos seriam a piscada do
         pensando de novo, mais lenta. Um olho só lê como piscadela. --}}
    <circle @class(['fill-[#d3754c]', 'claudinho-olho' => $animado, 'claudinho-piscada' => $piscando]) cx="105.5"
        cy="201.5" r="26" />
    <circle @class(['fill-[#f2ece1] dark:fill-[#120f0c]', 'claudinho-olho' => $animado]) cx="193.5" cy="201.5"
        r="26" />
</svg>
