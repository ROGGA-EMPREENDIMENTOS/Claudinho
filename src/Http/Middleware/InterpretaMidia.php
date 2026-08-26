<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rogga\Claudinho\Contracts\ResolvedorDeUsuario;
use Rogga\Claudinho\Midia\Recebedor;
use Rogga\Claudinho\Transcricao;

/**
 * Troca o JSON de mídia do gateway pelo que ele quer dizer.
 *
 * Foto, vídeo e áudio chegam no mesmo campo `mensagem` das perguntas, como o JSON
 * do anexo — `{"type":"image/jpeg","uri":"https://..."}`. Repassado assim, o modelo
 * recebe um endereço que não sabe abrir e responde que não entendeu, enquanto o
 * link assinado vence sem ninguém baixar nada.
 *
 * Aqui a mídia é recebida (ver Midia\Recebedor) e o JSON vira a anotação: a
 * descrição da foto, ou a transcrição do áudio. A legenda que veio escrita junto
 * continua sendo a mensagem de quem enviou — ela vem no mesmo JSON, em `text`.
 *
 * É o mais interno da pilha do canal: roda depois do throttle e do token, porque
 * baixar arquivo e chamar a API de visão ou de transcrição é a parte cara da
 * requisição e não pode acontecer antes de o chamador estar autenticado.
 *
 * Está sempre na pilha, e é ele quem decide se tem o que fazer. O interruptor da
 * transcrição mora no BANCO, e o registro da rota acontece no boot de toda
 * requisição da aplicação — ler o banco ali sairia caro justamente nas requisições
 * que nunca falam com o Claudinho. Com tudo desligado, a requisição sai daqui
 * intacta.
 */
final class InterpretaMidia
{
    public function __construct(private readonly Recebedor $recebedor) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // Antes de tudo, inclusive do comoTexto(): sem nada ligado não há mídia que
        // este middleware saiba aproveitar, e reescrever a mensagem para deixá-la
        // igual seria trabalho por nada em cada requisição do canal.
        if (! config('claudinho.api.midias.habilitado', false) && ! Transcricao::habilitada()) {
            return $next($request);
        }

        $mensagem = $this->comoTexto($request);

        if ($mensagem === null || ! preg_match('/[\'"]uri[\'"]/', $mensagem)) {
            return $next($request);
        }

        ['midias' => $midias, 'texto' => $texto] = $this->separar($mensagem);

        if ($midias === []) {
            return $next($request);
        }

        $usuario = $this->usuario($request);

        // Sem saber de quem é, não há a quem entregar o arquivo. Quem responde a
        // esse caso é a aplicação, e a mensagem original — o JSON — é o que ela
        // guarda para repetir depois de resolver quem está falando.
        if ($usuario === null) {
            return $next($request);
        }

        $anotacoes = [];

        foreach ($midias as $midia) {
            $anotacoes[] = $this->recebedor->receber(
                $usuario,
                $midia['tipo'],
                $midia['uri'],
                $midia['legenda'] ?? null,
            );
        }

        // A legenda primeiro: é o que a pessoa escreveu, e o resto é anotação
        // sobre o que chegou junto. O corte respeita o max:4000 que o controller
        // valida logo adiante — mensagem maior que isso derrubaria a requisição.
        $request->merge([
            'mensagem' => Str::limit(trim($texto."\n\n".implode("\n", $anotacoes)), 3900, ''),
        ]);

        return $next($request);
    }

    /**
     * A mensagem como texto, seja ela texto ou o objeto do anexo.
     *
     * O gateway manda o anexo dentro de `mensagem`, mas nem sempre como string:
     * chega também como OBJETO. Nesse formato o controller recusa a requisição
     * inteira na validação (`mensagem` precisa ser string) antes de qualquer
     * coisa — e o sintoma não é anexo perdido, é 422 e nenhuma resposta.
     */
    private function comoTexto(Request $request): ?string
    {
        $mensagem = $request->input('mensagem');

        if (\is_string($mensagem)) {
            return $mensagem;
        }

        if (! \is_array($mensagem)) {
            return null;
        }

        $texto = json_encode($mensagem, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($texto === false) {
            return null;
        }

        $request->merge(['mensagem' => $texto]);

        if (! preg_match('/[\'"]uri[\'"]/', $texto)) {
            Log::warning('Claudinho: mensagem veio como objeto e sem anexo dentro.', [
                'bruto' => Str::limit($texto, 200),
            ]);
        }

        return $texto;
    }

    /**
     * Quem está do outro lado, pelo mesmo resolvedor que o controller usa.
     */
    private function usuario(Request $request): ?Authenticatable
    {
        $classe = (string) config('claudinho.api.resolvedor', '');

        if ($classe === '') {
            return null;
        }

        $resolvedor = app($classe);

        return $resolvedor instanceof ResolvedorDeUsuario
            ? $resolvedor->resolver((string) $request->input('canal', ''), (string) $request->input('identificador', ''))
            : null;
    }

    /**
     * Separa os anexos do que a pessoa escreveu.
     *
     * O gateway manda um JSON por mídia, e a legenda dentro dele (`text`), mas
     * nada impede que venha texto solto em volta — daí a busca por objetos em vez
     * do decode da mensagem inteira. Sem chaves aninhadas de propósito: o objeto
     * de mídia é plano, e um padrão que aceitasse aninhamento abriria espaço para
     * backtracking numa mensagem que vem de fora.
     *
     * @return array{midias: list<array{tipo: string, uri: string, legenda: string|null}>, texto: string}
     */
    private function separar(string $mensagem): array
    {
        preg_match_all('/\{[^{}]*[\'"]uri[\'"][^{}]*\}/', $mensagem, $achados);

        $midias = [];
        $avisos = [];
        $maximo = max(1, (int) config('claudinho.api.midias.max_por_mensagem', 3));

        // Todos saem do texto, inclusive os que passam do limite: JSON sobrando na
        // mensagem é exatamente o que este middleware existe para evitar.
        $texto = str_replace($achados[0], '', $mensagem);

        foreach ($achados[0] as $bruto) {
            $midia = $this->decodificar($bruto);

            if ($midia === null) {
                continue;
            }

            if (\count($midias) >= $maximo) {
                $avisos[] = '[Chegaram mais de '.$maximo.' arquivos de uma vez. Só os '.$maximo
                    .' primeiros foram recebidos — peça o resto um a um, se precisar.]';

                break;
            }

            $midias[] = [
                'tipo' => $midia['type'],
                'uri' => $midia['uri'],
                'legenda' => filled($midia['text'] ?? null) ? trim((string) $midia['text']) : null,
            ];
        }

        $legendas = array_merge(array_filter(array_column($midias, 'legenda')), $avisos);

        return [
            'midias' => $midias,
            'texto' => trim(trim($texto)."\n".implode("\n", $legendas)),
        ];
    }

    /**
     * O objeto do gateway como array.
     *
     * json_decode primeiro: é o formato documentado e o único que trata escape
     * direito. O caminho por campo existe porque já chegou com ASPA SIMPLES —
     * `{'type':'image/jpeg','uri':'...'}` —, que não é JSON, e o decode devolvia
     * null. A mídia sumia em silêncio: a mensagem passava reta e o modelo recebia
     * o endereço cru.
     *
     * @return array{type: string, uri: string, text?: string}|null
     */
    private function decodificar(string $bruto): ?array
    {
        $midia = json_decode($bruto, true);

        if (\is_array($midia) && \is_string($midia['uri'] ?? null) && \is_string($midia['type'] ?? null)) {
            return $midia;
        }

        $tipo = $this->campo('type', $bruto);
        $uri = $this->campo('uri', $bruto);

        if ($tipo === null || $uri === null) {
            // Sem este registro, o formato novo do gateway volta a sumir sem
            // deixar rastro nenhum.
            Log::warning('Claudinho: anexo veio num formato que não deu para ler.', [
                'bruto' => Str::limit($bruto, 200),
            ]);

            return null;
        }

        return array_filter(
            ['type' => $tipo, 'uri' => $uri, 'text' => $this->campo('text', $bruto)],
            static fn (?string $valor): bool => $valor !== null
        );
    }

    /**
     * Um campo do objeto, com aspa simples ou dupla. Valor com aspa dentro sai
     * cortado — e tudo bem: os campos que interessam são tipo e endereço, e
     * legenda truncada é melhor que anexo perdido.
     */
    private function campo(string $nome, string $bruto): ?string
    {
        $padrao = '/[\'"]'.$nome.'[\'"]\s*:\s*[\'"]([^\'"]*)[\'"]/';

        return preg_match($padrao, $bruto, $achado) === 1 && $achado[1] !== '' ? $achado[1] : null;
    }
}
