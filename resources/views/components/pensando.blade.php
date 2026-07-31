{{-- Aqui a marca é SVG inline, não PNG como no header: piscar e mexer o cabelo exige
     animar cabeça, olhos e antenas em separado. A geometria foi medida do export
     original — cabeça 296x256 com raio 88, olhos de raio 26, antenas de traço 20. --}}
@props(['altura' => 'h-8'])

{{-- Fora do span: style não é conteúdo de frase válido. Como nasce com display:none,
     não conta como item de flex nem interfere no gap do container. --}}
<style>
    @keyframes claudinho-piscar {

        0%,
        90%,
        100% {
            transform: scaleY(1)
        }

        93%,
        95% {
            transform: scaleY(.08)
        }
    }

    @keyframes claudinho-cabelo {
        from {
            transform: rotate(-7deg)
        }

        to {
            transform: rotate(7deg)
        }
    }

    .claudinho-olho {
        transform-box: fill-box;
        transform-origin: center;
        animation: claudinho-piscar 3.4s infinite;
    }

    /* Cada antena gira em torno da própria base, escondida dentro da cabeça. */
    .claudinho-cabelo {
        transform-box: view-box;
        animation: claudinho-cabelo 1.3s ease-in-out infinite alternate;
    }

    .claudinho-cabelo--esq {
        transform-origin: 118.81px 90px;
        animation-duration: 1.15s;
    }

    .claudinho-cabelo--meio {
        transform-origin: 145.5px 90px;
        animation-duration: 1.45s;
    }

    /* Fase invertida: as três no mesmo compasso pareceriam um bloco só. */
    .claudinho-cabelo--dir {
        transform-origin: 172.19px 90px;
        animation-direction: alternate-reverse;
    }

    @media (prefers-reduced-motion: reduce) {

        .claudinho-olho,
        .claudinho-cabelo {
            animation: none;
        }
    }
</style>

<span {{ $attributes->merge(['class' => 'inline-flex shrink-0']) }}>
    <svg viewBox="0 0 296 373" class="{{ $altura }} w-auto overflow-visible" aria-hidden="true">
        {{-- Antenas antes da cabeça: é a cabeça que esconde a base delas. --}}
        <g stroke="#d3754c" stroke-width="20" stroke-linecap="round">
            <line class="claudinho-cabelo claudinho-cabelo--esq" x1="94" y1="31.21" x2="118.81" y2="90" />
            <line class="claudinho-cabelo claudinho-cabelo--meio" x1="145.5" y1="10" x2="145.5" y2="90" />
            <line class="claudinho-cabelo claudinho-cabelo--dir" x1="197" y1="31.21" x2="172.19" y2="90" />
        </g>

        <g class="fill-[#191512] dark:fill-[#f2ece1]">
            <rect x="0" y="72" width="296" height="256" rx="88" />
            <path d="M73,280 L46.3,340.5 A14,14 0 0 0 56.2,359.9 L112.9,371.9 L167.45,280 Z" />
        </g>

        <circle class="claudinho-olho fill-[#d3754c]" cx="105.5" cy="201.5" r="26" />
        <circle class="claudinho-olho fill-[#f2ece1] dark:fill-[#120f0c]" cx="193.5" cy="201.5" r="26" />
    </svg>

    <span class="sr-only">Claudinho está respondendo</span>
</span>
