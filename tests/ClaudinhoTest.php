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
