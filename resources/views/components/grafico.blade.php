<figure class="my-3 not-prose">
    <figcaption class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase">
        {{ $titulo }}
    </figcaption>

    <svg viewBox="0 0 {{ $largura }} {{ $altura }}" width="100%" height="auto" role="img"
        aria-label="{{ $descricaoAcessivel }}" class="overflow-visible max-w-full">
        <title>{{ $descricaoAcessivel }}</title>

        @foreach ($barras as $barra)
            <path d="{{ $barra['path'] }}" fill="{{ $cor }}" />

            @if ($tipo === 'barra')
                <text x="{{ $barra['rotulo_x'] }}" y="{{ $barra['rotulo_y'] }}" text-anchor="middle"
                    font-size="10" fill="#6b7280">{{ $barra['rotulo'] }}</text>
                <text x="{{ $barra['valor_x'] }}" y="{{ $barra['valor_y'] }}" text-anchor="middle"
                    font-size="11" font-weight="500" fill="#374151"
                    style="font-variant-numeric: tabular-nums">{{ $barra['valor'] }}</text>
            @else
                <text x="{{ $barra['rotulo_x'] }}" y="{{ $barra['rotulo_y'] }}" text-anchor="end"
                    dominant-baseline="middle" font-size="11" fill="#6b7280">{{ $barra['rotulo'] }}</text>
                <text x="{{ $barra['valor_x'] }}" y="{{ $barra['valor_y'] }}" dominant-baseline="middle"
                    font-size="11" font-weight="500" fill="#374151"
                    style="font-variant-numeric: tabular-nums">{{ $barra['valor'] }}</text>
            @endif
        @endforeach
    </svg>
</figure>
