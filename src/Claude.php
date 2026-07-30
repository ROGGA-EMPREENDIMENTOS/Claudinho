<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Generator;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

/**
 * Transporte da API de mensagens do Claude. Não conhece domínio nenhum:
 * recebe mensagens, system prompt e definições de ferramenta, devolve eventos.
 */
class Claude
{
    private const VERSAO_API = '2023-06-01';

    private string $url = 'https://api.anthropic.com/v1/messages';

    private ?string $apiKey;

    private string $model;

    private int $maxTokens;

    private string $effort;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('claudinho.api_key');
        $this->model = config('claudinho.model');
        $this->maxTokens = (int) config('claudinho.max_tokens');
        $this->effort = config('claudinho.effort');
        $this->timeout = (int) config('claudinho.timeout');
    }

    public function configurado(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mensagens
     */
    public function mensagem(array $mensagens, ?string $system = null): string
    {
        $response = $this->request()->post($this->url, $this->payload($mensagens, $system));

        $this->garanteRespostaValida($response);

        if ($response->json('stop_reason') === 'refusal') {
            throw new RuntimeException($this->mensagemDeRecusa());
        }

        return $this->textoDaResposta($response->json('content', []));
    }

    /**
     * Emite eventos da resposta em tempo real:
     *   ['tipo' => 'texto',    'conteudo' => string]
     *   ['tipo' => 'tool_use', 'id' => string, 'nome' => string, 'input' => array]
     *   ['tipo' => 'fim',      'stop_reason' => string]
     *
     * A requisição só é disparada na primeira iteração — envolva o foreach em try/catch.
     *
     * @param  array<int, array<string, mixed>>  $mensagens
     * @param  array<int, array<string, mixed>>  $tools
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(array $mensagens, ?string $system = null, array $tools = []): Generator
    {
        $response = $this->request()
            ->withOptions(['stream' => true])
            ->post($this->url, $this->payload($mensagens, $system, $tools, stream: true));

        $this->garanteRespostaValida($response);

        $body = $response->toPsrResponse()->getBody();

        $toolsParciais = [];
        $stopReason = null;

        while (! $body->eof()) {
            $linha = trim(Utils::readLine($body));

            if (! str_starts_with($linha, 'data:')) {
                continue;
            }

            $evento = json_decode(trim(substr($linha, 5)), true);

            if (! is_array($evento)) {
                continue;
            }

            $tipo = $evento['type'] ?? null;

            if ($tipo === 'error') {
                throw new RuntimeException($evento['error']['message'] ?? $this->mensagemDeErroPadrao());
            }

            if ($tipo === 'message_delta') {
                $stopReason = $evento['delta']['stop_reason'] ?? $stopReason;

                if ($stopReason === 'refusal') {
                    throw new RuntimeException($this->mensagemDeRecusa());
                }

                continue;
            }

            if ($tipo === 'content_block_start' && ($evento['content_block']['type'] ?? null) === 'tool_use') {
                $toolsParciais[$evento['index']] = [
                    'id' => $evento['content_block']['id'] ?? '',
                    'nome' => $evento['content_block']['name'] ?? '',
                    'json' => '',
                ];

                continue;
            }

            if ($tipo === 'content_block_delta') {
                $delta = $evento['delta'] ?? [];

                if (($delta['type'] ?? null) === 'text_delta') {
                    yield ['tipo' => 'texto', 'conteudo' => $delta['text'] ?? ''];
                }

                if (($delta['type'] ?? null) === 'input_json_delta' && isset($toolsParciais[$evento['index']])) {
                    $toolsParciais[$evento['index']]['json'] .= $delta['partial_json'] ?? '';
                }

                continue;
            }

            if ($tipo === 'content_block_stop' && isset($toolsParciais[$evento['index']])) {
                $tool = $toolsParciais[$evento['index']];
                unset($toolsParciais[$evento['index']]);

                yield [
                    'tipo' => 'tool_use',
                    'id' => $tool['id'],
                    'nome' => $tool['nome'],
                    'input' => json_decode($tool['json'] ?: '{}', true) ?: [],
                ];
            }
        }

        yield ['tipo' => 'fim', 'stop_reason' => $stopReason ?? 'end_turn'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $mensagens
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    private function payload(array $mensagens, ?string $system, array $tools = [], bool $stream = false): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->normalizaMensagens($mensagens),
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => $this->effort],
        ];

        if (filled($system)) {
            // O breakpoint de cache no system cobre também as definições de
            // ferramenta, que vêm antes dele na hierarquia de cache.
            $payload['system'] = [
                [
                    'type' => 'text',
                    'text' => $system,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        }

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /**
     * Tool chamada sem argumentos tem input vazio, que em PHP é array e vira "[]" no
     * JSON. A API exige objeto ("{}"). Normalizar na montagem do payload — e não ao
     * guardar o bloco — garante que a correção sobreviva ao round-trip de estado do
     * Livewire, que rehidrata JSON como array.
     *
     * @param  array<int, array<string, mixed>>  $mensagens
     * @return array<int, array<string, mixed>>
     */
    private function normalizaMensagens(array $mensagens): array
    {
        foreach ($mensagens as &$mensagem) {
            if (! is_array($mensagem['content'] ?? null)) {
                continue;
            }

            foreach ($mensagem['content'] as &$bloco) {
                if (($bloco['type'] ?? null) === 'tool_use' && ($bloco['input'] ?? null) === []) {
                    $bloco['input'] = new stdClass;
                }
            }
        }

        return $mensagens;
    }

    private function request(): PendingRequest
    {
        if (! $this->configurado()) {
            throw new RuntimeException('Integração com o Claude não configurada: defina ANTHROPIC_API_KEY.');
        }

        return Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::VERSAO_API,
            'content-type' => 'application/json',
        ])->timeout($this->timeout);
    }

    private function garanteRespostaValida(Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? $this->mensagemDeErroPadrao());
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function textoDaResposta(array $content): string
    {
        $textos = [];

        foreach ($content as $bloco) {
            if (($bloco['type'] ?? null) === 'text') {
                $textos[] = $bloco['text'] ?? '';
            }
        }

        return trim(implode('', $textos));
    }

    private function mensagemDeErroPadrao(): string
    {
        return 'Falha na comunicação com a API do Claude.';
    }

    private function mensagemDeRecusa(): string
    {
        return 'A solicitação foi recusada pelos filtros de segurança do modelo. Reformule a pergunta.';
    }
}
