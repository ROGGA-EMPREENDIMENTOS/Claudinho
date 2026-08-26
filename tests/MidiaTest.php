<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Rogga\Claudinho\Contracts\DestinoDeMidia;
use Rogga\Claudinho\Midia\MidiaRecebida;

/**
 * Foto e vídeo que chegam pelo canal externo.
 *
 * O gateway não manda o arquivo: manda o tipo e uma URI assinada de meia hora, e
 * repassar isso ao modelo é entregar um endereço que ele não sabe abrir. Cada
 * caso aqui saiu de uma falha real em produção.
 */
const FOTO = 'https://gateway.exemplo.com/media/abc?sig=xyz';

beforeEach(function () {
    comEndpoint([
        'claudinho.api.midias.habilitado' => true,
        'claudinho.api.midias.hosts' => ['gateway.exemplo.com'],
        'claudinho.api.midias.destino' => DestinoFake::class,
    ]);
    exigeBanco();

    DestinoFake::$recebidas = [];
});

function anexo(string $tipo = 'image/jpeg', array $extra = []): string
{
    return json_encode(array_merge(['type' => $tipo, 'uri' => FOTO], $extra), JSON_UNESCAPED_SLASHES);
}

/**
 * Nome próprio, e não `conversar()`: aquela vive no EndpointConversaTest e é de
 * escopo de arquivo — declarar as duas quebra a suíte inteira.
 */
function mandaAoCanal(string $mensagem): TestResponse
{
    return test()->withToken('token-do-gateway')->postJson('/claudinho/conversa', [
        'canal' => 'whatsapp',
        'identificador' => '5547999998888',
        'mensagem' => $mensagem,
    ]);
}

function baixaFoto(string $conteudo = 'os-bytes-da-foto'): void
{
    Http::fake(['gateway.exemplo.com/*' => Http::response($conteudo)]);
}

/**
 * A visão e, em seguida, a resposta do assistente — nesta ordem, porque o
 * middleware roda antes do controller. Um fake() só: chamar duas vezes soma os
 * stubs e o primeiro vence, e aí a chamada de visão recebia corpo SSE.
 */
function veFoto(string $descricao): void
{
    httpClaude()->fake([
        'api.anthropic.com/v1/messages' => httpClaude()->sequence()
            ->push(json_encode(['content' => [['type' => 'text', 'text' => $descricao]], 'stop_reason' => 'end_turn']))
            ->push(sseBody(rodadaTexto('Entendi, vou registrar.'))),
    ]);
}

it('troca o JSON do gateway pela descrição do que aparece na foto', function () {
    baixaFoto();
    veFoto('Parede com mancha escura de infiltração.');

    mandaAoCanal(anexo())->assertOk();

    expect(DestinoFake::$recebidas)->toHaveCount(1)
        ->and(DestinoFake::$recebidas[0]->descricao)->toContain('mancha escura de infiltração')
        ->and(DestinoFake::$recebidas[0]->conteudo)->toBe('os-bytes-da-foto');
});

it('deixa a aplicação acrescentar a própria frase à anotação', function () {
    baixaFoto();
    veFoto('Parede com mancha.');

    mandaAoCanal(anexo())->assertOk();

    $mensagem = mensagemQueChegouAoModelo();

    expect($mensagem)->toContain('Foto recebida nesta conversa')
        ->and($mensagem)->toContain('vai como anexo do chamado');
});

it('descreve mesmo sem destino configurado', function () {
    config(['claudinho.api.midias.destino' => null]);
    baixaFoto();
    veFoto('Parede com mancha.');

    mandaAoCanal(anexo())->assertOk();

    expect(mensagemQueChegouAoModelo())->toContain('Parede com mancha');
});

/**
 * O anexo chegou de verdade assim, com aspa simples, e o json_decode devolvia
 * null: a mensagem passava reta e o modelo recebia o endereço cru.
 */
it('lê o anexo com aspa simples', function () {
    baixaFoto();
    veFoto('Parede com mancha.');

    mandaAoCanal("{'type':'image/jpeg','uri':'".FOTO."'}")->assertOk();

    expect(DestinoFake::$recebidas)->toHaveCount(1);
});

/**
 * Como objeto, o controller recusava a requisição inteira na validação — o
 * sintoma não era anexo perdido, era 422 e nenhuma resposta.
 */
it('lê o anexo que vem como objeto em vez de texto', function () {
    baixaFoto();
    veFoto('Parede com mancha.');

    test()->withToken('token-do-gateway')
        ->postJson('/claudinho/conversa', [
            'canal' => 'whatsapp',
            'identificador' => '5547999998888',
            'mensagem' => ['type' => 'image/jpeg', 'uri' => FOTO],
        ])
        ->assertOk();

    expect(DestinoFake::$recebidas)->toHaveCount(1);
});

it('mantém a legenda como a mensagem de quem enviou', function () {
    baixaFoto();
    veFoto('Parede com mancha.');

    mandaAoCanal(anexo('image/jpeg', ['text' => 'isso aqui é normal?']))->assertOk();

    expect(mensagemQueChegouAoModelo())->toStartWith('isso aqui é normal?');
});

/**
 * O gateway erra o tipo: chegou image/jpeg apontando para um .webp, e a API
 * recusava a imagem inteira.
 */
it('usa o tipo dos bytes, não o que o gateway declarou', function () {
    baixaFoto(base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAUAmJaQAA3AA/v02aAA=', true));
    veFoto('Parede com mancha.');

    mandaAoCanal(anexo('image/jpeg'))->assertOk();

    expect(DestinoFake::$recebidas[0]->tipo)->toBe('image/webp')
        ->and(DestinoFake::$recebidas[0]->nome)->toEndWith('.webp');
});

it('anexa o vídeo sem descrever e pede a descrição em texto', function () {
    baixaFoto();
    fakeStream(rodadaTexto('Recebi seu vídeo.'));

    mandaAoCanal(anexo('video/mp4'))->assertOk();

    expect(DestinoFake::$recebidas)->toHaveCount(1)
        ->and(DestinoFake::$recebidas[0]->descricao)->toBeNull()
        ->and(mensagemQueChegouAoModelo())->toContain('Não consigo assistir a vídeo');
});

/**
 * A URI vem DENTRO da mensagem: qualquer um pode digitar o endereço da rede
 * interna no WhatsApp e o gateway repassa como texto.
 */
it('recusa endereço fora da lista sem pedir reenvio', function () {
    baixaFoto();
    fakeStream(rodadaTexto('ok'));

    mandaAoCanal(anexo('image/jpeg', ['uri' => 'https://169.254.169.254/latest/meta-data/']))->assertOk();

    Http::assertNothingSent();
    expect(mensagemQueChegouAoModelo())->toContain('NÃO peça reenvio');
});

it('recusa a rede interna mesmo com a lista de hosts vazia', function () {
    config(['claudinho.api.midias.hosts' => []]);
    baixaFoto();
    fakeStream(rodadaTexto('ok'));

    foreach (['169.254.169.254', '127.0.0.1', '10.0.0.5'] as $interno) {
        mandaAoCanal(anexo('image/jpeg', ['uri' => "https://{$interno}/x.jpg"]))->assertOk();
    }

    Http::assertNothingSent();
    expect(DestinoFake::$recebidas)->toBeEmpty();
});

it('não segue redirecionamento', function () {
    Http::fake([
        'gateway.exemplo.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
    ]);
    fakeStream(rodadaTexto('ok'));

    mandaAoCanal(anexo())->assertOk();

    expect(DestinoFake::$recebidas)->toBeEmpty()
        ->and(mensagemQueChegouAoModelo())->toContain('não chegou até mim');
});

it('recusa tipo que a aplicação não aceita', function () {
    baixaFoto();
    fakeStream(rodadaTexto('ok'));

    mandaAoCanal(anexo('application/pdf'))->assertOk();

    Http::assertNothingSent();
    expect(mensagemQueChegouAoModelo())->toContain('não é aceito neste canal');
});

it('fica desligado por padrão', function () {
    // comEndpoint, e não config()->set: o middleware entra na pilha no boot do
    // provider, e um set depois de a aplicação ter subido não tira rota nenhuma.
    comEndpoint(['claudinho.api.midias.habilitado' => false]);
    exigeBanco();

    baixaFoto();
    fakeStream(rodadaTexto('ok'));

    mandaAoCanal(anexo())->assertOk();

    Http::assertNothingSent();
    expect(DestinoFake::$recebidas)->toBeEmpty()
        ->and(mensagemQueChegouAoModelo())->toContain('gateway.exemplo.com');
});

/**
 * O destino é da aplicação e pode estourar. A descrição já existe e é o que o
 * modelo precisa: perder o arquivo é o preço, perder a conversa não.
 */
it('responde mesmo quando o destino da aplicação falha', function () {
    config(['claudinho.api.midias.destino' => DestinoQueEstoura::class]);
    baixaFoto();
    veFoto('Parede com mancha.');

    mandaAoCanal(anexo())->assertOk();

    expect(mensagemQueChegouAoModelo())->toContain('Parede com mancha');
});

/**
 * A última mensagem de usuário que o pacote mandou à API — é o que o modelo leu.
 */
function mensagemQueChegouAoModelo(): string
{
    foreach (collect(httpClaude()->recorded())->reverse() as [$requisicao]) {
        foreach (array_reverse((array) ($requisicao->data()['messages'] ?? [])) as $mensagem) {
            if (($mensagem['role'] ?? '') === 'user' && \is_string($mensagem['content'] ?? null)) {
                return $mensagem['content'];
            }
        }
    }

    return '';
}

class DestinoFake implements DestinoDeMidia
{
    /** @var list<MidiaRecebida> */
    public static array $recebidas = [];

    public function guardar(Authenticatable $usuario, MidiaRecebida $midia): ?string
    {
        static::$recebidas[] = $midia;

        return 'Ele já está guardado e vai como anexo do chamado que você abrir.';
    }
}

class DestinoQueEstoura implements DestinoDeMidia
{
    public function guardar(Authenticatable $usuario, MidiaRecebida $midia): ?string
    {
        throw new RuntimeException('disco cheio');
    }
}

/**
 * O mergeConfigFrom é raso: quem publicou o config antes da 1.7 tem o bloco `api`
 * inteiro dele, e a chave `midias` deste release não chega lá. Sem padrão dentro
 * do pacote, ligar a funcionalidade num consumidor antigo recusaria toda mídia
 * com "tipo não aceito", e o motivo não estaria em lugar nenhum que ele olhasse.
 */
it('funciona com o config publicado de uma versão anterior', function () {
    comEndpoint([
        'claudinho.api.midias' => ['habilitado' => true, 'destino' => DestinoFake::class],
    ]);
    exigeBanco();
    DestinoFake::$recebidas = [];

    Http::fake(['*' => Http::response('os-bytes-da-foto')]);
    veFoto('Parede com mancha.');

    mandaAoCanal(anexo())->assertOk();

    expect(DestinoFake::$recebidas)->toHaveCount(1);
});

/**
 * O gateway entrega o áudio, e o Google entrega o que foi dito nele — nesta
 * ordem, num fake só: chamar Http::fake() duas vezes soma os stubs e o primeiro
 * vence, e aí a chamada ao Google receberia os bytes do arquivo como resposta.
 */
function ouveAudio(string $texto, string $conteudo = ''): void
{
    Http::fake([
        'gateway.exemplo.com/*' => Http::response($conteudo === '' ? opus() : $conteudo),
        'speech.googleapis.com/*' => Http::response(
            ['results' => [['alternatives' => [['transcript' => $texto]]]]]
        ),
    ]);
}

it('troca o JSON do gateway pela transcrição do que foi dito no áudio', function () {
    config(['claudinho.transcricao.habilitado' => true, 'claudinho.transcricao.chave' => 'AIza-de-teste']);
    ouveAudio('preciso remarcar a vistoria de amanhã');
    fakeStream(rodadaTexto('Remarcada.'));

    mandaAoCanal(anexo('audio/ogg; codecs=opus'))->assertOk();

    // O modelo lê texto, e não um endereço que ele não sabe abrir.
    expect(mensagemQueChegouAoModelo())
        ->toContain('preciso remarcar a vistoria de amanhã')
        ->not->toContain('gateway.exemplo.com');
});

it('entrega o áudio ao destino da aplicação já com a transcrição junto', function () {
    config(['claudinho.transcricao.habilitado' => true, 'claudinho.transcricao.chave' => 'AIza-de-teste']);
    ouveAudio('o portão não fecha');
    fakeStream(rodadaTexto('Anotado.'));

    mandaAoCanal(anexo('audio/ogg'))->assertOk();

    // Junto, e não depois: sem isto a aplicação teria de transcrever de novo para
    // guardar o arquivo e o texto lado a lado.
    expect(DestinoFake::$recebidas)->toHaveCount(1)
        ->and(DestinoFake::$recebidas[0]->transcricao)->toBe('o portão não fecha')
        ->and(DestinoFake::$recebidas[0]->descricao)->toBeNull()
        ->and(DestinoFake::$recebidas[0]->nome)->toEndWith('.ogg');
});

it('diz que não escuta áudio quando a transcrição está desligada, sem baixar nada', function () {
    // Não é "tipo não aceito": a diferença muda o que a pessoa faz em seguida —
    // mandar o recado por escrito, em vez de reenviar achando que o envio falhou.
    config(['claudinho.transcricao.habilitado' => false]);
    Http::fake();
    fakeStream(rodadaTexto('ok'));

    mandaAoCanal(anexo('audio/ogg'))->assertOk();

    Http::assertNothingSent();
    expect(mensagemQueChegouAoModelo())->toContain('não escuto áudio');
});

it('recebe áudio mesmo com foto e vídeo desligados', function () {
    // Os dois interruptores são independentes: quem só quer transcrever recado de
    // voz não deveria precisar ligar o download de imagem para isso.
    comEndpoint([
        'claudinho.api.midias.habilitado' => false,
        'claudinho.api.midias.hosts' => ['gateway.exemplo.com'],
        'claudinho.api.midias.destino' => DestinoFake::class,
        'claudinho.transcricao.habilitado' => true,
        'claudinho.transcricao.chave' => 'AIza-de-teste',
    ]);
    exigeBanco();
    DestinoFake::$recebidas = [];

    ouveAudio('bom dia, tudo certo por aí?');
    fakeStream(rodadaTexto('Tudo.'));

    mandaAoCanal(anexo('audio/ogg'))->assertOk();

    expect(mensagemQueChegouAoModelo())->toContain('bom dia, tudo certo por aí?');

    // E a foto continua recusada, porque o interruptor dela segue desligado.
    mandaAoCanal(anexo('image/jpeg'))->assertOk();

    expect(mensagemQueChegouAoModelo())->toContain('não é aceito neste canal');
});

it('não manda reenviar o áudio que passa de um minuto', function () {
    config(['claudinho.transcricao.habilitado' => true, 'claudinho.transcricao.chave' => 'AIza-de-teste']);
    Http::fake([
        'gateway.exemplo.com/*' => Http::response(opus()),
        'speech.googleapis.com/*' => Http::response(
            ['error' => ['message' => 'Sync input too long. Use LongRunningRecognize.']], 400
        ),
    ]);
    fakeStream(rodadaTexto('ok'));

    mandaAoCanal(anexo('audio/ogg'))->assertOk();

    // Reenviar o mesmo áudio daria no mesmo, e o modelo precisa saber disso para
    // não pedir de novo.
    expect(mensagemQueChegouAoModelo())
        ->toContain('passa de um minuto')
        ->toContain('NÃO peça o reenvio');
});
