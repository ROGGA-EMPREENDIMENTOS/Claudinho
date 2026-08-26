<?php

declare(strict_types=1);

use Rogga\Claudinho\Canal;

// Sem banco de propósito: o que se prova aqui é o lado do CONFIG e as conversões de
// texto. Que o valor gravado em tela vence o arquivo é assunto do ConfiguracoesTest,
// que já migra a tabela.

it('cai no config quando nada foi gravado em tela', function () {
    config()->set('claudinho.api.acoes', false);
    config()->set('claudinho.api.minutos_inatividade', 45);
    config()->set('claudinho.api.minutos_confirmacao', 2);
    config()->set('claudinho.api.palavras_confirmacao', ['pode ir', 'confirmo']);
    config()->set('claudinho.api.instrucoes', '  Sem tabela.  ');
    config()->set('claudinho.api.midias.hosts', ['Mmg.WhatsApp.NET']);

    expect(Canal::acoes())->toBeFalse()
        ->and(Canal::minutosInatividade())->toBe(45)
        ->and(Canal::minutosConfirmacao())->toBe(2)
        ->and(Canal::palavras())->toBe(['pode ir', 'confirmo'])
        ->and(Canal::instrucoes())->toBe('Sem tabela.')
        ->and(Canal::hostsDeMidia())->toBe(['mmg.whatsapp.net']);
});

it('não deixa o prazo ser zero nem negativo', function () {
    // Prazo zero venceria a pendência antes de a resposta chegar ao outro lado, e
    // toda confirmação seria recusada por vencimento — o canal ficaria sem explicação
    // para "confirmei e ele disse que expirou".
    config()->set('claudinho.api.minutos_confirmacao', 0);
    config()->set('claudinho.api.minutos_inatividade', -10);

    expect(Canal::minutosConfirmacao())->toBe(1)
        ->and(Canal::minutosInatividade())->toBe(1);
});

it('guarda só o host, mesmo colando a URI assinada inteira', function () {
    // É o que a pessoa tem em mãos: o endereço que apareceu no log ou no painel do
    // gateway. Gravado inteiro, a comparação do Recebedor — que só olha o host —
    // falharia sem nada explicando.
    expect(Canal::host('https://MMG.whatsapp.net/v/t62.7118-24/123?ccb=11-4&oh=abc'))->toBe('mmg.whatsapp.net')
        ->and(Canal::host('cdn.gateway.com:8443/arquivo.jpg'))->toBe('cdn.gateway.com')
        ->and(Canal::host('  Gateway.Exemplo.COM  '))->toBe('gateway.exemplo.com')
        ->and(Canal::host('https://user:senha@cdn.gateway.com/x'))->toBe('cdn.gateway.com');
});

it('separa a lista por linha, vírgula ou ponto e vírgula, sem repetir', function () {
    // O campo é um textarea, e quem cola de outro lugar cola do jeito que estava lá.
    expect(Canal::lista("sim\nconfirmo, autorizo; sim\n\n  pode ir  "))
        ->toBe(['sim', 'confirmo', 'autorizo', 'pode ir']);
});

it('não aceita host repetido nem vazio na lista de mídia', function () {
    config()->set('claudinho.api.midias.hosts', ['mmg.whatsapp.net', 'MMG.WHATSAPP.NET', '', '   ']);

    expect(Canal::hostsDeMidia())->toBe(['mmg.whatsapp.net']);
});
