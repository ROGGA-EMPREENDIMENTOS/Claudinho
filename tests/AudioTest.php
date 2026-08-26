<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;
use Rogga\Claudinho\Contracts\DestinoDeMidia;
use Rogga\Claudinho\Midia\MidiaRecebida;
use Rogga\Claudinho\Midia\Recebedor;

// O Recebedor direto, sem endpoint e sem banco: o que se prova aqui é a anotação
// que o modelo vai ler e quando o áudio é aceito. O caminho de ponta a ponta —
// gateway, middleware, resposta — está no MidiaTest, que monta o endpoint e por
// isso exige banco.

const AUDIO = 'https://gateway.exemplo.com/media/abc?sig=xyz';

beforeEach(function () {
    config()->set('claudinho.api.midias.hosts', ['gateway.exemplo.com']);
    config()->set('claudinho.api.midias.destino', GuardaAudio::class);
    config()->set('claudinho.transcricao.habilitado', true);
    config()->set('claudinho.transcricao.chave', 'AIza-de-teste');

    GuardaAudio::$recebidas = [];
});

function chegaAudio(string $tipo = 'audio/ogg'): string
{
    return (new Recebedor)->receber(new User, $tipo, AUDIO);
}

/**
 * O gateway entrega o arquivo e o Google entrega o que foi dito nele. Num fake
 * só: chamar Http::fake() duas vezes soma os stubs e o primeiro vence, e aí a
 * chamada ao Google receberia os bytes do arquivo como resposta.
 */
function ouve(string $texto): void
{
    Http::fake([
        'gateway.exemplo.com/*' => Http::response(opus()),
        'speech.googleapis.com/*' => Http::response(['results' => [['alternatives' => [['transcript' => $texto]]]]]),
    ]);
}

function googleFalha(array $erro, int $status = 400): void
{
    Http::fake([
        'gateway.exemplo.com/*' => Http::response(opus()),
        'speech.googleapis.com/*' => Http::response(['error' => $erro], $status),
    ]);
}

it('troca o áudio pela transcrição do que foi dito nele', function () {
    ouve('preciso remarcar a vistoria de amanhã');

    // Anunciada como transcrição, e não como fala: o reconhecimento erra, e o
    // modelo precisa saber disso para confirmar o que ficou ambíguo.
    expect(chegaAudio())
        ->toContain('preciso remarcar a vistoria de amanhã')
        ->toContain('transcrição');
});

it('aceita o tipo com parâmetro, que é como o WhatsApp manda', function () {
    ouve('bom dia');

    // `audio/ogg; codecs=opus` comparado inteiro não casa com lista nenhuma, e o
    // áudio era recusado como "tipo não aceito" mesmo com tudo ligado.
    expect(chegaAudio('audio/ogg; codecs=opus'))->toContain('bom dia');
});

it('entrega ao destino da aplicação o arquivo já com a transcrição', function () {
    ouve('o portão não fecha');

    chegaAudio();

    // Junto, e não depois: sem isto a aplicação teria de transcrever de novo para
    // guardar o arquivo e o texto lado a lado.
    expect(GuardaAudio::$recebidas)->toHaveCount(1)
        ->and(GuardaAudio::$recebidas[0]->transcricao)->toBe('o portão não fecha')
        ->and(GuardaAudio::$recebidas[0]->descricao)->toBeNull()
        ->and(GuardaAudio::$recebidas[0]->nome)->toEndWith('.ogg');
});

it('diz que não escuta áudio com a transcrição desligada, sem baixar nada', function () {
    config()->set('claudinho.transcricao.habilitado', false);
    Http::fake();

    // Não é "tipo não aceito": a diferença muda o que a pessoa faz em seguida —
    // mandar o recado por escrito, em vez de reenviar achando que o envio falhou.
    expect(chegaAudio())->toContain('não escuto áudio');

    Http::assertNothingSent();
});

it('recebe áudio com foto e vídeo desligados', function () {
    // Os dois interruptores são independentes: quem só quer transcrever recado de
    // voz não deveria precisar ligar o download de imagem para isso. E a lista
    // `tipos` da aplicação foi escrita antes de áudio existir no pacote.
    config()->set('claudinho.api.midias.habilitado', false);
    ouve('bom dia, tudo certo por aí?');

    expect(chegaAudio())->toContain('bom dia, tudo certo por aí?');
});

it('continua recusando foto quando só a transcrição está ligada', function () {
    config()->set('claudinho.api.midias.habilitado', false);
    Http::fake();

    expect((new Recebedor)->receber(new User, 'image/jpeg', AUDIO))
        ->toContain('não é aceito neste canal');

    Http::assertNothingSent();
});

it('não manda reenviar o áudio que passa de um minuto', function () {
    googleFalha(['message' => 'Sync input too long. Use LongRunningRecognize.']);

    // Reenviar o mesmo áudio daria no mesmo, e o modelo precisa saber disso para
    // não pedir de novo — como já acontece com a foto de endereço bloqueado.
    expect(chegaAudio())
        ->toContain('passa de um minuto')
        ->toContain('NÃO peça o reenvio');
});

it('pede que repita quando não deu para entender nada', function () {
    Http::fake([
        'gateway.exemplo.com/*' => Http::response(opus()),
        'speech.googleapis.com/*' => Http::response([]),
    ]);

    // Áudio mudo, ruído, recado em outro idioma. Não é falha de rede: reenviar o
    // mesmo arquivo dá no mesmo.
    expect(chegaAudio())
        ->toContain('não deu para entender')
        ->toContain('repita');
});

it('pede o recado por escrito quando o Google não decodifica o formato', function () {
    Http::fake(['gateway.exemplo.com/*' => Http::response('ftypM4A ')]);

    // M4A e AAC nem entram na lista de aceitos, porque a API do Google não os
    // decodifica: baixar para recusar depois seria só gastar rede.
    expect(chegaAudio('audio/mp4'))->toContain('não é aceito neste canal');
});

it('pede o reenvio quando a chamada ao Google falha', function () {
    googleFalha(['message' => 'API key not valid.'], 403);

    // Aqui, sim, reenviar pode resolver — a diferença entre este caso e os de cima
    // é o que o assistente vai pedir em seguida.
    expect(chegaAudio())->toContain('Peça o reenvio');
});

it('não entrega áudio de endereço que este servidor não acessa', function () {
    Http::fake();

    expect((new Recebedor)->receber(new User, 'audio/ogg', 'https://outro.exemplo.com/a.ogg'))
        ->toContain('NÃO peça reenvio');

    expect(GuardaAudio::$recebidas)->toBeEmpty();
    Http::assertNothingSent();
});

class GuardaAudio implements DestinoDeMidia
{
    /** @var array<int, MidiaRecebida> */
    public static array $recebidas = [];

    public function guardar(Authenticatable $usuario, MidiaRecebida $midia): ?string
    {
        self::$recebidas[] = $midia;

        return null;
    }
}
