<?php

declare(strict_types=1);

use Rogga\Claudinho\Confirmacao;

/**
 * O ponto mais arriscado do endpoint: aqui um acerto frouxo vira escrita não
 * autorizada. Testado isolado, sem banco e sem HTTP, porque é a única pergunta que
 * importa — o que exatamente aprova.
 */
it('aprova a palavra sozinha, com ou sem acento, caixa e pontuação', function () {
    foreach (['sim', 'SIM', 'Sim.', ' sim ', 'sim!', 'confirmo', 'CONFIRMAR', 'autorizo'] as $mensagem) {
        expect(Confirmacao::aprovada($mensagem))->toBeTrue("deveria aprovar: {$mensagem}");
    }
});

it('recusa frase que contém a palavra, e é para isso que existe', function () {
    // O caso que justifica o casamento exato: por conteúdo, isto autorizaria
    // exatamente o oposto do que a pessoa escreveu.
    expect(Confirmacao::aprovada('não, não confirmo'))->toBeFalse()
        ->and(Confirmacao::aprovada('nao confirmo'))->toBeFalse()
        ->and(Confirmacao::aprovada('sim, mas antes me diz o valor'))->toBeFalse()
        ->and(Confirmacao::aprovada('acho que sim'))->toBeFalse()
        ->and(Confirmacao::aprovada('sim pode cancelar'))->toBeFalse();
});

it('recusa negativa, dúvida e mensagem qualquer', function () {
    foreach (['não', 'nao', 'n', 'cancela', 'espera', 'qual o valor?', 'oi', '👍'] as $mensagem) {
        expect(Confirmacao::aprovada($mensagem))->toBeFalse("deveria recusar: {$mensagem}");
    }
});

it('recusa mensagem vazia e lista de palavras vazia', function () {
    // Config mal preenchido não pode transformar qualquer mensagem em autorização.
    expect(Confirmacao::aprovada(''))->toBeFalse()
        ->and(Confirmacao::aprovada('   '))->toBeFalse()
        ->and(Confirmacao::aprovada('sim', []))->toBeFalse()
        ->and(Confirmacao::aprovada('sim', ['', '  ']))->toBeFalse();
});

it('usa a lista configurada pela aplicação', function () {
    config()->set('claudinho.api.palavras_confirmacao', ['pode ir']);

    expect(Confirmacao::aprovada('pode ir'))->toBeTrue()
        // Espaço repetido é colapsado, senão "pode  ir" deixaria de casar.
        ->and(Confirmacao::aprovada('Pode  ir!'))->toBeTrue()
        ->and(Confirmacao::aprovada('sim'))->toBeFalse();
});

it('normaliza sem exigir extensão de intl', function () {
    expect(Confirmacao::normalizar('SIM, é ISSO!'))->toBe('sim e isso')
        ->and(Confirmacao::normalizar('Não'))->toBe('nao')
        ->and(Confirmacao::normalizar('  ação   já  '))->toBe('acao ja');
});
