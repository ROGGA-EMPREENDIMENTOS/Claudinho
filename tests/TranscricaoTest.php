<?php

declare(strict_types=1);

use Rogga\Claudinho\Transcricao;

// Sem banco de propósito, igual ao CanalTest: o que se prova aqui é o lado do
// CONFIG. Que o valor gravado em tela vence o arquivo é assunto do
// ConfiguracoesTest, que já migra a tabela.

it('cai no config quando nada foi gravado em tela', function () {
    config()->set('claudinho.transcricao.habilitado', true);
    config()->set('claudinho.transcricao.chave', 'AIza-do-env');
    config()->set('claudinho.transcricao.idioma', 'en-US');

    expect(Transcricao::habilitada())->toBeTrue()
        ->and(Transcricao::chave())->toBe('AIza-do-env')
        ->and(Transcricao::idioma())->toBe('en-US');
});

it('nasce desligada', function () {
    // Ligar manda o áudio de quem está conversando para um serviço de terceiro e
    // custa dinheiro por minuto. Atualizar o pacote não pode fazer isso sozinho.
    expect(Transcricao::habilitada())->toBeFalse();
});

it('não fica ativa ligada sem chave', function () {
    // Ligado sem chave não é "meio ligado": é desligado com aparência de ligado. A
    // diferença entre os dois é o que a tela usa para avisar, em vez de deixar cada
    // áudio falhar em silêncio.
    config()->set('claudinho.transcricao.habilitado', true);
    config()->set('claudinho.transcricao.chave', null);

    expect(Transcricao::habilitada())->toBeTrue()
        ->and(Transcricao::ativa())->toBeFalse();
});

it('não fica ativa com chave e o interruptor desligado', function () {
    config()->set('claudinho.transcricao.habilitado', false);
    config()->set('claudinho.transcricao.chave', 'AIza-do-env');

    expect(Transcricao::ativa())->toBeFalse();
});

it('trata chave em branco como chave ausente', function () {
    // Chave vazia enviada ao Google vira um 400 genérico, e o motivo real — "não
    // tem chave nenhuma" — se perderia no log.
    config()->set('claudinho.transcricao.chave', '   ');

    expect(Transcricao::chave())->toBeNull();
});

it('tem padrão para quem publicou o config antes desta versão', function () {
    // O mergeConfigFrom alcança chave de primeiro nível, mas o arquivo publicado
    // pode estar em cache com o array antigo. Sem padrão aqui, o idioma chegaria
    // vazio ao Google e o timeout seria zero.
    config()->set('claudinho.transcricao', null);

    expect(Transcricao::habilitada())->toBeFalse()
        ->and(Transcricao::chave())->toBeNull()
        ->and(Transcricao::idioma())->toBe('pt-BR')
        ->and(Transcricao::timeout())->toBe(30);
});
