<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rogga\Claudinho\Claude;
use Rogga\Claudinho\Livewire\Chat;
use Rogga\Claudinho\Livewire\Configuracoes;
use Rogga\Claudinho\Models\Configuracao;

// Só este arquivo precisa de banco. Migra na mão em vez de usar RefreshDatabase
// porque a trait roda no setUp, antes do skip abaixo — e o :memory: do testbench
// já nasce vazio em cada teste, então não há o que reverter no fim.
beforeEach(function () {
    exigeBanco();
});

/**
 * Autentica um usuário e resolve o gate de administração como quiser.
 */
function comoAdmin(bool $permitido = true): void
{
    Gate::define('claudinho_admin', fn ($usuario): bool => $permitido);

    $usuario = new class extends User
    {
        protected $table = 'users';
    };

    $usuario->forceFill(['id' => 1, 'name' => 'Admin']);

    test()->actingAs($usuario);
}

it('grava a chave da API criptografada, não em texto puro', function () {
    Configuracao::definir('api_key', 'sk-ant-api03-super-secreta');

    $bruto = (string) DB::table('claudinho_configuracoes')->where('chave', 'api_key')->value('valor');

    expect($bruto)
        ->not->toContain('sk-ant-api03-super-secreta')
        ->and(Configuracao::valor('api_key'))->toBe('sk-ant-api03-super-secreta');
});

it('faz o valor gravado em tela vencer o config', function () {
    config()->set('claudinho.model', 'claude-sonnet-5');

    expect(Configuracao::valor('model', config('claudinho.model')))->toBe('claude-sonnet-5');

    Configuracao::definir('model', 'claude-opus-5');

    expect(Configuracao::valor('model', config('claudinho.model')))->toBe('claude-opus-5');
});

it('trata valor vazio como ausente, voltando para o config', function () {
    config()->set('claudinho.api_key', 'do-env');

    Configuracao::definir('api_key', 'da-tela');
    expect(Configuracao::valor('api_key', config('claudinho.api_key')))->toBe('da-tela');

    // É assim que o botão Limpar devolve o controle para o .env.
    Configuracao::definir('api_key', null);
    expect(Configuracao::valor('api_key', config('claudinho.api_key')))->toBe('do-env');
});

it('usa no payload da API o modelo definido em tela', function () {
    config()->set('claudinho.model', 'claude-sonnet-5');
    Configuracao::definir('model', 'claude-opus-5');
    Configuracao::definir('api_key', 'sk-ant-da-tela');

    fakeStream([['type' => 'message_stop']]);

    eventosDe((new Claude)->stream([['role' => 'user', 'content' => 'oi']]));

    Http::assertSent(
        fn ($request) => $request['model'] === 'claude-opus-5'
            && $request->header('x-api-key') === ['sk-ant-da-tela']
    );
});

it('esconde a engrenagem de quem não tem a permissão', function () {
    comoAdmin(permitido: false);

    Livewire::test(Chat::class)
        ->assertDontSee('claudinho-abrir-configuracoes', false)
        ->assertDontSee('Configurações do Claudinho');
});

it('mostra a engrenagem para quem tem a permissão', function () {
    comoAdmin();

    Livewire::test(Chat::class)
        ->assertSee('claudinho-abrir-configuracoes', false)
        ->assertSee('aria-label="Configurações"', false);
});

it('salva modelo e chave pelo componente de configurações', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->set('modelo', 'claude-haiku-4-5')
        ->set('chaveNova', 'sk-ant-api03-uma-chave-bem-longa')
        ->call('salvar')
        ->assertHasNoErrors()
        // O campo é só de escrita: precisa sair limpo para não voltar no HTML.
        ->assertSet('chaveNova', '')
        ->assertDispatched('claudinho-configuracoes-salvas');

    expect(Configuracao::valor('model'))->toBe('claude-haiku-4-5')
        ->and(Configuracao::valor('api_key'))->toBe('sk-ant-api03-uma-chave-bem-longa');
});

it('mantém a chave atual quando o campo é enviado em branco', function () {
    comoAdmin();
    Configuracao::definir('api_key', 'sk-ant-api03-a-que-ja-existia');

    Livewire::test(Configuracoes::class)
        ->set('modelo', 'claude-opus-5')
        ->call('salvar')
        ->assertHasNoErrors();

    expect(Configuracao::valor('api_key'))->toBe('sk-ant-api03-a-que-ja-existia');
});

it('nunca coloca a chave gravada no HTML da tela', function () {
    comoAdmin();
    Configuracao::definir('api_key', 'sk-ant-api03-nao-pode-aparecer');

    $html = Livewire::test(Configuracoes::class)->html();

    expect($html)
        ->not->toContain('sk-ant-api03-nao-pode-aparecer')
        // Mas a dica mascarada confirma qual chave está valendo.
        ->toContain('sk-ant-a');
});

it('recusa abrir as configurações para quem não tem a permissão', function () {
    comoAdmin(permitido: false);

    // O abort acontece no mount, então não dá para encadear set()/call() depois:
    // sem componente montado, o Livewire responde 404 e esconderia o 403.
    Livewire::test(Configuracoes::class)->assertForbidden();
});

it('revalida a permissão em cada gravação, não só ao montar', function () {
    comoAdmin();

    $componente = Livewire::test(Configuracoes::class);

    // Permissão revogada com a tela já aberta. mount() roda uma vez; se salvar()
    // não checasse por conta própria, a gravação passaria.
    Gate::define('claudinho_admin', fn (): bool => false);

    $componente->set('modelo', 'claude-opus-5')->call('salvar')->assertForbidden();

    expect(Configuracao::valor('model'))->toBeNull();
});

it('rejeita chave curta demais para ser válida', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->set('modelo', 'claude-opus-5')
        ->set('chaveNova', 'sk-ant-curta')
        ->call('salvar')
        ->assertHasErrors(['chaveNova' => 'min']);

    expect(Configuracao::valor('api_key'))->toBeNull();
});

it('sobrevive à tabela ainda não migrada, caindo no config', function () {
    config()->set('claudinho.model', 'claude-sonnet-5');
    config()->set('claudinho.api_key', 'do-env');

    Schema::drop('claudinho_configuracoes');

    expect(Configuracao::todas())->toBe([])
        ->and(Configuracao::valor('model', config('claudinho.model')))->toBe('claude-sonnet-5')
        ->and(Configuracao::valor('api_key', config('claudinho.api_key')))->toBe('do-env');
});

/**
 * Grava uma linha cifrada por OUTRA chave, que é o que sobra no banco depois de
 * rotacionar a APP_KEY (ou de apontar o app para um banco populado por outro
 * ambiente). O ciphertext é bem formado; o que falha é o MAC.
 */
function linhaIlegivel(string $chave, string $valor = 'valor-antigo'): void
{
    $outraChave = new Encrypter(random_bytes(32), (string) config('app.cipher'));

    DB::table('claudinho_configuracoes')->insert([
        'chave' => $chave,
        'valor' => $outraChave->encryptString($valor),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Insert direto na tabela não passa por definir(), então a memória fica velha.
    Configuracao::esquecer();
}

it('regrava por cima de valor cifrado com outra APP_KEY', function () {
    linhaIlegivel('api_key', 'sk-ant-da-chave-antiga');

    // A leitura já era tolerante: linha ilegível conta como ausente.
    expect(Configuracao::valor('api_key', 'do-env'))->toBe('do-env');

    // E a escrita não era: o isDirty() de dentro do save() decifrava o valor
    // gravado para comparar com o novo e estourava DecryptException aqui, antes
    // de escrever — deixando o usuário sem caminho de volta.
    Configuracao::definir('api_key', 'sk-ant-a-nova');

    expect(Configuracao::valor('api_key'))->toBe('sk-ant-a-nova');
});

it('limpa por cima de valor cifrado com outra APP_KEY', function () {
    config()->set('claudinho.api_key', 'do-env');
    linhaIlegivel('api_key');

    // Caminho do botão Limpar: grava null, que devolve o controle ao env.
    Configuracao::definir('api_key', null);

    expect(Configuracao::valor('api_key', config('claudinho.api_key')))->toBe('do-env');
});

it('deixa a tela de configurações recuperar de linhas ilegíveis', function () {
    comoAdmin();
    linhaIlegivel('model', 'claude-sonnet-5');
    linhaIlegivel('api_key', 'sk-ant-da-chave-antiga');

    Livewire::test(Configuracoes::class)
        ->set('modelo', 'claude-opus-5')
        ->set('chaveNova', 'sk-ant-api03-uma-chave-bem-longa')
        ->call('salvar')
        ->assertHasNoErrors()
        ->assertDispatched('claudinho-configuracoes-salvas');

    expect(Configuracao::valor('model'))->toBe('claude-opus-5')
        ->and(Configuracao::valor('api_key'))->toBe('sk-ant-api03-uma-chave-bem-longa');
});

it('grava na mesma linha em vez de recriar o registro', function () {
    Configuracao::definir('model', 'claude-sonnet-5');
    $id = DB::table('claudinho_configuracoes')->where('chave', 'model')->value('id');

    Configuracao::definir('model', 'claude-opus-5');

    // Recriar a linha seria a saída fácil para o DecryptException, mas perde o
    // registro e corre risco no unique de chave.
    expect(DB::table('claudinho_configuracoes')->where('chave', 'model')->value('id'))->toBe($id)
        ->and(DB::table('claudinho_configuracoes')->where('chave', 'model')->count())->toBe(1);
});

it('mantém selecionável um modelo fora da lista do config', function () {
    comoAdmin();
    Configuracao::definir('model', 'claude-fable-5');

    $disponiveis = Livewire::test(Configuracoes::class)->instance()->modelosDisponiveis();

    expect($disponiveis)->toHaveKey('claude-fable-5')
        ->and(array_key_first($disponiveis))->toBe('claude-fable-5');
});

/**
 * ===========================================
 * Interruptores de canal e documentação da API
 * ===========================================
 */
it('grava e devolve os interruptores de canal', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)
        ->assertSet('flutuante', true)
        // O padrão do atendimento é DESLIGADO: atualizar o pacote não abre endpoint.
        ->assertSet('api', false)
        ->set('api', true)
        ->call('salvar');

    expect(Configuracao::booleano('api_ativa', false))->toBeTrue();

    Livewire::test(Configuracoes::class)
        ->set('flutuante', false)
        ->set('api', false)
        ->call('salvar')
        ->assertHasNoErrors();

    expect(Configuracao::booleano('flutuante_ativo', true))->toBeFalse()
        ->and(Configuracao::booleano('api_ativa', true))->toBeFalse();

    // E voltam para a tela como foram gravados.
    Livewire::test(Configuracoes::class)
        ->assertSet('flutuante', false)
        ->assertSet('api', false);
});

it('guarda booleano como 1/0, porque a coluna é texto', function () {
    Configuracao::definirBooleano('api_ativa', false);

    // "false" em texto seria verdadeiro — o erro clássico de flag em tabela
    // chave/valor. O valor cru precisa ser '0'.
    expect(Configuracao::valor('api_ativa'))->toBe('0')
        ->and(Configuracao::booleano('api_ativa', true))->toBeFalse();

    Configuracao::definirBooleano('api_ativa', true);

    expect(Configuracao::booleano('api_ativa', false))->toBeTrue();
});

it('cai no config quando o interruptor nunca foi gravado', function () {
    config()->set('claudinho.flutuante.ativo', false);

    comoAdmin();

    Livewire::test(Configuracoes::class)->assertSet('flutuante', false);
});

it('esconde o botão flutuante quando desligado em tela', function () {
    Configuracao::definirBooleano('flutuante_ativo', false);

    Livewire::test(Chat::class, ['flutuante' => true])
        ->assertDontSee('Abrir o assistente')
        ->assertDontSee('role="dialog"', false);

    // O card na página não obedece a este interruptor: quem o colocou ali foi a
    // aplicação, e não cabe a uma configuração de tela escondê-lo.
    Livewire::test(Chat::class)->assertSee('wire:submit="enviar"', false);
});

it('mostra o botão flutuante de volta quando religado', function () {
    Configuracao::definirBooleano('flutuante_ativo', false);
    Livewire::test(Chat::class, ['flutuante' => true])->assertDontSee('Abrir o assistente');

    Configuracao::definirBooleano('flutuante_ativo', true);
    Livewire::test(Chat::class, ['flutuante' => true])->assertSee('Abrir o assistente');
});

it('diagnostica o que falta para a API funcionar', function () {
    comoAdmin();
    config()->set('claudinho.api.token', null);
    config()->set('claudinho.api.resolvedor', null);

    $situacao = Livewire::test(Configuracoes::class)->instance()->situacaoApi();

    // Desligado, sem token, sem resolvedor — só a migration está feita.
    expect($situacao['pronta'])->toBeFalse()
        ->and(collect($situacao['itens'])->pluck('ok')->all())->toBe([false, false, false, true]);

    $textos = collect($situacao['itens'])->pluck('texto')->implode(' ');

    expect($textos)->toContain('Atendimento desligado')
        ->toContain('Sem token')
        // O resolvedor é o único item que a tela não resolve: é uma classe.
        ->toContain('claudinho.api.resolvedor');
});

it('fica pronta quando tudo foi resolvido em tela, menos o resolvedor que é código', function () {
    comoAdmin();
    config()->set('claudinho.api.resolvedor', ResolvedorFake::class);

    $componente = Livewire::test(Configuracoes::class)->set('api', true)->call('salvar');

    $componente->call('gerarToken');

    $situacao = $componente->instance()->situacaoApi();

    expect($situacao['pronta'])->toBeTrue()
        ->and($situacao['ativa'])->toBeTrue();
});

it('mostra a URL real do ambiente na documentação', function () {
    comoAdmin();
    config()->set('claudinho.api.habilitado', true);
    config()->set('claudinho.api.prefixo', 'bot/v1');

    $componente = Livewire::test(Configuracoes::class);

    expect($componente->instance()->situacaoApi()['url'])->toEndWith('/bot/v1/conversa');

    // Documentação embutida, e não link: só ela sabe a URL deste ambiente.
    $componente->assertSee('Documentação da API')->assertSee('bot/v1/conversa');
});

it('gera o token, mostra uma vez e guarda mascarado', function () {
    comoAdmin();

    $componente = Livewire::test(Configuracoes::class);

    expect($componente->instance()->tokenEmUso()['origem'])->toBe('ausente');

    $componente->call('gerarToken');

    $gerado = $componente->get('tokenGerado');

    // Mostrado inteiro nesta resposta: quem opera precisa copiar para o gateway.
    expect($gerado)->toStartWith('clau_')
        ->and(mb_strlen($gerado))->toBe(53)
        ->and(Configuracao::valor('api_token'))->toBe($gerado);

    $componente->assertSee($gerado)->assertSee('não será mostrado de novo');

    // E some na interação seguinte, sobrando só a máscara.
    $componente->call('salvar');

    expect($componente->get('tokenGerado'))->toBe('');

    $recarregado = Livewire::test(Configuracoes::class);

    expect($recarregado->instance()->tokenEmUso()['origem'])->toBe('tela')
        ->and($recarregado->get('tokenGerado'))->toBe('');

    $recarregado->assertDontSee($gerado);
});

it('guarda o token criptografado, não em texto puro', function () {
    comoAdmin();

    Livewire::test(Configuracoes::class)->call('gerarToken');

    $bruto = (string) DB::table('claudinho_configuracoes')->where('chave', 'api_token')->value('valor');

    expect($bruto)->not->toContain('clau_')
        ->and(Configuracao::valor('api_token'))->toStartWith('clau_');
});

it('troca e revoga o token', function () {
    comoAdmin();

    $componente = Livewire::test(Configuracoes::class);

    $componente->call('gerarToken');
    $primeiro = $componente->get('tokenGerado');

    $componente->call('gerarToken');

    // Gerar de novo invalida o anterior na hora: é rotação, não acúmulo.
    expect($componente->get('tokenGerado'))->not->toBe($primeiro)
        ->and(Configuracao::valor('api_token'))->not->toBe($primeiro);

    $componente->call('revogarToken');

    expect(Configuracao::valor('api_token'))->toBeNull()
        ->and($componente->instance()->tokenEmUso()['origem'])->toBe('ausente');
});

it('exige a permissão para mexer no token', function () {
    comoAdmin();
    $componente = Livewire::test(Configuracoes::class);

    Gate::define('claudinho_admin', fn (): bool => false);

    $componente->call('gerarToken')->assertForbidden();

    expect(Configuracao::valor('api_token'))->toBeNull();
});
