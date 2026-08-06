<?php

declare(strict_types=1);

use Rogga\Claudinho\Claudinho;

it('devolve a versão instalada do pacote', function () {
    // Sob testbench o pacote está instalado, então a versão precisa existir.
    expect(Claudinho::versao())->toBeString()->not->toBeEmpty();
});

it('expõe o nome do pacote usado na consulta ao composer', function () {
    expect(Claudinho::PACOTE)->toBe('rogga/claudinho');
});

it('devolve null para pacote que não está instalado, em vez de estourar', function () {
    expect(Claudinho::versaoDe('rogga/pacote-que-nao-existe'))->toBeNull();
});

it('tira o v da tag para a versão ficar igual às outras da lista', function () {
    // livewire/livewire marca com v (v3.6.4): é o caso real que o ltrim atende, e
    // serve de sonda porque é dependência declarada, não pacote incidental do vendor.
    expect(Claudinho::versaoDe('livewire/livewire'))
        ->toBeString()
        ->not->toStartWith('v');
});

it('junta as versões do ambiente que a janela sobre mostra', function () {
    $ambiente = Claudinho::ambiente();

    expect($ambiente)
        ->toHaveKeys(['Claudinho', 'Laravel', 'Livewire', 'PHP'])
        ->and($ambiente['PHP'])->toBe(PHP_VERSION)
        ->and($ambiente['Laravel'])->toBe(app()->version())
        // Versão vazia sai da lista: linha "desconhecida" não ajuda ninguém.
        ->and(array_filter($ambiente, fn (string $versao) => $versao === ''))->toBe([]);
});

it('usa sonnet 5 como modelo padrão', function () {
    // O TestCase fixa o model, então lê o arquivo de config direto para checar o
    // default de quem instala o pacote sem publicar nem definir ANTHROPIC_MODEL.
    $padrao = require __DIR__.'/../config/claudinho.php';

    expect($padrao['model'])->toBe('claude-sonnet-5');
});
