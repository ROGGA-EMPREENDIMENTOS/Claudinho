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
    {{-- A geometria vive na marca; aqui entra só o que é animação. As classes que o
         <style> acima persegue vêm do `animado`. --}}
    <x-claudinho::marca :altura="$altura" animado />

    <span class="sr-only">Claudinho está respondendo</span>
</span>
