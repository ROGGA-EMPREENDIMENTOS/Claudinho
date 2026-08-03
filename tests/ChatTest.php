<?php

declare(strict_types=1);

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('não monta botão nem painel flutuante por padrão', function () {
    $html = Livewire::test(Chat::class)->html();

    expect($html)
        ->not->toContain('role="dialog"')
        ->not->toContain('Abrir o assistente')
        // Sem o painel não existe fechar() no escopo: chamada órfã quebraria o Alpine.
        ->not->toContain('fechar()');
});

it('monta o botão e o painel quando flutuante', function () {
    $componente = Livewire::test(Chat::class, ['flutuante' => true]);

    $componente
        ->assertSee('Abrir o assistente')
        ->assertSee('role="dialog"', false)
        ->assertSee('x-on:click="abrir()"', false)
        ->assertSee('x-on:click="fechar()"', false)
        ->assertSee('aria-label="Fechar o assistente"', false)
        // Tela inteira no mobile, ancorado do sm: para cima.
        ->assertSee('inset-0 sm:inset-auto', false)
        ->assertSee('sm:right-6', false);
});

it('usa a marca do Claudinho no botão flutuante, não um ícone genérico', function () {
    $componente = Livewire::test(Chat::class, ['flutuante' => true]);

    $componente
        // Cores da marca, e SVG inline: o botão não pode depender do publish dos PNGs.
        ->assertSee('#d3754c', false)
        ->assertSee('fill-[#191512] dark:fill-[#f2ece1]', false)
        ->assertSee('viewBox="0 0 296 373"', false)
        // A bolha de fala genérica do heroicons saiu de cena.
        ->assertDontSee('M12 20.25c4.97 0', false);
});

it('não deixa a marca do botão herdar a animação do pensando', function () {
    // O <style> do pensando é global. Se a marca do botão carregasse as classes dele,
    // ela piscaria os dois olhos e balançaria o cabelo junto, num canto fixo da tela,
    // a cada resposta. A piscadela discreta do botão é OUTRA classe, com keyframes
    // próprio e ciclo longo — por isso o guarda mira as classes do pensando, e não
    // qualquer `claudinho-*`.
    $html = Livewire::test(Chat::class, ['flutuante' => true])
        ->set('pergunta', 'quantas obras ativas?')
        ->call('enviar')
        ->html();

    $documento = new DOMDocument;
    @$documento->loadHTML($html);
    $xpath = new DOMXPath($documento);

    // Sem dois-pontos no nome do atributo: em XPath eles viram prefixo de namespace.
    $lancador = $xpath->query('//button[@x-show="! aberto"]')->item(0);

    expect($lancador)->not->toBeNull()
        ->and($xpath->query('.//svg', $lancador)->length)->toBeGreaterThan(0)
        // Nenhuma das duas classes que o keyframes do pensando persegue.
        ->and($xpath->query('.//*[contains(@class, "claudinho-cabelo")]', $lancador)->length)->toBe(0)
        ->and($xpath->query('.//*[contains(@class, "claudinho-olho")]', $lancador)->length)->toBe(0)
        // E a piscadela própria do botão está lá, num olho só.
        ->and($xpath->query('.//*[contains(@class, "claudinho-piscada")]', $lancador)->length)->toBe(1);

    // O pensando, esse sim, segue com a animação completa.
    expect($html)->toContain('claudinho-cabelo--esq')->toContain('claudinho-olho');
});

it('acompanha a piscadela do botão com o keyframes e o respeito a reduced-motion', function () {
    $html = Livewire::test(Chat::class, ['flutuante' => true])->html();

    // Classe sem keyframes é animação que não acontece; e movimento perpétuo num canto
    // da tela precisa de saída para quem pede menos movimento.
    expect($html)
        ->toContain('@keyframes claudinho-piscada')
        ->toContain('prefers-reduced-motion');
});

it('deixa o painel abaixo do modal de configurações', function () {
    config()->set('claudinho.permissao_admin', null);

    $html = Livewire::test(Chat::class, ['flutuante' => true])->html();

    // O modal de configurações é z-50; painel e botão acima disso esconderiam a
    // engrenagem por trás do próprio chat.
    expect($html)->toContain('fixed z-40')
        ->and($html)->toContain('z-50');
});

it('não aninha o modal de configurações dentro do painel flutuante', function () {
    config()->set('claudinho.permissao_admin', null);

    $documento = new DOMDocument;
    // O HTML do Livewire tem atributos que o parser reclama; o que importa é a árvore.
    @$documento->loadHTML(Livewire::test(Chat::class, ['flutuante' => true])->html());

    $modal = (new DOMXPath($documento))->query('//*[@aria-modal="true"]')->item(0);

    expect($modal)->not->toBeNull();

    $ancestrais = [];

    for ($no = $modal->parentNode; $no instanceof DOMElement; $no = $no->parentNode) {
        $ancestrais[] = $no->getAttribute('x-ref');
    }

    // O modal é fixed, e ancestral com transform vira o bloco contêiner de
    // descendentes fixed — o painel tem transform durante a transição de abertura.
    // Aninhado ali, o modal apareceria deslocado, ancorado no painel.
    expect($ancestrais)->not->toContain('painel');
});

it('troca o canto do flutuante pela config', function () {
    config()->set('claudinho.flutuante.posicao', 'esquerda');

    Livewire::test(Chat::class, ['flutuante' => true])
        ->assertSee('sm:left-6', false)
        ->assertSee('left-6', false)
        ->assertDontSee('sm:right-6', false);
});

it('usa o rótulo configurado no botão flutuante', function () {
    config()->set('claudinho.flutuante.rotulo', 'Falar com o assistente');

    Livewire::test(Chat::class, ['flutuante' => true])
        ->assertSee('aria-label="Falar com o assistente"', false)
        ->assertDontSee('Abrir o assistente');
});

it('abre já aberto quando a config pede', function () {
    config()->set('claudinho.flutuante.aberto', true);

    Livewire::test(Chat::class, ['flutuante' => true])->assertSee('aberto: true', false);

    config()->set('claudinho.flutuante.aberto', false);

    Livewire::test(Chat::class, ['flutuante' => true])->assertSee('aberto: false', false);
});

it('serve a mesma conversa nos dois modos', function () {
    foreach ([false, true] as $flutuante) {
        $componente = Livewire::test(Chat::class, ['flutuante' => $flutuante])
            ->set('pergunta', 'quantas obras ativas?')
            ->call('enviar');

        // O card é o mesmo partial: bolha, textarea e streaming não podem depender do modo.
        $componente
            ->assertSee('quantas obras ativas?')
            ->assertSee('wire:stream="resposta"', false)
            ->assertSee('Claudinho está respondendo');
    }
});

it('avisa que a resposta ficou pronta, para o painel fechado acender o ponto', function () {
    fakeStreams(rodadaTexto('São 12 obras ativas.'));

    Livewire::test(Chat::class, ['flutuante' => true])
        ->set('pergunta', 'quantas obras ativas?')
        ->call('enviar')
        ->call('responder')
        ->assertDispatched('claudinho-resposta-pronta');
});

it('mantém o 403 do gate no modo flutuante', function () {
    config()->set('claudinho.permissao', 'use_assistente');

    // Não relaxar o gate no flutuante é decisão: renderizar nada em silêncio
    // esconderia o assistente de todo mundo se o nome da permissão estiver errado.
    // O preço é que, num layout global, o include precisa vir dentro de @can — está
    // documentado no README, e é este teste que prende a documentação ao código.
    Livewire::test(Chat::class, ['flutuante' => true])->assertForbidden();
    Livewire::test(Chat::class)->assertForbidden();
});

it('não deixa o cliente ligar o modo flutuante por conta própria', function () {
    // Locked: onde o componente foi colocado é decisão do servidor. O checksum do
    // snapshot já barra adulteração do payload, mas não um $wire.set() legítimo.
    Livewire::test(Chat::class)->set('flutuante', true);
})->throws(CannotUpdateLockedPropertyException::class);

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

it('usa o terracota da marca na borda do flutuante, não um laranja qualquer', function () {
    $html = Livewire::test(Chat::class, ['flutuante' => true])->html();

    // Mesmo hex das antenas do ícone: a borda existe para amarrar o botão e o
    // painel à marca, então aproximar por uma cor do Tailwind quebraria o vínculo.
    expect($html)
        // Acento a 50%, não contorno: quem identifica o botão é o círculo branco com
        // a marca dentro, e quem separa o painel da página é a sombra.
        ->toContain('ring-1 ring-[#d3754c]/50')
        ->toContain('border-[#d3754c]/50')
        // No hover a cor fecha, para o alvo responder ao ponteiro.
        ->toContain('hover:ring-[#d3754c]')
        // O anel de FOCO segue sky e cheio: é ele que precisa saltar, e é ele que
        // tem exigência de contraste.
        ->toContain('focus:ring-2')
        ->toContain('focus:ring-sky-500')
        ->not->toContain('ring-sky-600/30');
});

it('deixa o card inline com a borda cinza discreta', function () {
    $html = Livewire::test(Chat::class)->html();

    // Inline é mais um bloco da página; laranja ali competiria com o conteúdo.
    expect($html)
        ->toContain('border-gray-200')
        ->not->toContain('border-[#d3754c]');
});
