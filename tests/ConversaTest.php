<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Rogga\Claudinho\Conversa;
use Rogga\Claudinho\FerramentaRegistry;

/**
 * O motor da conversa, sem UI e sem banco.
 *
 * Estes testes existem porque o loop passou a ter dois consumidores — o componente
 * Livewire e o endpoint HTTP. O ChatTest cobre o loop pelo lado da tela; aqui é o
 * contrato que o endpoint usa: estado que sobrevive ao round-trip, texto da rodada,
 * e o modo somente-leitura.
 */
beforeEach(function () {
    Gate::define('pode_cancelar', fn () => true);
    Auth::setUser(new User);
    CancelarPedido::$executadas = [];
});

it('sobrevive ao round-trip do estado', function () {
    fakeStreams(rodadaTexto('São 12 obras ativas.'));

    $conversa = new Conversa;
    $conversa->perguntar('quantas obras ativas?');
    $conversa->responder();

    // É assim que o endpoint guarda no banco e o Livewire serializa: array puro,
    // sem referência a objeto.
    $estado = $conversa->estado();

    expect(json_decode(json_encode($estado), true))->toBe($estado);

    $rehidratada = Conversa::de($estado);

    expect($rehidratada->respostaFinal())->toBe('São 12 obras ativas.')
        ->and($rehidratada->mensagens())->toBe($conversa->mensagens())
        ->and($rehidratada->pausada())->toBeFalse();
});

it('junta o texto de antes e depois da ferramenta na resposta da rodada', function () {
    registro([new BuscarPedido]);

    fakeStreams(
        rodadaToolUse('toolu_q', 'buscar_pedido', ['pedido' => 4821], 'Deixa eu conferir.'),
        rodadaTexto('O pedido 4821 está em aberto.'),
    );

    $conversa = new Conversa;
    $conversa->perguntar('como está o pedido 4821?');
    $conversa->responder();

    // Só o último bloco deixaria "Deixa eu conferir." de fora, e quem lê pelo
    // WhatsApp recebe as duas partes numa mensagem só.
    expect($conversa->respostaFinal())
        ->toBe("Deixa eu conferir.\n\nO pedido 4821 está em aberto.");
});

it('não devolve na resposta os rótulos de consulta, que são só do streaming', function () {
    registro([new BuscarPedido]);

    fakeStreams(
        rodadaToolUse('toolu_q', 'buscar_pedido', ['pedido' => 4821]),
        rodadaTexto('Está em aberto.'),
    );

    $pedacos = [];
    $conversa = new Conversa(aoStreamar: function (string $texto) use (&$pedacos) {
        $pedacos[] = $texto;
    });

    $conversa->perguntar('e o 4821?');
    $conversa->responder();

    // O rótulo aparece para quem assiste ao streaming...
    expect(implode('', $pedacos))->toContain('[consultando buscar_pedido...]')
        // ...e não entra no texto que o endpoint devolve.
        ->and($conversa->respostaFinal())->toBe('Está em aberto.');
});

it('deixa a conversa sem pendência quando o canal não aceita ações', function () {
    registro([new BuscarPedido, new CancelarPedido]);

    // A ação nem é declarada, então o modelo não tem como pedi-la. Aqui o fake
    // insiste em chamá-la de todo jeito, e o registro recusa por nome desconhecido.
    fakeStreams(
        rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]),
        rodadaTexto('Não consigo cancelar por aqui.'),
    );

    $conversa = (new Conversa)->semAcoes();
    $conversa->perguntar('cancela o 4821');
    $conversa->responder();

    expect($conversa->pausada())->toBeFalse()
        ->and(CancelarPedido::$executadas)->toBe([]);

    Http::assertSent(function ($request) {
        $nomes = collect($request['tools'] ?? [])->pluck('name')->all();

        return in_array('buscar_pedido', $nomes, true)
            && ! in_array('cancelar_pedido', $nomes, true);
    });
});

it('não fala de alteração no prompt quando o canal é somente-leitura', function () {
    registro([new CancelarPedido]);
    fakeStreams(rodadaTexto('oi'));

    $conversa = (new Conversa)->semAcoes();
    $conversa->perguntar('oi');
    $conversa->responder();

    // Prometer alteração num canal que não a oferece é convite para o modelo
    // dizer que vai fazer o que não pode.
    Http::assertSent(fn ($request) => str_contains($request['system'][0]['text'], 'somente-leitura')
        && ! str_contains($request['system'][0]['text'], 'ferramentas que alteram dados'));
});

it('põe as instruções do canal no fim do prompt, para vencerem as regras do pacote', function () {
    fakeStreams(rodadaTexto('oi'));

    $conversa = (new Conversa)->comInstrucoes('Não use tabela markdown neste canal.');
    $conversa->perguntar('oi');
    $conversa->responder();

    Http::assertSent(function ($request) {
        $system = $request['system'][0]['text'];

        // Depois da regra que manda usar tabela: instrução mais recente é a que vale.
        return str_contains($system, 'Não use tabela markdown neste canal.')
            && strpos($system, 'Não use tabela markdown neste canal.') > strpos($system, 'use tabela markdown');
    });
});

it('pausa na ação e devolve a frase de confirmação sem executar', function () {
    registro([new CancelarPedido]);
    fakeStreams(rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821], 'Vou cancelar.'));

    $conversa = new Conversa;
    $conversa->perguntar('cancela o 4821');
    $conversa->responder();

    expect($conversa->pausada())->toBeTrue()
        ->and($conversa->pendentes())->toHaveCount(1)
        ->and($conversa->pendentes()[0]['confirmacao'])->toBe('Cancelar o pedido 4821?')
        ->and(CancelarPedido::$executadas)->toBe([]);
});

it('recusa tudo de uma vez e liberta a conversa', function () {
    registro([new CancelarPedido]);
    fakeStreams(rodadaToolUse('toolu_a', 'cancelar_pedido', ['pedido' => 4821]));

    $conversa = new Conversa;
    $conversa->perguntar('cancela o 4821');
    $conversa->responder();

    expect($conversa->recusarTudo())->toBeTrue()
        ->and($conversa->pausada())->toBeFalse()
        ->and(CancelarPedido::$executadas)->toBe([]);

    // O modelo recebe a recusa como resultado, então tem o que comentar.
    $ultima = collect($conversa->mensagens())->last();

    expect($ultima['role'])->toBe('user')
        ->and($ultima['content'][0]['content'])->toContain('"recusada":true');
});

it('fecha o tool_use aberto quando o loop estoura, e propaga o erro', function () {
    registro([new BuscarPedido]);

    Http::fake(['api.anthropic.com/v1/messages' => Http::sequence()
        ->push(sseBody(rodadaToolUse('toolu_q', 'buscar_pedido', ['pedido' => 4821])))
        ->push(json_encode(['error' => ['message' => 'sobrecarregado']]), 529),
    ]);

    $conversa = new Conversa;
    $conversa->perguntar('e o 4821?');

    // Classe concreta, não Throwable: sendo interface, o Pest a trata como mensagem.
    expect(fn () => $conversa->responder())->toThrow(RuntimeException::class, 'sobrecarregado');

    // Sem isto a conversa ficaria com tool_use sem par e a API recusaria tudo
    // dali em diante — inclusive a pergunta seguinte.
    $ultima = collect($conversa->mensagens())->last();

    expect($ultima['role'])->toBe('user')
        ->and($ultima['content'][0]['type'])->toBe('tool_result');
});

it('respeita max_iteracoes para o loop não girar sozinho', function () {
    config()->set('claudinho.max_iteracoes', 2);
    registro([new BuscarPedido]);

    // Sempre a mesma chamada de ferramenta: sem o teto, giraria para sempre gastando
    // token. Sequência de três para sobrar resposta — com fakeStream (singular) o
    // corpo da resposta já vem consumido na segunda leitura e o loop pararia por
    // motivo errado, escondendo o que este teste quer provar.
    $rodada = rodadaToolUse('toolu_q', 'buscar_pedido', ['pedido' => 1]);
    fakeStreams($rodada, $rodada, $rodada);

    $conversa = new Conversa;
    $conversa->perguntar('e o 1?');
    $conversa->responder();

    expect($conversa->estado()['iteracao'])->toBe(2);

    // O que importa de verdade: o teto corta chamadas à API, não só o contador.
    Http::assertSentCount(2);
});

it('devolve a lista de ferramentas cheia quando o canal aceita ações', function () {
    registro([new BuscarPedido, new CancelarPedido]);
    fakeStreams(rodadaTexto('oi'));

    $conversa = new Conversa;
    $conversa->perguntar('oi');
    $conversa->responder();

    Http::assertSent(function ($request) {
        $nomes = collect($request['tools'] ?? [])->pluck('name')->all();

        return in_array('cancelar_pedido', $nomes, true);
    });
});

it('esvazia tudo no limpar', function () {
    fakeStreams(rodadaTexto('oi'));

    $conversa = new Conversa;
    $conversa->perguntar('oi');
    $conversa->responder();

    expect($conversa->vazia())->toBeFalse();

    $conversa->limpar();

    expect($conversa->vazia())->toBeTrue()
        ->and($conversa->estado())->toBe([
            'mensagens' => [], 'pendentes' => [], 'resultados' => [], 'iteracao' => 0,
        ]);
});

it('não vaza ferramenta de outro registro entre conversas', function () {
    // Guarda contra o motor cachear as definições: o registro é resolvido a cada
    // responder(), porque o usuário (e portanto o que ele pode ver) muda.
    registro([new BuscarPedido]);
    fakeStreams(rodadaTexto('a'), rodadaTexto('b'));

    $primeira = new Conversa;
    $primeira->perguntar('oi');
    $primeira->responder();

    app()->instance(FerramentaRegistry::class, new FerramentaRegistry);

    $segunda = new Conversa;
    $segunda->perguntar('oi');
    $segunda->responder();

    Http::assertSent(fn ($request) => ($request['tools'] ?? []) === []);
});
