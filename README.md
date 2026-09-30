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
- **Configuração em tela** — modelo, chave da API e os interruptores de canal (botão
  flutuante, atendimento pela API) pela engrenagem do header, atrás de gate próprio. A chave
  vai criptografada no banco e nunca volta para o navegador.
- **Tema claro e escuro** — acompanha o da aplicação pelas variantes `dark:`, sem
  configuração extra no pacote.
- **Janela "Sobre"** — versão instalada, versões do ambiente e modelo em uso, além de como o
  assistente trabalha, pelo ⓘ do header. Sem gate: é informação de quem usa o chat.
  Ver [Janela "Sobre"](#janela-sobre).
- **Histórico das conversas** — o relógio do header abre, ao lado da caixa de conversa, a
  lista das conversas gravadas pelos canais externos, da mais recente para a mais antiga;
  clicar num identificador abre a conversa inteira numa segunda coluna, ao lado da lista.
  Busca por número ou por nome, atrás do gate de administração.
  Ver [Histórico de conversas](#histórico-de-conversas).
- **Card na página ou chat flutuante** — o mesmo componente serve de tela dedicada ou de
  botão fixo num canto do layout, aberto em painel. Ver [Chat flutuante](#chat-flutuante).
- **Endpoint HTTP** — o mesmo assistente por API, para WhatsApp e outros canais externos,
  com o escopo de permissão do usuário que a aplicação apontar. Ver
  [Endpoint para canais externos](#endpoint-para-canais-externos-whatsapp-e-afins).
- **Foto e vídeo no canal externo** — o anexo do gateway é baixado, a imagem é descrita pela
  API de visão e a descrição entra na conversa; o arquivo vai para onde a sua aplicação
  mandar. Desligado por padrão. Ver [Foto e vídeo](#foto-e-vídeo).
- **Áudio transcrito no canal externo** — o recado de voz é transcrito pela API do Google
  Speech-to-Text e o texto entra na conversa, com a chave guardada criptografada como a do
  Claude. Desligado por padrão. Ver
  [Áudio: transcrição pelo Google Speech-to-Text](#áudio-transcrição-pelo-google-speech-to-text).

## Instalação

```bash
composer require rogga/claudinho
php artisan vendor:publish --tag=claudinho-config
php artisan vendor:publish --tag=claudinho-assets   # logo do header
php artisan migrate                                 # configurações e glossário
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

A engrenagem no header abre um modal em três abas, tudo sem deploy. Aparece só para quem
passa no gate `permissao_admin` (padrão `claudinho_admin`).

| Aba | O que tem |
|---|---|
| **Assistente** | O **contexto** (quem é o assistente nesta aplicação) e o **glossário de negócio**, regra a regra. É o que se mexe toda semana, e por isso abre nela. |
| **Modelo e chave** | O modelo em uso e a chave da API. Se define uma vez. |
| **Canais** | Botão flutuante, atendimento pela API, token do chamador, a documentação do endpoint, a **transcrição de áudio** e as **regras do canal**: prazos, palavras de confirmação, ações, instruções e hosts de mídia. |

Um aviso fica **fora das abas**, no alto do modal: *nenhuma chave configurada*. É a única
condição em que o chat está quebrado, e escondê-la atrás de uma aba seria deixar de avisar
justamente quem ainda não configurou nada.

### O glossário em tela

Cada regra é uma linha de `claudinho_glossario`, com assunto, autoria e data.

**A tela abre no índice de assuntos** — `OBRAS 3`, `DOCUMENTOS 12`, `PPC 10` — e as regras
aparecem ao escolher um. É como a pergunta nasce ("mostre o glossário de PPC") e é o que
mantém a tela usável depois da vigésima regra. Também é o que mantém o Livewire leve: com
as 46 regras do SGT abertas de uma vez, cada ação no componente carregaria ~200 KB de HTML;
pelo índice, são ~23 KB. A busca por texto atravessa todos os assuntos, para quem não sabe
em qual deles está o que procura.

O assunto é texto livre, com `datalist` das opções existentes e normalizado para maiúsculas
— `Obras`, `obras ` e `OBRAS` são o mesmo assunto, senão viram três grupos na tela e três
blocos no prompt, cada um com um terço do assunto. Regra sem assunto cai no grupo *Sem
assunto*, que é a fila do que falta classificar. Cadastrar em lote é o caso comum, então o
campo de assunto **não** é limpo entre uma regra e outra.

Cada regra pode ser **editada** (inclusive reclassificada em outro assunto), **desativada**
ou **removida**:

- **Desativar** tira a regra do prompt na hora e mantém o texto legível. É o que se faz
  quando uma regra piorou a resposta e ainda não se sabe qual é a redação certa — jogar
  fora obrigaria a reescrever do zero.
- **Remover** apaga mesmo. Sem histórico: quem precisa de rastro de alteração linha a
  linha deve manter o glossário no config, versionado no git.

Organizar o `config` em assuntos **antes** de importar poupa classificar dezenas de regras
uma a uma depois: o botão *Importar* leva o assunto junto.

**As regras salvam na hora**, sem passar pelo botão *Salvar* do rodapé — são registros,
não campos de formulário. O contexto, esse sim, vale no *Salvar*. Por isso o botão que
fecha o modal diz *Fechar* e não *Cancelar*: não há o que cancelar num glossário já
gravado.

A precedência com o config está descrita em [O glossário é o que faz a
diferença](#o-glossário-é-o-que-faz-a-diferença) — resumo: **a partir da primeira regra
cadastrada, o array do config para de valer inteiro.** A tela diz isso em voz alta,
mostrando quantas regras do arquivo ainda não foram importadas.

> Sem a migration da tabela `claudinho_glossario`, a aba mostra o que falta rodar e o chat
> segue pelo config. Nenhum formulário aparece para engolir o que for escrito nele.

Vale notar o que isso muda em custo: nada. O `systemPrompt()` é montado **uma vez por
resposta**, não por render, e o glossário vem junto da mesma leitura de banco que já
trazia modelo e chave. O que o glossário sempre custou são os **tokens** que ocupa no
system prompt a cada pergunta — idêntico vindo de arquivo ou de tabela. A única diferença
real é o cache de prompt da Anthropic: editar uma regra invalida o breakpoint, e a próxima
conversa paga o system prompt cheio de novo.

### Interruptores de canal

| Interruptor | O que faz | O que **não** faz |
|---|---|---|
| **Botão flutuante do chat** | Some com o botão do canto sem tirar o componente do layout. | Não afeta o chat que a aplicação colocou dentro de uma página — quem o pôs ali foi a aplicação, e não cabe a uma tela escondê-lo. |
| **Atendimento pela API** | Liga o endpoint. Desligado, ele responde 503 e nenhuma conversa externa é atendida. | Não dispensa o **resolvedor de usuário** — esse é uma classe, e por isso continua sendo código. |
| **Transcrever áudio recebido** | Liga a transcrição pela API do Google Speech-to-Text: o áudio que chega pelo canal vira texto, e é o texto que entra na conversa. Independe do interruptor de foto e vídeo. | Não funciona sem a **chave do Google**, gravada logo abaixo dele. Ligado sem chave, a tela avisa em vez de deixar o áudio falhar em silêncio. |

A tela também **gera o token** do chamador, guardado criptografado como a chave do Claude. Ou
seja: ligar a API não exige mexer no `.env`. As chaves `api.habilitado` e `api.token` do
config viraram apenas o *padrão* — o valor gravado em tela vence, como no resto do pacote.

Duas notas sobre como isso funciona por baixo:

- **As rotas são registradas sempre**, e quem liga ou desliga é o middleware. Decidir isso no
  boot exigiria ler o banco em toda requisição da aplicação, inclusive nas que nunca falam com
  o Claudinho — e é o banco que guarda o interruptor. A rota existir desligada não é brecha: o
  middleware recusa antes de qualquer processamento, e **depois** da checagem de token, então
  quem não se autenticou recebe 401 nos dois casos e não descobre se há endpoint ali.
- **O token aparece uma vez só**, na resposta em que é gerado. Diferente da chave do Claude,
  este segredo precisa ser lido — quem opera tem de copiá-lo para o gateway. Depois disso só
  resta a máscara; perdeu, gera outro (o anterior para de valer na hora).

> Sem trava de `.env`, quem passa no gate `permissao_admin` pode abrir um endpoint HTTP com
> acesso aos dados. Vale conferir quem tem essa permissão.

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
deve ir para o cache store, que costuma ser menos protegido que o banco. São consultas
leves **por resposta** — não por render de tela: uma que traz de uma vez tudo o que está em
`claudinho_configuracoes` (chave, modelo, contexto, interruptores) e outra do glossário.
Sem migration rodada, sem driver de banco ou sem conexão, tudo cai no `config`/`.env` em
silêncio, e o chat continua funcionando.

O select de modelos vem de `config('claudinho.modelos')` — é só a lista da UI, edite à
vontade. Um modelo gravado que saiu da lista continua selecionável, para o select não trocar
o modelo de produção sozinho.

Raciocínio adaptativo e `effort` só existem da geração 4.6 em diante. Em Haiku 4.5 e
anteriores os dois campos nem entram no payload — a API recusa a requisição inteira quando
aparecem —, então o modelo responde sem raciocinar antes, mais rápido e mais barato, e erra
mais na escolha da ferramenta. Se a sua aplicação monta requisição própria à API (uma análise
de arquivo em fila, por exemplo), pergunte pela mesma regra em vez de repetir a lista:

```php
if (\Rogga\Claudinho\Claude::suportaRaciocinio($modelo)) {
    $payload['thinking'] = ['type' => 'adaptive'];
    $payload['output_config'] = ['effort' => config('claudinho.effort')];
}
```

### Documentação da API na própria tela

A seção *Documentação da API* é embutida em vez de link, porque só ela sabe a URL **deste**
ambiente. Além do contrato e de um `curl` pronto com o endereço real, ela funciona como
diagnóstico, marcando o que ainda falta: atendimento ligado, token do chamador, resolvedor de
usuário e migration da tabela de conversas. Três dos quatro se resolvem ali mesmo.

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

## Janela "Sobre"

O ⓘ no header abre uma janela que serve a dois leitores ao mesmo tempo:

- **Como o assistente trabalha**, em quatro linhas escritas para quem usa o chat: que ele
  consulta pelas ferramentas que a aplicação registrou, que só vê o que aquele usuário vê,
  que alteração de dados espera confirmação — e que **pode errar, inclusive ao somar**. O
  último item é o único que muda o que a pessoa faz com um número que veio dali, então está
  lá com destaque em vez de nota de pé.
- **As versões** de Claudinho, Laravel, Livewire e PHP, mais o modelo em uso. É o que se pede
  a quem abre um chamado; sem isso a primeira resposta do suporte é sempre *"está
  atualizado?"*. Versão que o Composer não sabe informar (repositório clonado direto) sai da
  lista, em vez de aparecer como *desconhecida*.

O modelo vem do mesmo lugar que o `Claude` lê — o gravado em tela, com o config como padrão.
Ler do config direto faria a janela mostrar o do `.env` enquanto as respostas vêm de outro.

No rodapé fica a marca da **Rôgga Empreendimentos**, embutida em `data:` URI (~4 KB no HTML).
Não é arquivo em `public/vendor/claudinho` como o logo do header de propósito: esta janela é a
que não pode depender de `vendor:publish`, e quem usa `'logo' => false` nunca publicou asset
nenhum. Mesma decisão do Claudinho animado, que é SVG inline pelo mesmo motivo.

**Sem gate, de propósito.** Nenhum segredo passa por ali (chave e token ficam na engrenagem,
que tem gate próprio), e a informação serve justamente a quem **não** administra o pacote.
Para um header mais enxuto, `'sobre' => false` no config tira o botão — e a janela junto.

## Histórico de conversas

O **relógio** no header abre um painel **ao lado da caixa de conversa** — não por cima dela.
O painel cresce com o que está aberto: só a lista ocupa 16 rem, e a conversa escolhida abre
numa **segunda coluna** de 22 rem ao lado dela.

```
┌─────────┬────────────┬─────────────────────┐
│  LISTA  │  CONVERSA  │        CHAT         │
│ 47999.. │ ola        │ Digite sua          │
│ 47991.. │ Olá! Eu... │ pergunta...         │
│ ▲ rola  │ ▲ rola     │                     │
└─────────┴────────────┴─────────────────────┘
  16rem      22rem        o que sobrar
```

A lista **continua à vista** enquanto se lê uma conversa, que é o que permite pular de uma
para outra sem voltar — a linha aberta fica marcada.

**Quem encolhe é o chat.** As colunas do histórico têm largura fixa e não cedem: elas já são
o mínimo para ler um número e uma conversa. O card tem `max-w-4xl` de teto justamente porque
sobra largura ali, e é de lá que o espaço sai. No modo flutuante o painel tem largura `auto`
com teto de viewport, para as colunas nunca crescerem para fora da tela.

Os pontos de quebra seguem a largura disponível:

| Largura | O que aparece |
|---|---|
| `< lg` (1024) | O histórico **cobre** o chat. Lado a lado não deixaria largura utilizável para nenhum dos dois. |
| `lg` – `xl` | Histórico ao lado do chat, **uma coluna por vez** — a conversa toma o lugar da lista, com o caminho de volta no cabeçalho. |
| `≥ xl` (1280) | **As duas colunas** do histórico mais o chat. |

**A lista rola dentro dela mesma** — no card inline o histórico tem altura própria (34 rem,
teto de 75 vh) em vez de acompanhar o card, senão uma lista comprida esticaria o chat até
sobrar espaço vazio embaixo do campo de pergunta.

A lista traz os **identificadores ordenados pela última alteração** (`updated_at`), e cada
linha resume a conversa: o canal, o nome de quem estava do outro lado, há quanto tempo, se
está em andamento ou já encerrou por inatividade, quantas falas houve e a última delas.
Clicar abre **a conversa inteira gravada no estado** — as mesmas bolhas do chat, com os
mesmos rótulos de consulta e de alteração.

**O que ele mostra são as conversas dos canais externos** (WhatsApp e afins). São as únicas
com estado no banco: a conversa desta tela vive no componente Livewire e morre com a sessão.
É por isso que a chave da lista é o identificador do canal e não um título — do outro lado
não há quem dê nome à conversa.

### O gate é o de administração, e o padrão é esse por um motivo

Isto expõe a conversa de **outras pessoas**, com os dados que elas consultaram. Trate como
tela de auditoria:

```php
'historico' => [
    'habilitado' => true,
    'permissao' => env('CLAUDINHO_PERMISSAO_HISTORICO'),
    'limite' => 30,
    'varredura' => 200,
],
```

`permissao` vazio **não** libera geral: cai em `permissao_admin`, que é o mais restritivo dos
dois. Um config publicado antes da 2.0 não tem a chave nenhuma, e precisa herdar o lado
seguro — atualizar o pacote não pode abrir a conversa dos outros para quem só usa o chat.
Defina `permissao` só para dar o histórico a quem **não** administra o resto: um supervisor
de atendimento, por exemplo.

`limite` é quantas conversas a lista traz. O estado inteiro de cada uma vem junto, porque é
dele que saem a prévia e a contagem — subir muito é trazer alguns MB de JSON a cada abertura.
Para achar uma conversa antiga, a busca serve melhor que uma lista comprida.

### Buscar por número ou por nome custa coisas diferentes

O campo aceita os dois, e o caminho de cada um é outro porque eles moram em lugares
diferentes:

- **Número** está na tabela. O `LIKE` resolve e **alcança todas** as conversas, sem teto.
  A comparação é pelos dígitos, não pelo texto digitado: `(47) 99911-0130` acha
  `47999110130`, que é como o gateway grava — quem procura cola do jeito que tem em mãos.
- **Nome** não está em lugar nenhum do pacote. Quem sabe é o resolver da aplicação, **uma
  chamada por conversa**. Por isso a busca por nome varre as `varredura` mais recentes
  (200 por padrão) e filtra fora do SQL — e a tela **avisa** quando bateu nesse teto, porque
  um cap silencioso lê como *"essa pessoa não existe"*, que é conclusão bem diferente de
  *"não achei entre as mais recentes"*.

Termo com letra é sempre busca por nome, mesmo trazendo dígito: ninguém digita `joão 47`
procurando um telefone.

Se a sua varredura precisa ser grande, lembre que cada linha varrida é uma chamada ao seu
resolver — vale conferir se ele tem cache antes de subir o número.

### Quem dá nome a quem

O nome vem do **mesmo resolver que o endpoint usa** (`api.resolvedor`): é o único que sabe
mapear telefone para usuário nesta aplicação. O provedor de autenticação padrão não serve
sozinho — quem atende pelo WhatsApp costuma viver em outro guard que não o `web`.

O id devolvido é conferido contra o gravado na conversa. Resolver que passou a apontar para
outra pessoa devolveria o nome de quem **não** teve aquela conversa, e num histórico que
existe para auditar isso é pior do que não dar nome nenhum: aparece `usuário #681`.

O nome vai para a tela **em caixa alta**. Uniformizar é o ponto: o cadastro de origem grava
parte dos nomes gritando e parte não, e misturados numa coluna estreita os em caixa alta leem
como se estivessem marcados. Assim todos pesam igual, e o nome fica distinto do número logo
acima. Espaço repetido do cadastro é colapsado (`MAXWELL  F.` sairia com buraco no meio), e o
`usuário #681` do resolver que não respondeu passa intacto — aquilo não é nome, é a ausência
de um. A busca continua casando dos dois jeitos.

### A pendência aparece, o botão não

Conversa parada esperando confirmação mostra a frase da pendência como aviso — e **não** o
par confirmar/cancelar do chat. Quem decide está do outro lado, no aplicativo de mensagens; um
botão aqui executaria a alteração em nome dele.

### O que o histórico não alcança

A faxina do `claudinho:limpar-conversas` apaga conversas vencidas, e o que ela apagou não
está mais aqui. Se o histórico tem valor de registro para a sua operação, revise o `--dias`
do agendamento — ou tire o comando do schedule.

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

## Chat flutuante

O mesmo componente, com um parâmetro, vira um botão fixo num canto que abre o chat em
painel. Ponha uma vez no layout global, antes do `</body>`:

```blade
{{-- resources/views/layouts/app.blade.php --}}
@livewire('claudinho.chat', ['flutuante' => true])
```

Ligar o modo é parâmetro e não config porque é decisão de **onde** o componente foi
colocado: a mesma aplicação pode ter a tela dedicada (card na página) e o botão no layout.
Se você puser os dois na mesma página, serão duas conversas independentes — é o esperado,
mas provavelmente não é o que você quer.

O gate `claudinho.permissao` continua valendo. Num layout global, quem não tem a permissão
tomaria 403 em **toda** página, então proteja o include:

```blade
@can('use_assistente')
    @livewire('claudinho.chat', ['flutuante' => true])
@endcan
```

A aparência sai do config:

```php
'flutuante' => [
    'posicao' => 'direita',            // ou 'esquerda' — escolha o lado livre dos toasts
    'rotulo' => 'Abrir o assistente',  // nome acessível e tooltip do botão
    'aberto' => false,                 // já nascer aberto; deixe false no layout global
],
```

O que o modo flutuante faz por conta própria:

- **Fechar não descarta a conversa.** O componente segue montado e o painel só é escondido;
  reabrir devolve tudo onde estava, inclusive uma ação esperando confirmação.
- **Responde com o painel fechado.** Você pergunta, fecha, continua trabalhando — quando a
  resposta chega (ou o loop para pedindo confirmação, ou dá erro), um ponto vermelho acende
  no botão. Abrir apaga o ponto.
- **Tela inteira no mobile.** Um painel de 26rem em 360px de largura não serve para
  conversar; do breakpoint `sm:` para cima ele fica ancorado no canto.
- **Fecha com `Esc`**, e o foco vai para o campo de pergunta ao abrir. Não fecha ao clicar
  fora, de propósito: num chat, clicar na página para reler algo não é intenção de sair.

A aplicação pode abrir o chat de qualquer lugar — um item de menu, um botão de "precisa de
ajuda?" — despachando um evento na window:

```blade
<button x-on:click="$dispatch('claudinho-abrir')">Falar com o assistente</button>
```

## Endpoint para canais externos (WhatsApp e afins)

O mesmo assistente por HTTP, para um gateway de WhatsApp (ou n8n, Telegram, o que for)
conversar com ele. **Desligado por padrão** — rota que passa a existir só por ter atualizado
o pacote seria surpresa de segurança.

```
POST /claudinho/conversa            {canal, identificador, mensagem}
POST /claudinho/conversa/reiniciar  {canal, identificador}
Authorization: Bearer <CLAUDINHO_API_TOKEN>
```

### Duas identidades, e não confunda

| Quem | Como se identifica | O que garante |
|---|---|---|
| O **chamador** (o gateway) | token no header | que a requisição vem do seu servidor |
| O **usuário** da conversa | resolver da aplicação | de quem é a permissão sobre os dados |

Um token vazado dá acesso ao endpoint, **não** aos dados de todos: o escopo continua sendo o
do usuário que o resolver devolver para aquele número.

### O resolver é a peça central

Todo o modelo de permissão do pacote sai de `Auth::user()` — as ferramentas checam gates, os
Global Scopes filtram por obra, o system prompt usa o nome. O endpoint autentica como o
usuário que você apontar e, dali em diante, **tudo funciona igual ao chat em tela**. Não
existe caminho paralelo de permissão, de propósito.

Só a sua aplicação sabe mapear telefone para usuário:

```php
namespace App\Claudinho;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Rogga\Claudinho\Contracts\ResolvedorDeUsuario;

class ResolvedorPorTelefone implements ResolvedorDeUsuario
{
    public function resolver(string $canal, string $identificador): ?Authenticatable
    {
        // Devolver null é a resposta certa para número desconhecido: o endpoint
        // responde 403 e nada é consultado. NUNCA devolva um usuário "genérico" de
        // fallback — o filtro por obra deixaria de significar coisa alguma.
        return User::query()
            ->where('celular', preg_replace('/\D/', '', $identificador))
            ->where('ativo', true)
            ->first();
    }
}
```

Class-string no config e não closure, porque closure não sobrevive a `config:cache`:

```php
// config/claudinho.php
'api' => [
    'resolvedor' => App\Claudinho\ResolvedorPorTelefone::class,
],
```

```bash
php artisan migrate   # tabela claudinho_conversas
```

O resto — ligar o atendimento e gerar o token — sai pela engrenagem do chat, sem `.env` e sem
deploy. Ver [Configurações em tela](#configurações-em-tela).

As **regras do canal** também: prazo de inatividade, se as ações valem por aqui, as palavras que
confirmam uma alteração e o prazo delas, as instruções deste canal e os hosts de onde se aceita
baixar mídia. Ficam na mesma aba, em *Regras do canal externo*, e o que for gravado ali vence o
arquivo — **campo vazio volta ao config**, como na chave da API e no contexto. É o que permite
responder "por que o 'ok' dele não confirmou nada?" e corrigir na hora, sem deploy.

Continuam só no arquivo, porque não são decisão de quem opera: o resolvedor de usuário e o
destino da mídia (são classes), os tipos de mídia aceitos e os tetos de download.

> **Se você publicou o `config/claudinho.php` antes desta versão**, ele não tem o bloco `api`.
> O `mergeConfigFrom` do Laravel é **raso**: acrescentar só `'api' => ['resolvedor' => ...]`
> substitui o bloco inteiro do pacote e você perde `prefixo`, `throttle`,
> `palavras_confirmacao` e o resto — silenciosamente, todos como `null`. Copie o bloco `api`
> **completo** do config do pacote, ou republique com
> `vendor:publish --tag=claudinho-config --force` (isto sobrescreve suas edições de `contexto`,
> `glossario` e `ferramentas`, então guarde uma cópia antes).

### A resposta

```json
{
  "resposta": "São 12 obras ativas. A maior é a Azaleia.",
  "estado": "concluida",
  "confirmacao": null,
  "expira_em": "2026-08-03T20:29:47+00:00"
}
```

`resposta` já vem pronta para reenviar ao canal — a maioria das integrações só repassa isso.
`estado` é `concluida`, `aguardando_confirmacao` ou `erro` (HTTP 502 quando a API do Claude
falha; a conversa continua utilizável).

### Ações: confirmação por texto

Pelo WhatsApp não existe o card de confirmação, então o endpoint pausa e pede por escrito:

```
> cancela o pedido 4821
Cancelar o pedido 4821 do cliente Acme (R$ 12.400)?

Responda apenas SIM para confirmar. Qualquer outra resposta cancela.
```

Três regras conservadoras, e as três são deliberadas:

1. **Só aprovação exata aprova.** `sim` aprova; `sim, pode cancelar` não. Casar por conteúdo
   faria `não, não confirmo` conter `confirmo` e autorizar o oposto do que a pessoa escreveu.
   A resposta diz literalmente o que digitar, então a exigência é justa. A lista está em
   `api.palavras_confirmacao`, e a aba *Canais* da engrenagem grava por cima.
2. **Qualquer outra coisa cancela**, em vez de deixar pendente. Pendência viva esperaria um
   `sim` que pode chegar em outro assunto, meia hora depois.
3. **Prazo próprio**, mais curto que o da conversa (`api.minutos_confirmacao`, padrão 5, também
   editável em tela). E
   mais de uma alteração pendente na mesma rodada cancela todas: uma frase de texto não
   distingue "sim" para qual delas.

Para deixar o canal **somente-leitura** sem desregistrar as ações (que continuam valendo na
tela), `'acoes' => false` — ou o interruptor *Alterações de dados neste canal*, na engrenagem.
Aí a ferramenta de escrita nem é declarada ao modelo, e é recusada também na execução, caso ele
insista no nome.

### Formatação e continuidade

`api.instrucoes` entra no fim do system prompt só neste canal, e por padrão desfaz a regra de
tabela markdown — que o chat renderiza bem e o WhatsApp não. Editável em tela, que é onde se
ajusta o tom depois de ler as primeiras conversas de verdade.

A conversa é contínua por `canal` + `identificador` e recomeça após
`api.minutos_inatividade` (padrão 30) de silêncio: histórico de horas atrás confunde o modelo
mais do que ajuda, e encarece cada resposta. Agende a faxina das vencidas:

```php
Schedule::command('claudinho:limpar-conversas')->daily();
```

### Foto e vídeo

Desligado por padrão. Ligar significa o servidor passar a baixar arquivo de endereço que vem
na mensagem e a pagar uma chamada de visão por imagem — é decisão de quem instala.

O gateway não manda o arquivo: manda `{"type":"image/jpeg","uri":"https://..."}` no campo
`mensagem`, com uma URI assinada que costuma vencer em meia hora. Repassado assim, o modelo
recebe um endereço que não sabe abrir e responde que não entendeu, enquanto o link vence sem
ninguém baixar nada.

Ligado, o pacote baixa na hora, descobre o tipo pelos bytes, descreve a imagem e troca o JSON
pela descrição:

```
[Foto recebida nesta conversa. O que aparece nela: Canto de parede próximo ao piso, com
manchas escuras de mofo na quina entre as duas paredes (...)]
```

A descrição entra como TEXTO, e é de propósito: mandar a imagem em toda requisição junto do
histórico custaria os tokens dela em cada volta do loop de ferramenta. Descrever uma vez sai
mais barato e sobrevive ao corte do histórico. Vídeo a API não lê — é entregue igual, e o
assistente pede a descrição em texto.

```php
'midias' => [
    'habilitado' => true,
    'destino' => App\Suporte\AnexosDoChamado::class,   // opcional
    'hosts' => ['blipmediastore.blob.core.windows.net'],
],
```

Os **hosts** também se cadastram na aba *Canais* da engrenagem, e o que estiver gravado lá vence
esta lista — o gateway troca de domínio sem avisar, e quem descobre é quem está atendendo, pelo
log de mídia recusada. Pode colar a URI assinada inteira: fica só o host, que é o que o pacote
compara. O resto do bloco não sai do arquivo: ligar a funcionalidade, o destino e os tipos são
decisão de código.

**O destino é seu.** O pacote não sabe o que a foto significa na sua aplicação — num sistema
de atendimento vira anexo de chamado, num de vistoria é evidência do item, num terceiro não é
nada. Guardar é decisão de domínio:

```php
use Rogga\Claudinho\Contracts\DestinoDeMidia;
use Rogga\Claudinho\Midia\MidiaRecebida;

class AnexosDoChamado implements DestinoDeMidia
{
    public function guardar(Authenticatable $usuario, MidiaRecebida $midia): ?string
    {
        Storage::put("anexos/{$usuario->id}/{$midia->nome}", $midia->conteudo);

        // A frase que só você sabe dizer, acrescentada à anotação.
        return 'Ele vai como anexo do chamado que você abrir.';
    }
}
```

Sem `destino` a imagem ainda é descrita e a descrição entra na conversa: o modelo passa a
enxergar a foto mesmo numa aplicação que não tem o que fazer com o arquivo. O que se perde é
o arquivo.

**`hosts` é a barreira de segurança, e ela importa.** A URI vem DENTRO da mensagem: qualquer
um pode digitar `{"type":"image/jpeg","uri":"https://169.254.169.254/..."}` no WhatsApp e o
gateway repassa como texto. Preenchida, a lista é a única barreira e não há consulta DNS —
é o modo de produção, com o host do gateway. **Vazia, aceita qualquer endereço PÚBLICO**: a
rede interna segue barrada (IP direto ou domínio que resolva para lá) e redirecionamento não
é seguido, mas é postura de desenvolvimento.

O resto do bloco — `tipos`, `max_bytes`, `max_por_mensagem`, `timeout`,
`instrucao_da_descricao` — está comentado no config publicado.

### Áudio: transcrição pelo Google Speech-to-Text

Desligado por padrão, e ligar é decisão de quem instala — não do pacote. Passa a haver uma
chamada paga a um **serviço de terceiro, fora da Anthropic**, com o áudio de quem está
conversando dentro dela.

O áudio chega como a foto chega: `{"type":"audio/ogg","uri":"https://..."}` no campo
`mensagem`. A diferença é que o Claude **lê, mas não ouve** — quem transcreve é a API do
Google, e é o texto que entra na conversa:

```
[Áudio recebido nesta conversa. A transcrição do que foi dito nele: "preciso remarcar a
vistoria de amanhã". Trate isso como a mensagem de quem enviou, e confirme com ela o que a
transcrição deixou dúbio.]
```

Anunciado como transcrição, e não como fala: o reconhecimento erra, e o modelo precisa saber
disso para confirmar o que ficou ambíguo em vez de agir sobre o palpite.

Liga-se na aba *Canais* da engrenagem, com dois campos: o interruptor e a chave. No config,
que é só o padrão para quem prefere ambiente:

```php
'transcricao' => [
    'habilitado' => env('GOOGLE_SPEECH_HABILITADO', false),
    'chave' => env('GOOGLE_SPEECH_API_KEY'),
    'idioma' => env('GOOGLE_SPEECH_IDIOMA', 'pt-BR'),
    'timeout' => 30,
],
```

A chave é uma **credencial de projeto do Google Cloud** com a Speech-to-Text liberada (as que
começam com `AIza`), não de pessoa: vale para quem a tiver em mãos, então restrinja por IP e
por API no console. Gravada em tela, ela vai criptografada com a `APP_KEY`, igual à do Claude,
e o campo é só de escrita — o que a tela devolve é máscara. Ela viaja na query string, que é
como a API do Google aceita chave de projeto, e por isso o pacote a apaga das mensagens de
erro antes de registrá-las: sem isso o log guardaria a credencial em texto puro a cada falha.

`idioma` fica só no arquivo, de propósito: quem fala com o assistente é o mesmo público da
aplicação, e trocar isso é decisão de instalação, não de operação.

**Este interruptor é independente do de foto e vídeo.** Quem só quer transcrever recado de voz
não precisa ligar `api.midias.habilitado`, e o áudio não sai da lista `api.midias.tipos` —
entra com a transcrição ligada. Exigir a edição daquela lista deixaria o interruptor sem
efeito em quem publicou o config antes de áudio existir no pacote.

**Até um minuto por áudio.** É o teto do reconhecimento síncrono da API; o assíncrono exigiria
o arquivo num bucket do Cloud Storage, ou seja, obrigaria a aplicação a ter um — e quem está
do outro lado está esperando a resposta chegar no aplicativo de mensagens. Formatos aceitos:
Opus (a gravação de voz do WhatsApp), MP3, AMR, WAV, FLAC e WebM. AAC e M4A ficam de fora
porque a API do Google não os decodifica — aceitá-los seria baixar o arquivo para recusá-lo
depois.

Cada falha vira uma resposta diferente, e a diferença é o que a pessoa faz em seguida:

| O que houve | O que o assistente diz |
|---|---|
| Transcrição desligada | Que não escuta áudio e pede o recado por escrito — **sem baixar o arquivo**. |
| Áudio acima de um minuto | Que só ouve até um minuto, e pede um mais curto. Diz explicitamente para **não** reenviar. |
| Formato que a API não lê | Pede o recado por escrito, sem pedir reenvio. |
| Nada compreensível no áudio | Pede que repita falando mais perto. |
| A chamada falhou | Pede o reenvio — aqui, sim, tentar de novo pode resolver. |

O áudio chega ao `DestinoDeMidia` da aplicação **já com o texto**, em `$midia->transcricao`,
para não haver uma segunda transcrição só para guardar os dois lado a lado.

Em código, `Transcricao::habilitada()` é o interruptor e `Transcricao::ativa()` é o resultado —
ligado sem chave não é "meio ligado", é desligado com aparência de ligado:

```php
use Rogga\Claudinho\Transcricao;

if (Transcricao::ativa()) {
    // Transcricao::chave() já respeita a precedência tela > config/.env.
}
```

Nunca leia `config('claudinho.transcricao.chave')` direto: a chave gravada em tela seria
ignorada sem ninguém perceber. `Transcricao` existe para ser o único lugar que responde o que
está valendo agora — o mesmo papel que o `Canal` faz para as regras do canal.

### Cuidados de integração

- **Tempo de resposta.** Uma pergunta com consultas pode levar dezenas de segundos. Se o seu
  gateway tem timeout curto no webhook, chame o endpoint de dentro de um job e mande a
  resposta pela API dele depois.
- **Gráficos não vão.** `gerar_grafico` desenha SVG na conversa, o que não existe aqui. Se o
  canal externo é o principal, considere `grafico.habilitado => false`.
- **O histórico fica em claro** na tabela, não criptografado: ele só contém dado que aquele
  usuário já podia ver, e vive no mesmo banco de onde saiu.

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

Schema o modelo descobre sozinho; **semântica não**. O glossário são as regras que não
estão em lugar nenhum do código, **agrupadas por assunto**:

```php
'glossario' => [
    'OBRAS' => [
        'EMPREENDIMENTO e OBRA são a mesma coisa.',
        'obras.divisao é Prime ou Easy; obras.linha_negocio é a linha de negócio.',
    ],
    'FUNCIONÁRIOS' => [
        'funcionarios.is_obra guarda o id da obra em que a pessoa está trabalhando
         naquele momento; é foto do instante, não lotação do período.',
        'A tabela funcionario_obras é pouco populada; não use como fonte de headcount.',
    ],
],
```

A lista simples de antes dos assuntos continua valendo — as regras entram no bloco *sem
assunto*, e nada quebra.

O assunto **não é organização de tela**: ele vira o título do bloco no system prompt. Numa
lista corrida de dezenas de itens, a regra de PPC e a de documentos chegam ao modelo com o
mesmo peso e sem vizinhança, e ele perde a pista de que *status* quer dizer coisas
diferentes em cada assunto. O título é o contexto que a regra sozinha não carrega.

O glossário crescer **é** o mecanismo de aprendizado do assistente. O modelo não aprende
entre conversas — cada conversa começa do zero, com o que estiver ali.

E é por isso que o glossário **não mora só no config**: quem sabe a regra de negócio é
quem opera o sistema, não quem faz deploy. A aba *Assistente* da engrenagem cadastra,
edita, desativa e remove regra por regra, com autoria e data — ver
[O glossário em tela](#o-glossário-em-tela).

O array do config é o glossário de **partida**: vale enquanto não houver nenhuma regra
cadastrada, e a tela tem um botão que importa tudo o que está nele. Quem prefere manter
as regras no git simplesmente não cadastra nada em tela e continua como antes.

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

### Fakeando a API nos testes da sua aplicação

A chamada à API **não** passa pelo facade `Http`, e sim por um cliente próprio do pacote
(`Rogga\Claudinho\Http\ClienteHttp`). O motivo está na seção seguinte. Consequência
prática: `Http::fake()` não intercepta o Claudinho — o fake tem que ir no cliente dele.

```php
use Illuminate\Support\Facades\Http;
use Rogga\Claudinho\Http\ClienteHttp;

app(ClienteHttp::class)->fake([
    'api.anthropic.com/v1/messages' => Http::response(
        "event: message_stop\ndata: {\"type\":\"message_stop\"}\n"
    ),
]);
```

É a mesma `Factory` do HTTP client do Laravel, então `fake()`, `sequence()`,
`assertSent()` e `assertSentCount()` funcionam igual — só mudam de instância. O corpo é
SSE, porque o chat consome a resposta em streaming.

### Por que a chamada não usa o facade `Http`

A resposta do stream chega em pedaços e é lida linha por linha: o corpo não se rebobina.
Quem escuta os eventos do HTTP client do Laravel — **Clockwork**, Telescope, Debugbar —
rebobina o corpo para registrar a resposta, e a requisição morre em `Stream is not
seekable`. Com o Clockwork instalado em `require-dev` e ativo em local, era a primeira
pergunta do chat que morria. Listener que lê sem rebobinar é pior: não estoura, consome o
stream que o chat ainda não leu e a resposta chega vazia.

Não é bug de quem escuta: gravar uma resposta que só pode ser lida uma vez é impossível.
A `Factory` do pacote não tem event dispatcher, então não dispara `RequestSending` nem
`ResponseReceived` e a chamada não passa por listener nenhum. O preço é ela não aparecer
no painel de HTTP dessas ferramentas. O HTTP client da sua aplicação continua intacto,
com os eventos dele.

## Licença

MIT.
