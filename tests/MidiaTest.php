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
