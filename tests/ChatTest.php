<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rogga\Claudinho\Livewire\Chat;

it('monta o header com as quatro variantes do logo e o título', function () {
    $componente = Livewire::test(Chat::class);

    // Cada combinação de tema e largura precisa do seu arquivo: PNG tem cor fixa.
    $componente
        ->assertSee('vendor/claudinho/claudinho-icone-claro.png', false)
        ->assertSee('vendor/claudinho/claudinho-icone-escuro.png', false)
        ->assertSee('vendor/claudinho/claudinho-lockup-claro.png', false)
        ->assertSee('vendor/claudinho/claudinho-lockup-escuro.png', false)
        ->assertSee('Assistente de IA');
});

it('dispensa os assets publicados quando o logo está desligado', function () {
    config()->set('claudinho.logo', false);

    Livewire::test(Chat::class)
        ->assertDontSee('vendor/claudinho/claudinho-lockup-claro.png', false)
        ->assertSee('Assistente de IA');
});

it('usa o título configurado pela aplicação', function () {
    config()->set('claudinho.titulo', 'Assistente da Obra');

    Livewire::test(Chat::class)->assertSee('Assistente da Obra');
});

it('mantém o nome acessível do botão enviar, que só tem ícone', function () {
    Livewire::test(Chat::class)
        ->assertSee('aria-label="Enviar"', false)
        ->assertDontSee('>Enviar<', false);
});

it('troca o cursor pelo Claudinho animado enquanto responde', function () {
    $componente = Livewire::test(Chat::class);

    $componente->assertDontSee('claudinho-cabelo', false);

    $componente->set('pergunta', 'quantas obras ativas?')->call('enviar');

    $componente
        ->assertSee('claudinho-cabelo--esq', false)
        ->assertSee('claudinho-cabelo--meio', false)
        ->assertSee('claudinho-cabelo--dir', false)
        ->assertSee('claudinho-olho', false)
        ->assertSee('Claudinho está respondendo')
        // O ▌ saiu de cena; a animação precisa parar para quem pede menos movimento.
        ->assertDontSee('▌')
        ->assertSee('prefers-reduced-motion', false);
});

it('instala o auto-scroll na área das mensagens', function () {
    Livewire::test(Chat::class)
        ->assertSee('MutationObserver', false)
        ->assertSee('presoNoFim', false)
        // Sem o characterData o streaming não move a rolagem, só a mensagem inteira.
        ->assertSee('characterData: true', false)
        ->assertSee('x-on:claudinho-rolar.window', false)
        ->assertSee("\$dispatch('claudinho-rolar')", false);
});

it('monta a engrenagem e o modal quando permissao_admin está liberado', function () {
    // permissao_admin em branco libera para qualquer um — e sem banco acessível o
    // componente de configurações cai no config, então este teste roda sem sqlite.
    config()->set('claudinho.permissao_admin', null);

    Livewire::test(Chat::class)
        ->assertSee('claudinho-abrir-configuracoes', false)
        ->assertSee('aria-label="Configurações"', false)
        ->assertSee('Configurações do Claudinho')
        ->assertSee('claudinho-modelo', false)
        // O TestCase define uma api_key de fixture, então a origem é o .env.
        ->assertSee('vinda do');
});

it('avisa na tela de configurações quando não há chave em lugar nenhum', function () {
    config()->set('claudinho.permissao_admin', null);
    config()->set('claudinho.api_key', null);

    Livewire::test(Chat::class)->assertSee('Nenhuma chave configurada');
});

it('esconde a engrenagem quando permissao_admin exige gate e ninguém está logado', function () {
    config()->set('claudinho.permissao_admin', 'claudinho_admin');

    Livewire::test(Chat::class)
        ->assertDontSee('claudinho-abrir-configuracoes', false)
        ->assertDontSee('Configurações do Claudinho');
});

it('monta o seletor de tema com os três estados', function () {
    $componente = Livewire::test(Chat::class);

    $componente
        ->assertSee('x-on:click="alternar()"', false)
        ->assertSee("tema === 'sistema'", false)
        ->assertSee("tema === 'claro'", false)
        ->assertSee("tema === 'escuro'", false)
        // Sem o script inline quem escolheu escuro vê um lampejo claro na carga.
        ->assertSee('claudinho-tema', false)
        ->assertSee('currentScript.parentElement', false);
});

it('repõe a classe do tema depois do morph do Livewire', function () {
    Livewire::test(Chat::class)
        // O morph ressincroniza os atributos da raiz a partir do HTML do servidor, que não
        // conhece a escolha do usuário. Sem o observer, mandar uma mensagem apagava a classe
        // e o chat voltava para o claro — só no tema escuro, porque no claro não há classe
        // para perder. O attributeFilter é o que distingue este observer do do auto-scroll.
        ->assertSee("attributeFilter: ['class']", false)
        ->assertSee('classList.contains(\'dark\') !== escuro()', false);
});

it('não põe a classe dark no mesmo elemento que usa as utilities dark:', function () {
    $html = Livewire::test(Chat::class)->html();

    // O Tailwind gera `.dark\:bg-gray-900:is(.dark *)` — seletor de ancestral, que não
    // casa com o próprio elemento. Se a raiz que recebe a classe dark for a mesma que
    // carrega dark:bg-gray-900, o fundo do card nunca troca, e o header e a área de
    // conversa herdam o fundo claro dele. Foi exatamente esse o bug.
    $posEfeito = strpos($html, 'x-effect=');
    $tagRaiz = substr($html, 0, strpos($html, '>', $posEfeito));

    expect($tagRaiz)
        ->toContain('x-effect')
        ->not->toContain('dark:bg-')
        ->not->toContain('dark:border-');

    // E o card, agora descendente, segue com as classes.
    expect($html)->toContain('dark:bg-gray-900');
});

it('aplica o tema no documento quando configurado assim', function () {
    config()->set('claudinho.tema.alvo', 'documento');

    Livewire::test(Chat::class)
        ->assertSee('document.documentElement.classList.toggle', false)
        ->assertDontSee('currentScript.parentElement', false);
});

it('esconde o seletor de tema quando a aplicação tem o próprio', function () {
    config()->set('claudinho.tema.seletor', false);

    Livewire::test(Chat::class)
        ->assertDontSee('x-on:click="alternar()"', false)
        // O x-effect continua: quem já escolheu antes não perde a escolha.
        ->assertSee("classList.toggle('dark', escuro)", false);
});

it('renderiza as variantes dark do container e das bolhas', function () {
    $componente = Livewire::test(Chat::class);

    $componente
        ->assertSee('dark:bg-gray-900', false)
        ->assertSee('dark:border-gray-800', false);

    $componente->set('pergunta', 'quantas obras ativas?')->call('enviar');

    // A bolha do usuário e o textarea são as superfícies que o tema escuro precisa cobrir.
    $componente
        ->assertSee('dark:bg-sky-950', false)
        ->assertSee('dark:bg-gray-800', false);
});
