<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Rogga\Claudinho\AcaoBase;
use Rogga\Claudinho\Contracts\ResolvedorDeUsuario;
use Rogga\Claudinho\FerramentaBase;
use Rogga\Claudinho\FerramentaRegistry;
use Rogga\Claudinho\Models\Configuracao;
use Rogga\Claudinho\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Monta um corpo SSE no mesmo formato que a API devolve.
 */
function sseBody(array $eventos): string
{
    $linhas = [];

    foreach ($eventos as $evento) {
        $linhas[] = 'event: '.$evento['type'];
        $linhas[] = 'data: '.json_encode($evento);
        $linhas[] = '';
    }

    return implode("\n", $linhas);
}

function fakeStream(array $eventos): void
{
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(sseBody($eventos)),
    ]);
}

/**
 * Uma resposta por volta do loop de ferramentas. Sem sequência, o fake devolve o
 * mesmo tool_use para sempre e o loop só para no max_iteracoes.
 */
function fakeStreams(array ...$rodadas): void
{
    $sequencia = Http::sequence();

    foreach ($rodadas as $eventos) {
        $sequencia->push(sseBody($eventos));
    }

    Http::fake(['api.anthropic.com/v1/messages' => $sequencia]);
}

/**
 * Eventos SSE de uma volta que chama uma ferramenta.
 */
function rodadaToolUse(string $id, string $nome, array $input = [], string $texto = ''): array
{
    $eventos = [];

    if ($texto !== '') {
        $eventos[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $texto]];
    }

    $eventos[] = ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $nome]];
    $eventos[] = ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($input ?: new stdClass)]];
    $eventos[] = ['type' => 'content_block_stop', 'index' => 1];
    $eventos[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']];
    $eventos[] = ['type' => 'message_stop'];

    return $eventos;
}

/**
 * Eventos SSE de uma volta que só devolve texto.
 */
function rodadaTexto(string $texto): array
{
    return [
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $texto]],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
        ['type' => 'message_stop'],
    ];
}

function eventosDe(Generator $stream): array
{
    return iterator_to_array($stream, preserve_keys: false);
}

/**
 * Pula o teste quando não há banco acessível.
 *
 * Checa a CONEXÃO, e não a extensão pdo_sqlite: assim os testes de banco rodam em
 * qualquer ambiente que tenha algum driver configurado, em vez de pular por não ser
 * exatamente sqlite. Um teste que pula é um teste que não protege nada.
 */
function exigeBanco(): void
{
    try {
        DB::connection()->getPdo();
    } catch (Throwable $th) {
        test()->markTestSkipped('Requer um banco acessível para o testbench: '.$th->getMessage());
    }

    // migrate:fresh, e não migrate: banco persistente (MySQL) não recomeça vazio como
    // o :memory: do sqlite. Sem derrubar as tabelas, um teste enxerga as linhas do
    // anterior — e o teste que faz Schema::drop() deixaria a tabela faltando para
    // todos os seguintes, porque a migration já está registrada e não roda de novo.
    test()->artisan('migrate:fresh')->run();

    // A memória de requisição do Configuracao é estática e atravessaria os testes.
    Configuracao::esquecer();
}

/*
|--------------------------------------------------------------------------
| Ferramentas de teste
|--------------------------------------------------------------------------
|
| Ficam aqui, e não dentro de um arquivo de teste, porque o Pest carrega cada
| arquivo isoladamente: fixture definida num deles não existe para os outros, nem
| quando se roda um arquivo só.
|
*/

class CancelarPedido extends AcaoBase
{
    /** @var array<int, array<string, mixed>> */
    public static array $executadas = [];

    protected ?string $permissao = 'pode_cancelar';

    public function __construct(bool $confirmar = true, private bool $explode = false)
    {
        $this->confirmar = $confirmar;
    }

    public function nome(): string
    {
        return 'cancelar_pedido';
    }

    public function descricao(): string
    {
        return 'Cancela um pedido em aberto.';
    }

    public function propriedades(): array
    {
        return ['pedido' => ['type' => 'integer', 'description' => 'Id do pedido']];
    }

    public function obrigatorios(): array
    {
        return ['pedido'];
    }

    public function confirmacao(array $input): string
    {
        return "Cancelar o pedido {$input['pedido']}?";
    }

    public function executar(array $input): array
    {
        static::$executadas[] = $input;

        if ($this->explode) {
            throw new RuntimeException('conexão perdida no meio do update');
        }

        return ['cancelado' => $input['pedido']];
    }
}

class BuscarPedido extends FerramentaBase
{
    public function nome(): string
    {
        return 'buscar_pedido';
    }

    public function descricao(): string
    {
        return 'Busca um pedido pelo id.';
    }

    public function propriedades(): array
    {
        return ['pedido' => ['type' => 'integer', 'description' => 'Id do pedido']];
    }

    public function executar(array $input): array
    {
        return ['pedido' => $input['pedido'], 'situacao' => 'em aberto'];
    }
}

function registro(array $ferramentas): FerramentaRegistry
{
    $registro = new FerramentaRegistry;

    foreach ($ferramentas as $ferramenta) {
        $registro->registrar($ferramenta);
    }

    app()->instance(FerramentaRegistry::class, $registro);

    return $registro;
}

/*
|--------------------------------------------------------------------------
| Endpoint de canais externos
|--------------------------------------------------------------------------
|
| Aqui e não num arquivo de teste: o Pest carrega cada arquivo isoladamente, e em
| ordem alfabética o EndpointConversaTest vem antes do EndpointTest — a fixture
| definida lá não existiria para ele.
|
*/

function comEndpoint(array $config = []): void
{
    test()->comConfig(array_merge([
        'claudinho.api.habilitado' => true,
        'claudinho.api.token' => 'token-do-gateway',
        'claudinho.api.resolvedor' => ResolvedorFake::class,
    ], $config));
}

class ResolvedorFake implements ResolvedorDeUsuario
{
    /** Números que o resolver conhece, no formato identificador => id de usuário. */
    public static array $conhecidos = ['5547999998888' => 7];

    public static array $chamadas = [];

    public function resolver(string $canal, string $identificador): ?Authenticatable
    {
        static::$chamadas[] = [$canal, $identificador];

        $id = static::$conhecidos[$identificador] ?? null;

        if ($id === null) {
            return null;
        }

        $usuario = new class extends User
        {
            protected $table = 'users';
        };

        return $usuario->forceFill(['id' => $id, 'name' => 'Fulano']);
    }
}
