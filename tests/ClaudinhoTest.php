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

it('usa sonnet 5 como modelo padrão', function () {
    // O TestCase fixa o model, então lê o arquivo de config direto para checar o
    // default de quem instala o pacote sem publicar nem definir ANTHROPIC_MODEL.
    $padrao = require __DIR__.'/../config/claudinho.php';

    expect($padrao['model'])->toBe('claude-sonnet-5');
});
