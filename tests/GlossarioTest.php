<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rogga\Claudinho\Conversa;
use Rogga\Claudinho\Livewire\Configuracoes;
use Rogga\Claudinho\Models\Configuracao;
use Rogga\Claudinho\Models\Regra;

/**
 * O glossário e o contexto cadastrados em tela.
 *
 * O que estes testes protegem, acima de tudo, é a PRECEDÊNCIA: enquanto não houver
 * regra cadastrada vale o config, e a partir da primeira o arquivo sai de cena
 * inteiro. Errar isso significa mandar duas versões da mesma regra para o modelo, ou
 * ressuscitar no prompt uma regra que alguém acabou de desativar.
 */
beforeEach(function () {
    exigeBanco();
});

it('usa o glossário do config enquanto não há regra cadastrada', function () {
    config()->set('claudinho.glossario', ['users.obra_scoped vazio significa acesso a todas as obras.']);

    expect((new Conversa)->systemPrompt())->toContain('users.obra_scoped vazio significa acesso a todas as obras.');
});

it('faz a primeira regra cadastrada tirar o config do prompt', function () {
    config()->set('claudinho.glossario', ['a regra que estava no arquivo']);

    Regra::create(['regra' => 'a regra cadastrada em tela']);

    $prompt = (new Conversa)->systemPrompt();

    expect($prompt)
        ->toContain('a regra cadastrada em tela')
        ->not->toContain('a regra que estava no arquivo');
});

it('mantém fora do prompt a regra desativada, sem ressuscitar o config', function () {
    config()->set('claudinho.glossario', ['a regra que estava no arquivo']);

    Regra::create(['regra' => 'a regra que vale hoje']);
    Regra::create(['regra' => 'a regra que atrapalhou a resposta', 'ativo' => false]);

    $prompt = (new Conversa)->systemPrompt();

    expect($prompt)
        ->toContain('a regra que vale hoje')
        ->not->toContain('a regra que atrapalhou a resposta')
        // Desativar todas é decisão de quem administra; cair no config aqui traria de
        // volta justamente o glossário que a pessoa silenciou.
        ->not->toContain('a regra que estava no arquivo');
});

it('sobrevive à tabela ainda não migrada, caindo no config', function () {
    config()->set('claudinho.glossario', ['a regra que estava no arquivo']);

    Schema::drop('claudinho_glossario');
    Regra::esquecer();

    expect(Regra::glossario())->toBe(['' => ['a regra que estava no arquivo']])
        ->and((new Conversa)->systemPrompt())->toContain('a regra que estava no arquivo');
});

it('cadastra, edita, desativa e remove regras pela tela', function () {
    comoAdmin();

    $componente = Livewire::test(Configuracoes::class)
        ->set('regraNova', 'funcionarios.ativo = 1 identifica o vínculo vigente.')
        ->call('adicionarRegra');

    $regra = Regra::query()->firstOrFail();

    expect($regra->regra)->toBe('funcionarios.ativo = 1 identifica o vínculo vigente.')
        ->and($regra->ativo)->toBeTrue()
        // Autoria: o glossário saiu do git, então quem escreveu a regra precisa estar
        // gravado em algum lugar.
        ->and($regra->autor)->toBe('Admin');

    $componente->call('editarRegra', $regra->id)
        ->assertSet('textoEmEdicao', 'funcionarios.ativo = 1 identifica o vínculo vigente.')
        ->set('textoEmEdicao', 'funcionarios.ativo = 1 coincide com não ter dt_rescisao.')
        ->call('salvarRegra')
        ->assertSet('emEdicao', null);

    expect(Regra::query()->firstOrFail()->regra)->toBe('funcionarios.ativo = 1 coincide com não ter dt_rescisao.');

    $componente->call('alternarRegra', $regra->id);

    expect(Regra::query()->firstOrFail()->ativo)->toBeFalse()
        ->and(Regra::glossario())->toBe([]);

    $componente->call('removerRegra', $regra->id);

    expect(Regra::query()->count())->toBe(0);
});

it('limpa o campo e a busca ao cadastrar, para a regra nova não sumir', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->set('busca', 'obra')
        ->set('regraNova', 'Empreendimento e obra são a mesma coisa.')
        ->call('adicionarRegra')
        ->assertSet('regraNova', '')
        // Sem isto a regra recém-escrita cairia fora do filtro anterior e sumiria da
        // lista sem explicação nenhuma.
        ->assertSet('busca', '');
});

it('recusa regra curta demais para ensinar alguma coisa', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->set('regraNova', 'obras')
        ->call('adicionarRegra')
        ->assertHasErrors(['regraNova' => 'min']);

    expect(Regra::query()->count())->toBe(0);
});

it('importa o glossário do config sem duplicar o que já está cadastrado', function () {
    comoAdmin();

    config()->set('claudinho.glossario', ['primeira regra do arquivo', 'segunda regra do arquivo']);

    Regra::create(['regra' => 'primeira regra do arquivo']);

    Livewire::test(Configuracoes::class)->call('importarDoConfig');

    expect(Regra::query()->pluck('regra')->all())
        ->toBe(['primeira regra do arquivo', 'segunda regra do arquivo']);

    // Clicar duas vezes não pode multiplicar o glossário.
    Livewire::test(Configuracoes::class)->call('importarDoConfig');

    expect(Regra::query()->count())->toBe(2);
});

it('conta o que ainda dá para importar do config', function () {
    comoAdmin();

    config()->set('claudinho.glossario', ['primeira regra do arquivo', 'segunda regra do arquivo']);

    $situacao = Livewire::test(Configuracoes::class)->instance()->glossarioEmUso();

    expect($situacao['origem'])->toBe('config')
        ->and($situacao['importaveis'])->toBe(2);

    Regra::create(['regra' => 'primeira regra do arquivo']);
    Regra::esquecer();

    $situacao = Livewire::test(Configuracoes::class)->instance()->glossarioEmUso();

    expect($situacao['origem'])->toBe('tela')
        ->and($situacao['cadastradas'])->toBe(1)
        ->and($situacao['importaveis'])->toBe(1);
});

it('filtra a lista pela busca, sem exigir a maiúscula certa', function () {
    comoAdmin();

    Regra::create(['regra' => 'Empreendimento e OBRA são a mesma coisa.']);
    Regra::create(['regra' => 'Notas fiscais têm competência mensal.']);

    $componente = Livewire::test(Configuracoes::class)->set('busca', 'obra');

    expect($componente->instance()->regras())->toHaveCount(1)
        ->and($componente->instance()->regras()[0]->regra)->toContain('Empreendimento');
});

it('revalida a permissão em cada escrita no glossário, não só ao montar', function () {
    $regra = Regra::create(['regra' => 'uma regra qualquer de negócio para editar']);
    Regra::esquecer();

    $escritas = [
        fn ($componente) => $componente->set('regraNova', 'regra que não pode ser cadastrada')->call('adicionarRegra'),
        fn ($componente) => $componente->call('salvarRegra'),
        fn ($componente) => $componente->call('alternarRegra', $regra->id),
        fn ($componente) => $componente->call('removerRegra', $regra->id),
        fn ($componente) => $componente->call('importarDoConfig'),
    ];

    foreach ($escritas as $escrita) {
        // Um componente novo por ação: depois de um abort o Livewire perde o snapshot,
        // e a chamada seguinte viraria 404 — que esconderia justamente o 403 buscado.
        comoAdmin();

        $componente = Livewire::test(Configuracoes::class);

        // Permissão revogada com a tela já aberta. mount() roda uma vez, e o glossário
        // é escrita como qualquer outra: sem checar por conta própria, passaria.
        Gate::define('claudinho_admin', fn (): bool => false);

        $escrita($componente)->assertForbidden();
    }

    expect(Regra::query()->count())->toBe(1)
        ->and(Regra::query()->firstOrFail()->ativo)->toBeTrue();
});

it('agrupa o glossário por assunto no system prompt', function () {
    Regra::create(['regra' => 'a regra geral, sem assunto']);
    Regra::create(['regra' => 'obras.divisao é Prime ou Easy', 'tema' => 'OBRAS']);
    Regra::create(['regra' => 'o mês do PPC não é o do calendário', 'tema' => 'PPC']);
    Regra::create(['regra' => 'empreendimento e obra são a mesma coisa', 'tema' => 'OBRAS']);

    $prompt = (new Conversa)->systemPrompt();

    // O bloco por assunto é o contexto que a regra sozinha não carrega: "status"
    // quer dizer coisas diferentes em PPC e em documentos.
    expect($prompt)
        ->toContain("OBRAS\n- obras.divisao é Prime ou Easy\n- empreendimento e obra são a mesma coisa")
        ->toContain("PPC\n- o mês do PPC não é o do calendário")
        ->toContain('agrupadas por assunto')
        // Sem assunto vem primeiro, e sem título: é o bloco geral, não um apêndice.
        ->toContain("\n\n- a regra geral, sem assunto\n\nOBRAS");
});

it('mantém o texto de antes dos temas quando nenhuma regra tem assunto', function () {
    Regra::create(['regra' => 'uma regra sem assunto nenhum']);

    expect((new Conversa)->systemPrompt())
        ->toContain('Regras de negócio desta aplicação:')
        ->not->toContain('agrupadas por assunto');
});

it('trata Obras, obras e OBRAS como o mesmo assunto', function () {
    comoAdmin();

    $componente = Livewire::test(Configuracoes::class);

    foreach ([' Obras ', 'obras', 'OBRAS'] as $escrito) {
        $componente->set('temaNovo', $escrito)
            ->set('regraNova', 'regra escrita com o assunto '.trim($escrito))
            ->call('adicionarRegra');
    }

    // Sem normalizar, seriam três grupos na tela e três blocos no prompt — cada um
    // com um terço do assunto.
    expect(Regra::temas())->toBe(['OBRAS' => 3])
        ->and(Regra::glossario())->toHaveKey('OBRAS');
});

it('importa o config já organizado em assuntos', function () {
    comoAdmin();

    config()->set('claudinho.glossario', [
        'OBRAS' => ['empreendimento e obra são a mesma coisa', 'obras.divisao é Prime ou Easy'],
        'PPC' => ['o mês do PPC não é o do calendário'],
    ]);

    // Antes de importar, o config já vale agrupado: quem nunca abriu a tela ganha os
    // blocos do mesmo jeito.
    expect(Regra::glossario())->toHaveKeys(['OBRAS', 'PPC']);

    Livewire::test(Configuracoes::class)->call('importarDoConfig');

    expect(Regra::query()->where('tema', 'OBRAS')->count())->toBe(2)
        ->and(Regra::query()->where('tema', 'PPC')->count())->toBe(1);
});

it('aceita o config em lista simples, como era antes dos assuntos', function () {
    config()->set('claudinho.glossario', ['uma regra escrita sem assunto nenhum']);

    expect(Regra::doConfig())->toBe([['tema' => null, 'regra' => 'uma regra escrita sem assunto nenhum']]);
});

it('filtra a lista por assunto', function () {
    comoAdmin();

    Regra::create(['regra' => 'primeira de obras', 'tema' => 'OBRAS']);
    Regra::create(['regra' => 'segunda de obras', 'tema' => 'OBRAS']);
    Regra::create(['regra' => 'uma de ppc', 'tema' => 'PPC']);
    Regra::create(['regra' => 'uma sem assunto']);

    $componente = Livewire::test(Configuracoes::class);

    // Sem assunto escolhido a tela é o índice: com dezenas de regras, abrir todas de
    // uma vez manda ~200 KB de HTML em TODA ação do Livewire.
    expect($componente->instance()->mostrandoRegras())->toBeFalse()
        ->and($componente->instance()->temas())->toBe(['OBRAS' => 2, 'PPC' => 1, '' => 1]);

    $componente->set('tema', 'PPC');

    expect($componente->instance()->mostrandoRegras())->toBeTrue()
        ->and($componente->instance()->regras())->toHaveCount(1)
        ->and($componente->instance()->regrasPorTema())->toHaveKey('PPC');

    // "Sem assunto" é um grupo como qualquer outro, e precisa ser escolhível: é a
    // fila do que ainda falta classificar.
    $componente->set('tema', '');

    expect($componente->instance()->regras())->toHaveCount(1)
        ->and($componente->instance()->regras()[0]->regra)->toBe('uma sem assunto');

    // Procurar atravessa os assuntos: quem procura não sabe em qual deles está.
    $componente->set('tema', null)->set('busca', 'de obras');

    expect($componente->instance()->mostrandoRegras())->toBeTrue()
        ->and($componente->instance()->regras())->toHaveCount(2);
});

it('leva o filtro junto quando a regra muda de assunto', function () {
    comoAdmin();

    $regra = Regra::create(['regra' => 'uma regra que está no assunto errado', 'tema' => 'OBRAS']);
    Regra::esquecer();

    Livewire::test(Configuracoes::class)
        ->set('tema', 'OBRAS')
        ->call('editarRegra', $regra->id)
        ->assertSet('temaEmEdicao', 'OBRAS')
        ->set('temaEmEdicao', 'PPC')
        ->call('salvarRegra')
        // Sem isto a regra sumiria da tela no instante em que foi salva, porque o
        // filtro continuaria no assunto de onde ela acabou de sair.
        ->assertSet('tema', 'PPC');

    expect(Regra::query()->firstOrFail()->tema)->toBe('PPC');
});

it('mantém o assunto no campo para cadastrar em lote', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->set('temaNovo', 'ppc')
        ->set('regraNova', 'a primeira regra de ppc do lote')
        ->call('adicionarRegra')
        ->assertSet('regraNova', '')
        // A tela pula para o assunto onde a regra caiu: é onde se confere se ficou boa.
        ->assertSet('tema', 'PPC')
        // Glossário se cadastra em lote, um assunto por vez: reescrever "PPC" a cada
        // linha seria trabalho à toa.
        ->assertSet('temaNovo', 'PPC');
});

it('grava o contexto em tela por cima do config', function () {
    comoAdmin();

    config()->set('claudinho.contexto', 'Assistente definido no arquivo.');

    Livewire::test(Configuracoes::class)
        ->assertSet('contexto', 'Assistente definido no arquivo.')
        ->set('contexto', 'Assistente do SGT, sistema de gestão de terceiros.')
        ->call('salvar');

    expect((new Conversa)->systemPrompt())
        ->toContain('Assistente do SGT, sistema de gestão de terceiros.')
        ->not->toContain('Assistente definido no arquivo.');
});

it('volta ao contexto do config quando o campo é esvaziado', function () {
    comoAdmin();

    config()->set('claudinho.contexto', 'Assistente definido no arquivo.');

    Configuracao::definir('contexto', 'Texto que alguém gravou antes.');

    Livewire::test(Configuracoes::class)
        ->assertSet('contexto', 'Texto que alguém gravou antes.')
        ->set('contexto', '')
        ->call('salvar')
        // O campo volta preenchido com o texto do config: afirmar contexto vazio seria
        // a tela mentir sobre o que o assistente está usando.
        ->assertSet('contexto', 'Assistente definido no arquivo.');

    expect((new Conversa)->systemPrompt())->toContain('Assistente definido no arquivo.');
});

it('não muda de aba ao mexer no glossário', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->set('aba', 'assistente')
        ->set('regraNova', 'uma regra qualquer de negócio para cadastrar')
        ->call('adicionarRegra')
        ->assertSet('aba', 'assistente');
});
