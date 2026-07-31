<figure class="my-3 not-prose">
    <figcaption class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
        {{ $titulo }}
    </figcaption>

    <svg viewBox="0 0 {{ $largura }} {{ $altura }}" width="100%" height="auto" role="img"
        aria-label="{{ $descricaoAcessivel }}" class="overflow-visible max-w-full">
        <title>{{ $descricaoAcessivel }}</title>

        @foreach ($barras as $barra)
            <path d="{{ $barra['path'] }}" fill="{{ $cor }}" />

            @if ($tipo === 'barra')
                <text x="{{ $barra['rotulo_x'] }}" y="{{ $barra['rotulo_y'] }}" text-anchor="middle"
                    font-size="10" class="fill-gray-500 dark:fill-gray-400">{{ $barra['rotulo'] }}</text>
                <text x="{{ $barra['valor_x'] }}" y="{{ $barra['valor_y'] }}" text-anchor="middle"
                    font-size="11" font-weight="500" class="fill-gray-700 dark:fill-gray-200"
                    style="font-variant-numeric: tabular-nums">{{ $barra['valor'] }}</text>
            @else
                <text x="{{ $barra['rotulo_x'] }}" y="{{ $barra['rotulo_y'] }}" text-anchor="end"
                    dominant-baseline="middle" font-size="11"
                    class="fill-gray-500 dark:fill-gray-400">{{ $barra['rotulo'] }}</text>
                <text x="{{ $barra['valor_x'] }}" y="{{ $barra['valor_y'] }}" dominant-baseline="middle"
                    font-size="11" font-weight="500" class="fill-gray-700 dark:fill-gray-200"
                    style="font-variant-numeric: tabular-nums">{{ $barra['valor'] }}</text>
            @endif
        @endforeach
    </svg>
</figure>
