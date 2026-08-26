<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rogga\Claudinho\Midia\Transcritor;

// Sem banco: o Transcritor só lê config (a chave gravada em tela cai no config
// quando não há tabela), e o que se prova aqui é o que vai e o que volta da API do
// Google. O caminho de ponta a ponta — gateway, download, anotação — está no
// MidiaTest, que já monta o endpoint.

const GOOGLE = 'speech.googleapis.com/*';

beforeEach(function () {
    config()->set('claudinho.transcricao.habilitado', true);
    config()->set('claudinho.transcricao.chave', 'AIza-chave-de-teste-do-google');
    config()->set('claudinho.transcricao.idioma', 'pt-BR');
});

/**
 * Um MP3 só com o primeiro quadro. `$b1` carrega a versão do MPEG e `$b2`, o
 * índice da taxa.
 */
function mp3(int $b1 = 0xFB, int $b2 = 0x90): string
{
    return "\xFF".\chr($b1).\chr($b2)."\x00".str_repeat("\x00", 32);
}

function googleResponde(array $corpo, int $status = 200): void
{
    Http::fake([GOOGLE => Http::response($corpo, $status)]);
}

function falou(string $texto): void
{
    googleResponde(['results' => [['alternatives' => [['transcript' => $texto, 'confidence' => 0.97]]]]]);
}

/** O corpo que foi realmente enviado ao Google. */
function corpoEnviado(): array
{
    $enviadas = [];

    Http::assertSent(function (Request $request) use (&$enviadas): bool {
        $enviadas[] = $request->data();

        return true;
    });

    return $enviadas[0] ?? [];
}

it('manda o áudio ao Google e devolve o que foi dito', function () {
    falou('preciso remarcar a vistoria de amanhã');

    $resultado = (new Transcritor)->transcrever('audio/ogg', opus());

    expect($resultado)->toBe(['texto' => 'preciso remarcar a vistoria de amanhã', 'motivo' => 'ok']);

    $corpo = corpoEnviado();

    expect($corpo['config']['encoding'])->toBe('OGG_OPUS')
        ->and($corpo['config']['languageCode'])->toBe('pt-BR')
        // Sem isto o texto chega numa linha só, sem vírgula nem ponto — e é ele que
        // o modelo vai ler como se fosse a mensagem digitada por quem enviou.
        ->and($corpo['config']['enableAutomaticPunctuation'])->toBeTrue()
        ->and(base64_decode($corpo['audio']['content'], true))->toBe(opus());
});

it('aceita o tipo com parâmetro, que é como o WhatsApp manda', function () {
    falou('bom dia');

    // `audio/ogg; codecs=opus`. Comparado inteiro não casa com lista nenhuma.
    expect((new Transcritor)->transcrever('audio/ogg; codecs=opus', opus())['motivo'])->toBe('ok');

    expect(corpoEnviado()['config']['encoding'])->toBe('OGG_OPUS');
});

it('lê a taxa no cabeçalho do próprio arquivo', function () {
    // Errar a taxa não degrada a transcrição: a API recusa o áudio inteiro. E ela
    // varia com quem gravou, então não há palpite que sirva para todos.
    falou('oi');

    (new Transcritor)->transcrever('audio/ogg', opus(48000));

    expect(corpoEnviado()['config']['sampleRateHertz'])->toBe(48000);
});

it('usa a taxa da gravação de voz quando o cabeçalho traz uma que o Google recusa', function () {
    falou('oi');

    // 44100 não está entre as taxas que o Google aceita para Opus, e mandá-la
    // faria a API recusar com "sample rate must be one of".
    (new Transcritor)->transcrever('audio/ogg', opus(44100));

    expect(corpoEnviado()['config']['sampleRateHertz'])->toBe(16000);
});

it('lê a taxa do MP3 em vez de chutar a de música', function () {
    falou('oi');

    // Recado de voz costuma vir em 16 kHz, não nos 44,1 de música: chutar o valor
    // de música recusaria justamente o arquivo que interessa.
    (new Transcritor)->transcrever('audio/mpeg', mp3(0xF3, 0x08));

    expect(corpoEnviado()['config'])->toMatchArray(['encoding' => 'MP3', 'sampleRateHertz' => 16000]);
});

it('pula a etiqueta ID3 antes de procurar o quadro do MP3', function () {
    falou('oi');

    // A etiqueta vem antes do áudio e é texto: o sincronismo do quadro seria
    // encontrado dentro do nome da música.
    $etiqueta = "ID3\x03\x00\x00\x00\x00\x00\x20".str_repeat("\xFF", 32);

    (new Transcritor)->transcrever('audio/mpeg', $etiqueta.mp3(0xF3, 0x08));

    expect(corpoEnviado()['config']['sampleRateHertz'])->toBe(16000);
});

it('deixa o WAV responder por si', function () {
    falou('oi');

    // WAV e FLAC são os únicos dois em que a API lê o cabeçalho. Mandar um palpite
    // no lugar faria ela recusar um áudio que leria sozinha.
    (new Transcritor)->transcrever('audio/wav', 'RIFF....WAVE');

    expect(corpoEnviado()['config'])
        ->not->toHaveKey('encoding')
        ->not->toHaveKey('sampleRateHertz');
});

it('emenda os trechos que a API devolveu', function () {
    // A API quebra o áudio em trechos, um `results` por trecho, e cada um com as
    // alternativas da mais provável para a menos.
    googleResponde(['results' => [
        ['alternatives' => [['transcript' => 'preciso remarcar a vistoria'], ['transcript' => 'errada']]],
        ['alternatives' => [['transcript' => ' de amanhã de manhã']]],
    ]]);

    expect((new Transcritor)->transcrever('audio/ogg', opus())['texto'])
        ->toBe('preciso remarcar a vistoria de amanhã de manhã');
});

it('separa "não entendi nada" de "falhou"', function () {
    // Resposta boa e sem nada dentro é o áudio mudo, o ruído e o recado em outro
    // idioma. Reenviar o mesmo arquivo dá no mesmo, então não pode virar "peça o
    // reenvio".
    googleResponde([]);

    expect((new Transcritor)->transcrever('audio/ogg', opus()))
        ->toBe(['texto' => null, 'motivo' => 'vazio']);
});

it('reconhece o áudio acima de um minuto pelo texto do erro', function () {
    // A API não devolve código próprio: é um INVALID_ARGUMENT como outro qualquer,
    // e o que distingue está na frase. Sem isto o assistente mandaria reenviar um
    // áudio que vai ser recusado de novo.
    googleResponde(['error' => [
        'code' => 400,
        'message' => 'Sync input too long. For audio longer than 1 min use LongRunningRecognize.',
    ]], 400);

    expect((new Transcritor)->transcrever('audio/ogg', opus())['motivo'])->toBe('longo');
});

it('trata erro de outra natureza como falha', function () {
    googleResponde(['error' => ['code' => 403, 'message' => 'API key not valid.']], 403);

    expect((new Transcritor)->transcrever('audio/ogg', opus()))
        ->toBe(['texto' => null, 'motivo' => 'falha']);
});

it('não chama a API com formato que ela não decodifica', function () {
    Http::fake();

    // AAC e M4A: o WhatsApp manda Opus na gravação de voz, mas arquivo encaminhado
    // vem em qualquer coisa. Baixar já foi feito; subir para receber 400, não.
    expect((new Transcritor)->transcrever('audio/mp4', 'ftypM4A '))
        ->toBe(['texto' => null, 'motivo' => 'formato']);

    Http::assertNothingSent();
});

it('não chama a API com áudio acima do teto do modo síncrono', function () {
    Http::fake();

    // O áudio vai embutido no JSON, em base64, e ainda infla um terço no caminho.
    $resultado = (new Transcritor)->transcrever('audio/ogg', opus().str_repeat('a', 11 * 1024 * 1024));

    expect($resultado['motivo'])->toBe('longo');

    Http::assertNothingSent();
});

it('não chama a API sem chave', function () {
    Http::fake();

    config()->set('claudinho.transcricao.chave', null);

    expect((new Transcritor)->transcrever('audio/ogg', opus()))
        ->toBe(['texto' => null, 'motivo' => 'falha']);

    Http::assertNothingSent();
});

it('não deixa a chave do Google vazar para o log', function () {
    Log::spy();

    // A chave viaja na query string, que é como a API do Google aceita chave de
    // projeto — e a mensagem de erro do cliente HTTP traz a URL inteira. Sem a
    // troca, o log passaria a guardar a credencial em texto puro a cada falha.
    Http::fake([GOOGLE => function (): void {
        throw new ConnectionException(
            'cURL error 28: Operation timed out for https://speech.googleapis.com/v1/speech:recognize'
            .'?key=AIza-chave-de-teste-do-google'
        );
    }]);

    expect((new Transcritor)->transcrever('audio/ogg', opus())['motivo'])->toBe('falha');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $titulo, array $contexto): bool => ! str_contains(json_encode($contexto), 'AIza-chave-de-teste')
            && str_contains((string) $contexto['message'], '[chave]')
    );
});
