<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Rogga\Claudinho\AcaoBase;
use Rogga\Claudinho\FerramentaBase;
use Rogga\Claudinho\FerramentaRegistry;
use Rogga\Claudinho\Livewire\Chat;

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

/**
 * @return array<int, array<string, mixed>>
 */
function toolResultsDaConversa(array $conversa): array
{
    $resultados = [];

    foreach ($conversa as $mensagem) {
        foreach (is_array($mensagem['content']) ? $mensagem['content'] : [] as $bloco) {
            if (($bloco['type'] ?? null) === 'tool_result') {
                $resultados[$bloco['tool_use_id']] = $bloco['content'];
            }
        }
    }

    return $resultados;
}

beforeEach(function () {
    CancelarPedido::$executadas = [];

    Gate::define('pode_cancelar', fn () => true);
    Auth::setUser(new Illuminate\Foundation\Auth\User);
});

it('marca na descrição enviada ao modelo que a ferramenta altera dados', function () {
    $definicao = collect(registro([new CancelarPedido])->definicoes())->firstWhere('name', 'cancelar_pedido');

    expect($definicao['description'])
        ->toContain('Cancela um pedido em aberto.')
        ->toContain('ALTERA DADOS')
        ->toContain('interface pede confirmação');
});

it('avisa que o efeito é imediato quando a ação dispensa confirmação', function () {
    $definicao = collect(registro([new CancelarPedido(confirmar: false)])->definicoes())->firstWhere('name', 'cancelar_pedido');

    expect($definicao['description'])->toContain('efeito é imediato');
});

it('sabe se há ação exposta ao usuário atual', function () {
    expect(registro([new BuscarPedido])->temAcoes())->toBeFalse()
        ->and(registro([new BuscarPedido, new CancelarPedido])->temAcoes())->toBeTrue();

    // Gate negado esconde a ação, e com isso o chat volta a ser só de consulta.
    Gate::define('pode_cancelar', fn () => false);

    expect(registro([new CancelarPedido])->temAcoes())->toBeFalse();
});

it('nega ação que esqueceu de declarar o gate, em vez de liberar', function () {
    $semGate = new class extends AcaoBase
    {
        public function nome(): string
        {
            return 'apagar_tudo';
        }

        public function descricao(): string
        {
            return 'Apaga.';
        }

        public function propriedades(): array
        {
            return [];
        }

        public function confirmacao(array $input): string
        {
            return 'Apagar?';
        }

        public function executar(array $input): array
        {
            return ['apagou' => true];
        }
    };

    // Numa Ferramenta de leitura, permissao null libera (é o caso do gráfico).
    expect($semGate->permitida())->toBeFalse()
        ->and(registro([$semGate])->definicoes())->toBe([])
        ->and(registro([$semGate])->executar('apagar_tudo', [])['erro'])->toContain('sem permissão');
});

it('pausa o loop na ação, sem executar nada, e mostra a confirmação', function () {
    registro([new CancelarPedido]);
    fakeStreams(rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821], 'Vou cancelar.'));

    $componente = Livewire::test(Chat::class)
        ->set('pergunta', 'cancela o pedido 4821')
        ->call('enviar')
        ->call('responder');

    expect(CancelarPedido::$executadas)->toBe([])
        ->and($componente->get('pendentes'))->toHaveCount(1)
        ->and($componente->get('pendentes')[0]['confirmacao'])->toBe('Cancelar o pedido 4821?')
        // Loop parado: nem respondendo, nem tool_result gravado.
        ->and($componente->get('respondendo'))->toBeFalse()
        ->and(toolResultsDaConversa($componente->get('conversa')))->toBe([]);

    $componente
        ->assertSee('Cancelar o pedido 4821?')
        ->assertSee('Confirmar e executar')
        ->assertSee('Nada foi alterado ainda')
        ->assertSee('Aguardando confirmação: cancelar_pedido (pedido: 4821)');
});

it('executa e retoma o loop quando o usuário confirma', function () {
    registro([new CancelarPedido]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Pedido 4821 cancelado.'),
    );

    $componente = Livewire::test(Chat::class)
        ->set('pergunta', 'cancela o pedido 4821')
        ->call('enviar')
        ->call('responder')
        ->call('confirmar', 'toolu_a');

    expect(CancelarPedido::$executadas)->toBe([['pedido' => 4821]])
        ->and($componente->get('pendentes'))->toBe([])
        // respondendo volta a true: é o wire:init que continua o loop de onde parou.
        ->and($componente->get('respondendo'))->toBeTrue()
        ->and(toolResultsDaConversa($componente->get('conversa'))['toolu_a'])->toContain('"cancelado":4821');

    $componente->call('responder')
        ->assertSee('Pedido 4821 cancelado.')
        ->assertSee('Alterou dados: cancelar_pedido (pedido: 4821)');
});

it('devolve a recusa ao modelo sem executar quando o usuário cancela', function () {
    registro([new CancelarPedido]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Ok, não cancelei nada.'),
    );

    $componente = Livewire::test(Chat::class)
        ->set('pergunta', 'cancela o pedido 4821')
        ->call('enviar')
        ->call('responder')
        ->call('recusar', 'toolu_a');

    expect(CancelarPedido::$executadas)->toBe([])
        ->and(toolResultsDaConversa($componente->get('conversa'))['toolu_a'])
        ->toContain('"recusada":true');

    $componente->call('responder')
        ->assertSee('Ok, não cancelei nada.')
        // Rótulo distinto do "Alterou dados": o histórico não pode sugerir que mudou algo.
        ->assertSee('Alteração não autorizada pelo usuário: cancelar_pedido (pedido: 4821)');
});

it('ignora clique repetido em vez de aplicar o efeito duas vezes', function () {
    registro([new CancelarPedido]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Feito.'),
    );

    Livewire::test(Chat::class)
        ->set('pergunta', 'cancela o pedido 4821')
        ->call('enviar')
        ->call('responder')
        ->call('confirmar', 'toolu_a')
        ->call('confirmar', 'toolu_a');

    expect(CancelarPedido::$executadas)->toHaveCount(1);
});

it('barra pergunta nova enquanto a ação está pendente', function () {
    registro([new CancelarPedido]);
    fakeStreams(rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]));

    $componente = Livewire::test(Chat::class)
        ->set('pergunta', 'cancela o pedido 4821')
        ->call('enviar')
        ->call('responder');

    $antes = $componente->get('conversa');

    $componente->set('pergunta', 'e o 4822?')->call('enviar');

    // Deixar a pergunta entrar aqui deixaria um tool_use sem tool_result e a API
    // rejeitaria a conversa inteira.
    expect($componente->get('conversa'))->toBe($antes)
        ->and($componente->get('respondendo'))->toBeFalse();

    $componente->assertSee('Confirme ou cancele a alteração acima');
});

it('executa a consulta da mesma volta e guarda os dois tool_results para sair juntos', function () {
    registro([new BuscarPedido, new CancelarPedido]);

    fakeStreams([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_q', 'name' => 'buscar_pedido']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"pedido": 4821}']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'cancelar_pedido']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"pedido": 4821}']],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
    ], rodadaTexto('Cancelado.'));

    $componente = Livewire::test(Chat::class)
        ->set('pergunta', 'confere e cancela o 4821')
        ->call('enviar')
        ->call('responder');

    // A consulta já rodou, mas o resultado dela fica retido: a API exige que todo
    // tool_use da mensagem do assistente seja respondido de uma vez.
    expect($componente->get('pendentes'))->toHaveCount(1)
        ->and($componente->get('resultados'))->toHaveCount(1)
        ->and(toolResultsDaConversa($componente->get('conversa')))->toBe([]);

    $componente->call('confirmar', 'toolu_a');

    expect(array_keys(toolResultsDaConversa($componente->get('conversa'))))->toBe(['toolu_q', 'toolu_a'])
        ->and($componente->get('resultados'))->toBe([]);
});

it('executa direto a ação que dispensa confirmação, e ainda marca o rótulo', function () {
    registro([new CancelarPedido(confirmar: false)]);
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Cancelado.'),
    );

    $componente = Livewire::test(Chat::class)
        ->set('pergunta', 'cancela o pedido 4821')
        ->call('enviar')
        ->call('responder');

    expect(CancelarPedido::$executadas)->toBe([['pedido' => 4821]])
        ->and($componente->get('pendentes'))->toBe([]);

    $componente->assertSee('Alterou dados: cancelar_pedido (pedido: 4821)');
});

it('fecha o tool_use aberto quando a ação estoura, para a conversa seguir válida', function () {
    registro([new CancelarPedido(confirmar: false, explode: true)]);
    fakeStreams(rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]));

    $componente = Livewire::test(Chat::class)
        ->set('pergunta', 'cancela o pedido 4821')
        ->call('enviar')
        ->call('responder');

    // Sem isto a conversa ficava com um tool_use sem par e a API recusava tudo
    // dali em diante — inclusive tendo a alteração possivelmente sido aplicada.
    expect(toolResultsDaConversa($componente->get('conversa'))['toolu_a'])
        ->toContain('conexão perdida')
        ->and($componente->get('respondendo'))->toBeFalse()
        ->and($componente->get('pendentes'))->toBe([]);

    $componente->assertDispatched('claudinho-erro');
});

it('só fala de alteração no system prompt quando há ação exposta', function () {
    registro([new BuscarPedido]);
    fakeStreams(rodadaTexto('oi'));

    Livewire::test(Chat::class)->set('pergunta', 'oi')->call('enviar')->call('responder');

    Http::assertSent(function ($request) {
        $system = $request['system'][0]['text'];

        return str_contains($system, 'somente-leitura')
            && ! str_contains($system, 'ferramentas que alteram dados');
    });
});

it('instrui o modelo a chamar a ação em vez de pedir permissão por texto', function () {
    registro([new BuscarPedido, new CancelarPedido]);
    fakeStreams(rodadaTexto('oi'));

    Livewire::test(Chat::class)->set('pergunta', 'oi')->call('enviar')->call('responder');

    Http::assertSent(function ($request) {
        $system = $request['system'][0]['text'];

        return str_contains($system, 'ferramentas que alteram dados')
            && str_contains($system, 'Quem pede a confirmação é a interface')
            && str_contains($system, '"recusada"')
            // Dizer "somente-leitura" aqui seria o prompt desautorizando as
            // ferramentas que a requisição acabou de declarar.
            && ! str_contains($system, 'somente-leitura');
    });
});
