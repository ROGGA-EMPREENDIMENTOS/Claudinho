# Claudinho

Assistente de chat em linguagem natural sobre os dados da própria aplicação Laravel,
integrado à API do Claude via *tool use*.

O pacote entrega tudo o que **não** é domínio: transporte HTTP/SSE, loop de ferramentas,
UI de chat com streaming, markdown sanitizado e gráficos em SVG. Você escreve apenas as
consultas do seu sistema — e o pacote garante que o modelo só veja o que o usuário pode ver.

## O que ele faz

- **Chat com streaming** — a resposta aparece palavra por palavra (`wire:stream`).
- **Tool use com loop** — o modelo consulta o banco, recebe o resultado e continua, até 5 voltas.
- **Autorização em duas barreiras** — o que o usuário não pode ver não é nem oferecido ao
  modelo, e a permissão é revalidada antes de cada execução.
- **Ações com confirmação** — ferramenta que altera dados pausa o loop e mostra o efeito
  para o usuário aprovar. Nada é alterado sem clique.
- **Markdown sanitizado** — tabela e lista renderizam; `<script>` e `javascript:` são removidos.
- **Gráfico de barras em SVG** — gerado no servidor a partir de dados tipados. O modelo
  nunca emite HTML.
- **Configuração em tela** — modelo e chave da API pela engrenagem do header, atrás de gate
  próprio. A chave vai criptografada no banco e nunca volta para o navegador.
- **Tema claro e escuro** — acompanha o da aplicação pelas variantes `dark:`, sem
  configuração extra no pacote.

## Instalação

```bash
composer require rogga/claudinho
php artisan vendor:publish --tag=claudinho-config
php artisan vendor:publish --tag=claudinho-assets   # logo do header
php artisan migrate                                 # tabela de configurações
```

O `claudinho-assets` copia os PNGs do logo para `public/vendor/claudinho`. Sem ele o header
aparece com imagem quebrada — ou defina `'logo' => false` no config para exibir só o título.
Como é arquivo em `public/`, republique a cada `composer update` do pacote (vale colocar
`@php artisan vendor:publish --tag=claudinho-assets --force` no `post-update-cmd`).

No `.env`:

```
ANTHROPIC_API_KEY=sk-ant-api03-...
ANTHROPIC_MODEL=claude-sonnet-5           # padrão; ou claude-opus-5, mais capaz e mais caro
CLAUDINHO_PERMISSAO=use_assistente        # opcional: gate para abrir o chat
CLAUDINHO_PERMISSAO_ADMIN=claudinho_admin # gate da engrenagem de configurações
```

Chave e modelo também dão para configurar em tela — ver a seção seguinte. O `.env` continua
valendo como fallback: é de lá que sai o valor enquanto nada foi gravado pela engrenagem.

Requisitos do lado da aplicação: Livewire 3+ e, para as tabelas do markdown saírem
estilizadas, o plugin `@tailwindcss/typography`. Sem ele o conteúdo aparece, só sem estilo.

As classes do chat são compiladas pelo Tailwind da aplicação, então inclua as views do
pacote no `content`:

```js
// tailwind.config.js
content: [
    './vendor/rogga/claudinho/resources/views/**/*.blade.php',
    // ...
],
```

## Configurações em tela

A engrenagem no header abre um modal onde dá para trocar **modelo** e **chave da API** sem
deploy. Aparece só para quem passa no gate `permissao_admin` (padrão `claudinho_admin`).

A precedência é: o que foi gravado em tela vence o `config`/`.env`; valor vazio em tela cai
no `.env` de novo — é o que o botão *Limpar* faz. Assim um ambiente pode continuar 100%
configurado por variável de ambiente, sem nunca abrir a engrenagem.

O que a tela **não** faz, de propósito:

- **Não devolve a chave gravada.** Propriedade pública do Livewire vai para o HTML e volta em
  cada requisição, então o campo é só de escrita. O que aparece é uma máscara
  (`sk-ant-a••••••••1b2c`) para confirmar qual chave está valendo.
- **Não valida a chave contra a API.** Chave errada só aparece na primeira pergunta, como
  erro vindo da Anthropic. Se quiser um botão *Testar conexão*, é um `GET /v1/models` — não
  está incluído.

A chave vai criptografada no banco, com a `APP_KEY`. Duas consequências práticas: rotacionar
a `APP_KEY` sem re-encriptar torna o valor gravado indecifrável, e o pacote trata isso como
*chave ausente* — volta a usar o `.env` e a engrenagem permite regravar, em vez de derrubar o
chat com erro de criptografia. E backup de banco sem a `APP_KEY` não restaura a chave.

O modelo em uso é lido do banco a cada resposta, sem cache: o valor decifrado da chave não
deve ir para o cache store, que costuma ser menos protegido que o banco. São duas consultas
leves por resposta — não por render de tela. Sem migration rodada, sem driver de banco ou
sem conexão, tudo cai no `.env` em silêncio, e o chat continua funcionando.

O select de modelos vem de `config('claudinho.modelos')` — é só a lista da UI, edite à
vontade. Um modelo gravado que saiu da lista continua selecionável, para o select não trocar
o modelo de produção sozinho.

## Tema claro e escuro

O botão no header alterna entre três estados: **seguindo o sistema** → **claro** → **escuro**.
Ciclar em vez de alternar entre dois é o que permite voltar a seguir o sistema depois de uma
escolha manual. A escolha fica no `localStorage` (chave `claudinho-tema`), por navegador.

Onde a classe `dark` é aplicada depende de `tema.alvo`:

- **`componente`** (padrão) — só no card do chat. Funciona em qualquer aplicação, inclusive
  nas que não têm tema escuro próprio: o resto da página não muda.
- **`documento`** — no `<html>`, alternando a aplicação inteira. Use quando **todas** as
  telas já têm tema escuro; caso contrário o chat escurece sozinho no meio de uma página clara.

Se a aplicação já tem seletor de tema próprio, ponha `tema.seletor => false` para não ficarem
dois. O `x-effect` continua ativo nesse caso, então quem já havia escolhido não perde a escolha.

Tem um `<script>` inline no topo do componente que aplica a classe durante o parse do HTML,
antes do primeiro paint. Sem ele, quem escolheu escuro vê um lampejo claro até o Alpine
inicializar. O pacote não consegue injetar no `<head>` da aplicação, então essa é a janela
mais cedo disponível — resolve o flash do chat, não o da página acima dele.

As classes `dark:` funcionam com `darkMode: 'media'` e `'class'`: o Tailwind gera um seletor
de ancestral (`:is(.dark *)`), que casa tanto com o `<html>` quanto com o card do chat.

O logo tem quatro arquivos
porque PNG tem cor fixa: tinta sobre fundo claro, creme sobre fundo escuro, e em cada tema
a assinatura completa de `sm` para cima contra a marca sozinha em telas estreitas.

| | claro | escuro |
|---|---|---|
| até `sm` | `claudinho-icone-claro.png` | `claudinho-icone-escuro.png` |
| `sm` + | `claudinho-lockup-claro.png` | `claudinho-lockup-escuro.png` |

Para trocar a arte, sobrescreva esses quatro nomes em `resources/images/` do pacote (ou
publique as views com `--tag=claudinho-views` e edite `components/logo.blade.php`). Os
originais em alta ficam em `art/`.

## Colocando na tela

```php
// routes/web.php
Route::get('/assistente', fn () => view('assistente'))
    ->middleware(['auth', 'can:use_assistente'])
    ->name('assistente');
```

```blade
{{-- resources/views/assistente.blade.php --}}
@extends('layouts.app')

@section('content')
    @livewire('claudinho.chat')
@endsection
```

## Escrevendo uma ferramenta

Uma ferramenta é uma consulta somente-leitura. Para alterar dados, ver
[Ações](#ações-quando-a-ferramenta-altera-dados) — é outra classe base, com outras regras.
Estenda `FerramentaBase` e declare o que importa:

```php
namespace App\Claudinho\Ferramentas;

use App\Models\Obra;
use Illuminate\Database\Eloquent\Builder;
use Rogga\Claudinho\FerramentaBase;

class ListarObras extends FerramentaBase
{
    protected ?string $permissao = 'cad_obras';

    protected int $limite = 200;

    public function nome(): string
    {
        return 'listar_obras';
    }

    public function descricao(): string
    {
        return 'Lista as obras com cidade e situação. Use também para descobrir o nome '
            .'correto de uma obra antes de filtrar as outras ferramentas.';
    }

    public function propriedades(): array
    {
        return [
            'situacao' => [
                'type' => 'string',
                'enum' => ['ativas', 'inativas', 'todas'],
                'description' => 'Padrão: ativas.',
            ],
            'termo' => ['type' => 'string', 'description' => 'Nome parcial. Opcional.'],
        ];
    }

    public function executar(array $input): array
    {
        $situacao = $input['situacao'] ?? 'ativas';
        $termo = trim((string) ($input['termo'] ?? ''));

        return $this->paginado(
            Obra::query()   // Global Scopes do model são herdados aqui
                ->when($situacao === 'ativas', fn (Builder $q) => $q->where('ativo', true))
                ->when($termo, fn (Builder $q) => $q->where('descricao', 'like', "%{$termo}%"))
                ->orderBy('descricao'),
            fn (Obra $obra) => [
                'obra' => $obra->descricao,
                'cidade' => $obra->cidade,
                'ativa' => (bool) $obra->ativo,
            ],
            'obras',
        );
    }
}
```

Registre em `config/claudinho.php`:

```php
'ferramentas' => [
    App\Claudinho\Ferramentas\ListarObras::class,
],
```

O `paginado()` devolve `total_encontrado`, `mostrando` e `truncado` — é o que permite ao
assistente dizer *"são 84, mostrando 25"* em vez de contar as linhas que recebeu e errar.

## Ações: quando a ferramenta altera dados

Uma **ação** é uma ferramenta que escreve. Estenda `AcaoBase` (que é uma `FerramentaBase`
com o contrato `Acao`) e acrescente `confirmacao()`:

```php
namespace App\Claudinho\Acoes;

use App\Models\Pedido;
use Rogga\Claudinho\AcaoBase;

class CancelarPedido extends AcaoBase
{
    protected ?string $permissao = 'cancelar_pedidos';

    public function nome(): string
    {
        return 'cancelar_pedido';
    }

    public function descricao(): string
    {
        return 'Cancela um pedido que ainda está em aberto. Não serve para pedido já '
            .'faturado — nesse caso é estorno, que não tem ferramenta.';
    }

    public function propriedades(): array
    {
        return [
            'pedido' => ['type' => 'integer', 'description' => 'Id do pedido.'],
            'motivo' => ['type' => 'string', 'description' => 'Motivo do cancelamento.'],
        ];
    }

    public function obrigatorios(): array
    {
        return ['pedido', 'motivo'];
    }

    /** O que o usuário lê antes de aprovar. */
    public function confirmacao(array $input): string
    {
        $pedido = Pedido::find($input['pedido']);

        return $pedido
            ? "Cancelar o pedido {$pedido->numero} de {$pedido->cliente->nome} "
                ."(R$ {$pedido->total})? Motivo: {$input['motivo']}."
            : "Cancelar o pedido {$input['pedido']}? Motivo: {$input['motivo']}.";
    }

    public function executar(array $input): array
    {
        $pedido = Pedido::find($input['pedido']);

        if (! $pedido) {
            return ['erro' => "Pedido {$input['pedido']} não encontrado."];
        }

        if ($pedido->faturado) {
            return ['erro' => "O pedido {$pedido->numero} já foi faturado e não pode ser cancelado."];
        }

        $pedido->cancelar($input['motivo']);

        return ['cancelado' => $pedido->numero];
    }
}
```

Registre no mesmo array das consultas:

```php
'ferramentas' => [
    App\Claudinho\Ferramentas\ListarObras::class,
    App\Claudinho\Acoes\CancelarPedido::class,
],
```

### O que o pacote faz com isso

1. **Marca a descrição.** A definição enviada ao modelo ganha *"ATENÇÃO: esta ferramenta
   ALTERA DADOS. A interface pede confirmação ao usuário antes de executar"*. O system prompt
   passa a instruir o modelo a **chamar** a ferramenta em vez de pedir permissão por texto —
   pedir duas vezes é o que treina o usuário a clicar sem ler.
2. **Pausa o loop.** No `tool_use` de uma ação, o loop para *antes de executar* e a conversa
   mostra um card com a sua `confirmacao()` e os botões **Confirmar e executar** / **Cancelar**.
   O campo de pergunta fica bloqueado até a decisão.
3. **Retoma.** Confirmado, a ação executa (com a permissão revalidada) e o loop continua de
   onde parou. Cancelado, o modelo recebe `{"recusada": true}` e volta a falar — o usuário
   tem uma resposta, não um card que some.
4. **Registra na conversa.** O rótulo distingue *"Alterou dados: cancelar_pedido (pedido:
   4821)"* de *"Alteração não autorizada pelo usuário"* e de *"Alteração falhou"*, com cor
   própria. Consulta e alteração nunca aparecem com o mesmo verbo.

Se o modelo pedir várias ações numa volta só, cada uma ganha o seu card e o loop só segue
quando a última for decidida — a API exige que todo `tool_use` seja respondido de uma vez.
Consultas da mesma volta rodam na hora, mas o resultado fica retido até lá.

### Regras que a `AcaoBase` impõe

- **Gate obrigatório.** `permissao` em `null` **nega** numa ação, ao contrário de uma
  consulta (onde `null` libera, porque é o caso do gráfico). Esquecer o gate não vira
  escrita liberada para qualquer usuário autenticado.
- **A confirmação é a interface do risco.** `confirmacao()` recebe o input do modelo e deve
  nomear o efeito e os registros afetados. *"Cancelar o pedido 4821 da Acme (R$ 12.400)"* é
  confirmável; *"Executar cancelar_pedido"* não é. Consultar o banco aqui para montar a
  frase é bem-vindo — é o que transforma um id em algo que o usuário reconhece.
- **Valide em `executar()` de novo.** A confirmação é texto; a regra de negócio é código. O
  usuário pode aprovar o cancelamento de um pedido que faturou nesse meio-tempo.
- **Erro previsível volta como `['erro' => '...']`**, como em qualquer ferramenta. Se o
  efeito puder ficar aplicado pela metade, diga isso na mensagem: o system prompt instrui o
  modelo a não repetir a chamada e a sugerir conferir o registro.

Para alteração de efeito pequeno e reversível, `protected bool $confirmar = false;` executa
direto, sem card. O rótulo na conversa e o aviso na descrição continuam — o que se perde é
só o clique.

## O glossário é o que faz a diferença

Schema o modelo descobre sozinho; **semântica não**. Coloque em `config/claudinho.php`
as regras que não estão em lugar nenhum do código:

```php
'glossario' => [
    'funcionarios.is_obra guarda o id da obra em que a pessoa está trabalhando naquele
     momento; é foto do instante, não lotação do período.',
    'users.obra_scoped vazio significa acesso a todas as obras.',
    'A tabela funcionario_obras é pouco populada; não use como fonte de headcount.',
],
```

Esse arquivo crescer **é** o mecanismo de aprendizado do assistente. O modelo não aprende
entre conversas — cada conversa começa do zero, com o que estiver aqui.

## Regras que o pacote impõe

Não são configuráveis, porque são a diferença entre um assistente confiável e um que
inventa dados:

- Ferramenta não permitida não é exposta **nem** executada.
- Resultado vazio é comunicado como "não há registro entre os dados retornados", nunca
  como "não existe no sistema".
- `truncado: true` obriga o modelo a informar o total real.
- Nenhuma ferramenta aceita SQL, nome de tabela ou de coluna vindo do modelo.
- A resposta do modelo nunca vira HTML executável.

## Gráficos

O modelo chama `gerar_grafico` com dados tipados e o SVG é desenhado no servidor:

```json
{"tipo": "barra_horizontal", "titulo": "Documentos vencendo por obra",
 "series": [{"rotulo": "AZALEIA", "valor": 37}, {"rotulo": "GRANT", "valor": 24}]}
```

Dois tipos: `barra_horizontal` (padrão, para categorias com nome longo) e `barra` (vertical,
para série no tempo). Não há pizza de propósito — parte-do-todo lê melhor em barra ordenada.

A especificação é revalidada na renderização, porque o estado do Livewire rehidrata o input
do `tool_use` como JSON. Rótulo longo é cortado e a coluna do valor é dimensionada pelo maior
número da série, então nome comprido ou valor de sete dígitos não colidem nem vazam.

Ao trocar `grafico.cor`, valide a cor contra a superfície onde o gráfico é renderizado
(banda de luminosidade, chroma e contraste ≥ 3:1).

## O que o pacote NÃO faz

- **Text-to-SQL.** Se a autorização da sua aplicação vive em Gates e Global Scopes, uma
  ferramenta que aceita SQL é bypass de permissão por construção.
- **Escrita sem você escrever.** O pacote não traz nenhuma ação pronta e não deduz nenhuma
  do seu schema: quem declara o que pode ser alterado é você, uma classe por ação. Ver
  [Ações](#ações-quando-a-ferramenta-altera-dados).
- **Desfazer.** Confirmada a ação, o efeito é o que a sua classe fizer. Se precisa de
  reversão, é a sua aplicação que grava o histórico e oferece o desfazer.
- **Aprender sozinho.** Ver a seção do glossário.
- **Acessar a web.** Nenhuma tool de busca, fetch de URL ou maps é declarada — todo o
  contexto que entra vem do banco da sua aplicação. Consequência prática: as regras
  anti-invenção do system prompt são escopadas a **registros**, então para pergunta de
  conhecimento geral (um CNPJ, uma norma técnica, uma distância) o modelo pode responder
  da memória de treinamento, que tem data de corte. Se isso importa no seu caso, diga no
  `contexto` para ele avisar quando estiver fora dos dados do sistema.

## Custo

O breakpoint de cache fica no system prompt, o que cobre também as definições de ferramenta.
A partir da segunda mensagem da conversa, esse prefixo custa ~10%. O histórico é limitado a
20 mensagens (`limite_historico`) e cada consulta a 25 linhas por padrão.

Um detalhe do cache que vale saber: em `claude-sonnet-5` o prefixo mínimo cacheável é de
1024 tokens (em `claude-opus-5` são 512). Abaixo disso o cache simplesmente não é criado,
sem erro nenhum — o sintoma é `cache_read_input_tokens` sempre em zero. Com ferramentas
registradas e glossário preenchido o prefixo passa disso com folga; numa instalação recém
publicada, com `contexto` de uma linha e nenhuma ferramenta, pode não passar.

## Testes

```bash
composer install
./vendor/bin/pest
```

## Licença

MIT.
