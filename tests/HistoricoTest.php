<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Rogga\Claudinho\Exibicao;
use Rogga\Claudinho\Livewire\Chat;
use Rogga\Claudinho\Livewire\Historico;
use Rogga\Claudinho\Models\ConversaExterna;

/**
 * Grava uma conversa como o canal externo grava, com o momento da última alteração
 * mandado à mão.
 *
 * `updated_at` precisa ser escrito depois, e não no create(): o Eloquent carimba o
 * agora em toda gravação, e sem isto as três conversas do teste de ordem sairiam com
 * o mesmo segundo — passando ou falhando por acaso.
 */
function conversaGravada(string $identificador, string $quando, array $mensagens = [], array $pendentes = []): ConversaExterna
{
    $conversa = ConversaExterna::query()->create([
        'canal' => 'whatsapp',
        'identificador' => $identificador,
        'user_id' => 1,
        'estado' => ['mensagens' => $mensagens, 'pendentes' => $pendentes, 'resultados' => [], 'iteracao' => 0],
        'expira_em' => now()->addMinutes(30),
    ]);

    ConversaExterna::query()->whereKey($conversa->id)->update(['updated_at' => $quando]);

    return $conversa->refresh();
}

/** A conversa mais simples que existe: uma pergunta e uma resposta. */
function trocaDeMensagens(string $pergunta, string $resposta): array
{
    return [
        ['role' => 'user', 'content' => $pergunta],
        ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $resposta]]],
    ];
}

it('mostra o relógio no header de quem administra', function () {
    comoAdmin();

    Livewire::test(Chat::class)->assertSee('aria-label="Histórico de conversas"', false);
});

it('esconde o relógio de quem não administra', function () {
    comoAdmin(permitido: false);

    Livewire::test(Chat::class)->assertDontSee('aria-label="Histórico de conversas"', false);
});

it('esconde o relógio com o histórico desligado no config', function () {
    comoAdmin();
    config()->set('claudinho.historico.habilitado', false);

    Livewire::test(Chat::class)->assertDontSee('aria-label="Histórico de conversas"', false);
});

it('aceita uma permissão própria, para dar o histórico a quem não administra o resto', function () {
    comoAdmin(permitido: false);

    config()->set('claudinho.historico.permissao', 've_historico');
    Gate::define('ve_historico', fn (): bool => true);

    Livewire::test(Chat::class)->assertSee('aria-label="Histórico de conversas"', false);
});

it('cai no gate de administração quando a permissão própria está vazia', function () {
    // Config publicado antes da 2.0 não tem a chave nenhuma: o lado seguro precisa
    // ser o herdado, senão atualizar o pacote abriria a conversa dos outros para
    // qualquer usuário do chat.
    comoAdmin(permitido: false);

    config()->set('claudinho.historico.permissao', null);

    expect(Historico::disponivel())->toBeFalse();
});

it('não abre o painel para quem não tem a permissão', function () {
    comoAdmin(permitido: false);

    // Depois do abort não há estado para inspecionar — o 403 é a asserção.
    Livewire::test(Chat::class)->call('alternarHistorico')->assertForbidden();
});

it('recusa montar o painel para quem não tem a permissão', function () {
    comoAdmin(permitido: false);

    Livewire::test(Historico::class)->assertForbidden();
});

it('abre e fecha o painel pelo mesmo botão', function () {
    comoAdmin();

    $componente = Livewire::test(Chat::class);

    $componente->call('alternarHistorico');
    expect($componente->get('historicoAberto'))->toBeTrue();

    $componente->call('alternarHistorico');
    expect($componente->get('historicoAberto'))->toBeFalse();
});

it('fecha o painel só do chat que o abriu', function () {
    comoAdmin();

    $componente = Livewire::test(Chat::class);
    $componente->call('alternarHistorico');

    // O X de um painel de OUTRO chat da mesma página não pode fechar este.
    $componente->call('fecharHistorico', 'outro-chat');
    expect($componente->get('historicoAberto'))->toBeTrue();

    $componente->call('fecharHistorico', $componente->id());
    expect($componente->get('historicoAberto'))->toBeFalse();
});

it('não deixa o cliente abrir o painel por fora do botão', function () {
    comoAdmin();

    // Locked: abrir é o que MONTA o painel, e o mount é onde o gate é checado. Um
    // $wire.set() de quem não tem permissão não vazaria nada — o painel se recusa a
    // montar —, mas estouraria um 403 no meio da conversa de quem estava usando o chat.
    Livewire::test(Chat::class)->set('historicoAberto', true);
})->throws(CannotUpdateLockedPropertyException::class);

it('para de desenhar o painel quando a permissão é revogada com ele aberto', function () {
    comoAdmin();

    $componente = Livewire::test(Chat::class)->call('alternarHistorico');

    $componente->assertSeeLivewire(Historico::class);

    // Duas barreiras, como nas permissões de ferramenta: o gate decide o botão E o
    // painel. Entre um render e outro a permissão pode ter sido tirada.
    Gate::define('claudinho_admin', fn (): bool => false);

    $componente->call('$refresh')->assertDontSeeLivewire(Historico::class);
});

it('deixa o painel flutuante crescer com as colunas, sem passar da tela', function () {
    comoAdmin();

    // Largura auto: quem dimensiona são os filhos. Fixar no painel obrigaria o
    // chat.blade.php a saber se há uma conversa aberta, que é estado do OUTRO
    // componente — e o teto de viewport é o que segura as três colunas num monitor
    // curto.
    Livewire::test(Chat::class, ['flutuante' => true])
        ->call('alternarHistorico')
        ->assertSee('sm:w-auto', false)
        ->assertSee('sm:max-w-[calc(100vw-3rem)]', false);
});

it('faz o chat encolher para o histórico caber, e não o contrário', function () {
    comoAdmin();

    $componente = Livewire::test(Chat::class);

    // Sem shrink-0 e com min-w-0: as colunas do histórico são o mínimo para ler um
    // número e uma conversa, e quem tem largura sobrando é o chat — é de lá que o
    // espaço sai. Sem isto o card empurrava as três colunas para fora da tela.
    $componente->call('alternarHistorico')
        ->assertSee('w-full min-w-0 max-w-4xl', false)
        ->assertDontSee('max-w-4xl mx-auto shrink-0', false);
});

it('põe o histórico ao lado do card inline, com altura própria para a lista rolar', function () {
    comoAdmin();

    Livewire::test(Chat::class)
        ->call('alternarHistorico')
        // Ao lado só a partir do lg: abaixo disso, card mais coluna de histórico não
        // deixa largura utilizável para nenhum dos dois, e o histórico cobre o card.
        ->assertSee('lg:static', false)
        // Altura fixa e self-start: amarrar ao card faria uma lista comprida esticá-lo
        // até sobrar espaço vazio embaixo do campo de pergunta.
        ->assertSee('lg:self-start', false)
        ->assertSee('lg:h-[34rem]', false)
        // O painel é componente próprio: o estado de administração não pode viajar
        // junto de cada mensagem que alguém digita no chat.
        ->assertSeeLivewire(Historico::class);
});

it('lista os identificadores da alteração mais recente para a mais antiga', function () {
    exigeBanco();
    comoAdmin();

    conversaGravada('5547911111111', now()->subDays(2)->toDateTimeString());
    conversaGravada('5547933333333', now()->subMinutes(5)->toDateTimeString());
    conversaGravada('5547922222222', now()->subHours(3)->toDateTimeString());

    $lista = Livewire::test(Historico::class)->instance()->conversas();

    expect(array_column($lista, 'identificador'))
        ->toBe(['5547933333333', '5547922222222', '5547911111111']);
});

it('resume cada linha com o canal, a última fala e quantas houve', function () {
    exigeBanco();
    comoAdmin();

    conversaGravada('5547999998888', now()->toDateTimeString(), trocaDeMensagens(
        'quantas obras ativas?',
        'São 12 obras ativas.'
    ));

    $linha = Livewire::test(Historico::class)->instance()->conversas()[0];

    expect($linha['canal'])->toBe('whatsapp')
        ->and($linha['previa'])->toBe('São 12 obras ativas.')
        ->and($linha['falas'])->toBe(2)
        ->and($linha['ativa'])->toBeTrue();
});

it('mostra a conversa inteira do estado ao escolher um identificador', function () {
    exigeBanco();
    comoAdmin();

    $conversa = conversaGravada('5547999998888', now()->toDateTimeString(), trocaDeMensagens(
        'quantas obras ativas?',
        'São 12 obras ativas.'
    ));

    Livewire::test(Historico::class)
        ->call('abrir', $conversa->id)
        ->assertSee('quantas obras ativas?')
        ->assertSee('São 12 obras ativas.')
        ->assertSee('5547999998888');
});

it('abre a conversa numa coluna ao lado, sem tirar a lista da tela', function () {
    exigeBanco();
    comoAdmin();

    $conversa = conversaGravada('5547999998888', now()->toDateTimeString(), trocaDeMensagens(
        'quantas obras ativas?',
        'São 12 obras ativas.'
    ));

    conversaGravada('5547911111111', now()->subHour()->toDateTimeString());

    $componente = Livewire::test(Historico::class)->call('abrir', $conversa->id);

    $componente
        // A lista some abaixo do xl, onde não cabem lista + conversa + chat, e volta
        // como coluna fixa dali para cima.
        ->assertSee('hidden xl:flex xl:w-[16rem] xl:shrink-0', false)
        // E continua sendo uma lista: a outra conversa segue clicável ao lado.
        ->assertSee('5547911111111')
        // A linha aberta fica marcada, senão nada diz qual delas virou a coluna.
        ->assertSee('aria-current="true"', false);
});

it('fecha só a conversa, e não o painel, pelo X da coluna', function () {
    exigeBanco();
    comoAdmin();

    $conversa = conversaGravada('5547999998888', now()->toDateTimeString());

    $componente = Livewire::test(Historico::class)->call('abrir', $conversa->id);

    // voltar() devolve a lista; fechar() é que avisa o Chat para esconder o painel.
    $componente->call('voltar')->assertSee('Buscar número ou nome');

    expect($componente->get('conversaId'))->toBeNull();
});

it('marca a alteração recusada no histórico, e não só a que foi executada', function () {
    exigeBanco();
    comoAdmin();

    // É o mesmo Exibicao do chat que traduz isto: num registro de alteração, "alterou
    // dados" no lugar de "não autorizada" é o tipo de erro que faz alguém concluir
    // que o sistema mudou algo que não mudou.
    registro([new CancelarPedido]);

    $conversa = conversaGravada('5547999998888', now()->toDateTimeString(), [
        ['role' => 'user', 'content' => 'cancela o pedido 4821'],
        ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'cancelar_pedido', 'input' => ['pedido' => 4821]],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_a', 'content' => json_encode(['recusada' => true])],
        ]],
    ]);

    Livewire::test(Historico::class)
        ->call('abrir', $conversa->id)
        ->assertSee('Alteração não autorizada pelo usuário: cancelar_pedido (pedido: 4821)');
});

it('avisa que a confirmação está com quem conversa, sem oferecer o botão', function () {
    exigeBanco();
    comoAdmin();

    $conversa = conversaGravada('5547999998888', now()->toDateTimeString(), [
        ['role' => 'user', 'content' => 'cancela o pedido 4821'],
    ], [
        ['id' => 'toolu_a', 'nome' => 'cancelar_pedido', 'input' => ['pedido' => 4821], 'confirmacao' => 'Cancelar o pedido 4821?'],
    ]);

    $componente = Livewire::test(Historico::class)->call('abrir', $conversa->id);

    // A decisão é de quem está no aplicativo de mensagens: um botão aqui executaria
    // a alteração em nome dele.
    $componente
        ->assertSee('Cancelar o pedido 4821?')
        ->assertDontSee('Confirmar e executar');
});

it('filtra a lista pelo número', function () {
    exigeBanco();
    comoAdmin();

    conversaGravada('5547911111111', now()->toDateTimeString());
    conversaGravada('5547922222222', now()->subHour()->toDateTimeString());

    $componente = Livewire::test(Historico::class)->set('busca', '2222');

    expect(array_column($componente->instance()->conversas(), 'identificador'))
        ->toBe(['5547922222222']);
});

it('acha o número escrito como quem procura tem em mãos', function () {
    exigeBanco();
    comoAdmin();

    conversaGravada('5547999998888', now()->toDateTimeString());

    // O identificador é gravado como o canal manda; quem procura cola do painel do
    // gateway, da agenda ou do WhatsApp — e lá o número tem parêntese e traço.
    $componente = Livewire::test(Historico::class)->set('busca', '(47) 99999-8888');

    expect(array_column($componente->instance()->conversas(), 'identificador'))
        ->toBe(['5547999998888']);
});

it('filtra a lista pelo nome de quem estava do outro lado', function () {
    exigeBanco();
    comoAdmin();

    // O nome não está na tabela: quem sabe é o resolver da aplicação, o mesmo que o
    // endpoint usa para autenticar a conversa.
    config()->set('claudinho.api.resolvedor', ResolvedorFake::class);

    $conhecida = conversaGravada('5547999998888', now()->toDateTimeString());
    $conhecida->update(['user_id' => 7]);

    conversaGravada('5547911111111', now()->subHour()->toDateTimeString());

    $lista = Livewire::test(Historico::class)->set('busca', 'fulano')->instance()->conversas();

    expect(array_column($lista, 'identificador'))->toBe(['5547999998888'])
        ->and($lista[0]['usuario'])->toBe('FULANO');
});

it('não acha pelo nome a conversa que o resolver passou a atribuir a outra pessoa', function () {
    exigeBanco();
    comoAdmin();

    config()->set('claudinho.api.resolvedor', ResolvedorFake::class);

    // O resolver devolve o usuário 7 para este número, mas a conversa rodou sob o 99.
    // Dar o nome do 7 a ela seria atribuir a conversa a quem não a teve.
    $conversa = conversaGravada('5547999998888', now()->toDateTimeString());
    $conversa->update(['user_id' => 99]);

    $componente = Livewire::test(Historico::class);

    expect($componente->set('busca', 'fulano')->instance()->conversas())->toBe([])
        ->and($componente->set('busca', '')->instance()->conversas()[0]['usuario'])->toBe('usuário #99');
});

it('não varre nada quando a busca é por número, e avisa o teto quando é por nome', function () {
    exigeBanco();
    comoAdmin();

    config()->set('claudinho.historico.varredura', 2);

    foreach (range(1, 3) as $numero) {
        conversaGravada("554799999000{$numero}", now()->subMinutes($numero)->toDateTimeString());
    }

    $componente = Livewire::test(Historico::class);

    // Nome varre só as mais recentes, e a tela precisa poder dizer isso: cap
    // silencioso lê como "essa pessoa não existe".
    $componente->set('busca', 'fulano')->instance()->conversas();
    expect($componente->instance()->varreuTudo())->toBeTrue();

    // Número está na tabela — o LIKE alcança todas, e não há teto a avisar.
    $componente->set('busca', '0003')->instance()->conversas();
    expect($componente->instance()->varreuTudo())->toBeFalse();
});

it('respeita o limite configurado para a lista', function () {
    exigeBanco();
    comoAdmin();

    config()->set('claudinho.historico.limite', 2);

    foreach (range(1, 4) as $numero) {
        conversaGravada("554799999000{$numero}", now()->subMinutes($numero)->toDateTimeString());
    }

    expect(Livewire::test(Historico::class)->instance()->conversas())->toHaveCount(2);
});

it('não estoura quando a conversa aberta é apagada pela faxina', function () {
    exigeBanco();
    comoAdmin();

    $conversa = conversaGravada('5547999998888', now()->toDateTimeString());

    $componente = Livewire::test(Historico::class)->call('abrir', $conversa->id);

    $conversa->delete();

    // Volta a mostrar a lista, em vez de 500: o `claudinho:limpar-conversas` roda no
    // schedule e pode apagar entre o clique e o próximo render.
    $componente->call('$refresh')->assertOk()->assertSee('Histórico');
});

it('mostra o nome em caixa alta, para a coluna não ler como ênfase', function () {
    // O cadastro de origem grava parte dos nomes gritando e parte não; misturados
    // numa coluna estreita, os em caixa alta leem como se estivessem marcados.
    expect(Historico::nome('William Domingues Martins'))->toBe('WILLIAM DOMINGUES MARTINS')
        ->and(Historico::nome('MURILO A. R. KOMAR'))->toBe('MURILO A. R. KOMAR')
        ->and(Historico::nome('João da Silva'))->toBe('JOÃO DA SILVA')
        // Espaço repetido do cadastro entra na conta, senão o nome sai com buraco.
        ->and(Historico::nome('MAXWELL  F.  FERREIRA'))->toBe('MAXWELL F. FERREIRA')
        ->and(Historico::nome('  '))->toBe('')
        ->and(Historico::nome(''))->toBe('');
});

it('lê a última fala de trás para a frente, ignorando rótulo de ferramenta', function () {
    registro([new BuscarPedido]);

    $mensagens = [
        ['role' => 'user', 'content' => 'e o 4821?'],
        ['role' => 'assistant', 'content' => [
            ['type' => 'text', 'text' => 'Já verifico.'],
            ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'buscar_pedido', 'input' => ['pedido' => 4821]],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_a', 'content' => json_encode(['situacao' => 'em aberto'])],
        ]],
        ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'O pedido 4821 está em aberto.']]],
    ];

    expect(Exibicao::ultimaFala($mensagens))->toBe('O pedido 4821 está em aberto.')
        // A volta de tool_result é mensagem para a API e não é fala nenhuma: contá-la
        // faria esta troca aparecer como quatro.
        ->and(Exibicao::falas($mensagens))->toBe(3);
});

it('não devolve fala nenhuma de conversa vazia', function () {
    expect(Exibicao::ultimaFala([]))->toBe('')
        ->and(Exibicao::falas([]))->toBe(0);
});
