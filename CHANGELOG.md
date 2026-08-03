# Changelog

## v1.2.0

### Adicionado

- **Chat flutuante.** O mesmo componente, com `['flutuante' => true]`, vira um botão fixo num
  canto que abre o chat em painel — para pôr uma vez no layout global em vez de ocupar uma
  tela. Parâmetro e não config porque é decisão de *onde* o componente foi colocado: a mesma
  aplicação pode ter a tela dedicada e o botão no layout.
  - **Fechar não descarta a conversa**: o componente segue montado e o painel só é escondido.
    Reabrir devolve tudo onde estava, inclusive ação esperando confirmação.
  - **Responde com o painel fechado.** Resposta pronta, loop parado pedindo confirmação ou
    erro acendem um ponto no botão (evento `claudinho-resposta-pronta`, novo). Sem isso a
    pergunta ficaria sem retorno visível para quem fecha e continua trabalhando.
  - Tela inteira no mobile, ancorado no canto do `sm:` para cima; `Esc` fecha; o foco vai
    para o campo ao abrir. Não fecha ao clicar fora, de propósito — num chat, clicar na
    página para reler algo não é intenção de sair.
  - `flutuante.posicao`, `flutuante.rotulo` e `flutuante.aberto` no config, só aparência.
  - A aplicação abre o chat de onde quiser com `$dispatch('claudinho-abrir')`.
  - O botão traz **a marca do Claudinho**, não um ícone de bolha genérico. Em SVG inline
    para não depender do publish dos PNGs — imagem quebrada no elemento mais visível do
    modo seria pior que ícone genérico. A superfície do botão é neutra (branco/gray-900 com
    anel sky) em vez do `sky-600` do botão de enviar: a marca tem três cores próprias e foi
    desenhada para fundo claro ou creme, e sobre azul saturado só a cabeça leria bem.
  - E ela **pisca**: um olho só, a cada 8s, com keyframes próprio (`claudinho-piscada`).
    O ciclo é longo e a piscada curta de propósito — é o intervalo parado que separa
    "tem alguém aí" de ruído permanente num canto fixo da tela. Some com
    `prefers-reduced-motion`.
- Componente `x-claudinho::marca`: a geometria da marca, que antes vivia dentro do
  `pensando`, agora existe num lugar só. O `pensando` passou a ser essa marca mais as
  animações, via `animado`; o botão flutuante usa `piscando`. As duas flags são separadas
  porque os `<style>` são globais — misturá-las faria a marca do botão balançar o cabelo
  junto sempre que houvesse um "pensando" em cena.
  - O gate `claudinho.permissao` **não** foi relaxado: num layout global o include precisa
    vir dentro de `@can`, senão quem não tem a permissão toma 403 em toda página. Renderizar
    nada em silêncio esconderia o assistente de todos ao menor erro no nome da permissão.
- O card virou o partial `livewire/partials/card.blade.php`, compartilhado pelos dois modos.
  Quem publicou as views com `vendor:publish --tag=claudinho-views` precisa republicar.

### Corrigido

- O modal de configurações saiu de dentro do card e passou a ficar na raiz do componente. Ele
  é `fixed`, e ancestral com `transform` vira o bloco contêiner de descendentes `fixed` — o
  painel flutuante tem `transform` durante a transição de abertura, o que deslocaria o modal.
  No modo inline nada muda de aparência.

## v1.1.1

### Corrigido

- **Salvar na tela de configurações estourava `DecryptException` depois de rotacionar a
  `APP_KEY`** (ou ao apontar a aplicação para um banco populado por outro ambiente). A coluna
  `valor` tem cast `encrypted`, e o `updateOrCreate()` de `Configuracao::definir()` chamava
  `save()` → `isDirty()` → `originalIsEquivalent()`, que **decifra o valor que está no banco**
  para comparar com o novo. Linha cifrada com outra chave derrubava a requisição ali, antes
  de qualquer escrita.

  O grave não era o erro em si, era o beco sem saída: `todas()` engole o `DecryptException`
  na leitura justamente para o usuário poder regravar pela tela — e regravar era exatamente
  o que não funcionava. A assimetria entre leitura tolerante e escrita intolerante deixava a
  aplicação presa: o chat seguia no `.env`, mas nenhuma gravação passava, nem a da chave nova
  que consertaria o estado.

  `definir()` passou a montar o registro com um select sem a coluna `valor`. Sem o original
  carregado, o Eloquent conta o atributo como sujo sem comparar nada — não há o que decifrar.
  A linha é preservada (mesmo `id`), então nada de `delete`+`insert`, que perderia o registro
  e correria risco no unique de `chave`.

  O framework tem um atalho para este caso em `HasAttributes::originalIsEquivalent()`, mas só
  quando `APP_PREVIOUS_KEYS` está configurado — o que não cobre chave antiga perdida, que é o
  cenário real de quem cai aqui.

## v1.1.0

### Adicionado

- **Ações: ferramenta que altera dados, com confirmação do usuário.** Antes, todo o pipeline
  já executava escrita sem reclamar (nada no caminho impunha somente-leitura), mas três
  coisas no pacote trabalhavam contra: o system prompt afirmava que as ferramentas eram
  somente-leitura, o rótulo na conversa dizia "Consultou" para qualquer chamada, e o loop
  executava tudo na hora — não existia ponto onde a aplicação pudesse pedir confirmação, já
  que ela só é chamada em `executar()`, quando a decisão já foi tomada.
  - `Rogga\Claudinho\Contracts\Acao` e `Rogga\Claudinho\AcaoBase`. Interface separada em vez
    de método novo na `Ferramenta`: não quebra quem já implementa o contrato, e o chat decide
    pausar por `instanceof`, então não há ação que escape da confirmação por esquecimento de
    sobrescrever um método.
  - **O loop pausa antes de executar.** No `tool_use` de uma ação, a conversa mostra um card
    com a `confirmacao()` da ferramenta e os botões Confirmar / Cancelar, e o campo de
    pergunta é bloqueado. Confirmado, a ação executa com a permissão revalidada e o loop
    continua da iteração em que parou; cancelado, o modelo recebe `{"recusada": true}` e
    volta a falar. Várias ações numa volta ganham um card cada, e o loop só segue quando a
    última for decidida — a API exige que todo `tool_use` seja respondido de uma vez.
  - **Gate obrigatório.** `permissao` em `null` nega numa ação, ao contrário de uma consulta,
    onde libera. Esquecer o gate não vira escrita aberta a qualquer usuário autenticado.
  - **A descrição enviada ao modelo é marcada** com "ATENÇÃO: esta ferramenta ALTERA DADOS",
    no registro e não em cada classe, para valer também para quem implementa `Acao` direto.
  - **O system prompt deixa de mentir**: só afirma "somente-leitura" quando nenhuma ação está
    exposta ao usuário atual, e ganha um bloco que instrui o modelo a chamar a ferramenta em
    vez de pedir permissão por texto — pedir duas vezes é o que treina o usuário a clicar sem
    ler.
  - **O rótulo na conversa distingue** "Alterou dados", "Alteração não autorizada pelo
    usuário", "Alteração falhou" e "Aguardando confirmação", com cor própria, em vez de tudo
    aparecer como "Consultou".
  - Para alteração pequena e reversível, `protected bool $confirmar = false;` executa direto.
    O rótulo e o aviso na descrição continuam.

### Corrigido

- **Falha no meio do loop de ferramentas inutilizava a conversa.** O bloco `tool_use` já
  estava gravado quando a execução estourava, mas o `tool_result` não — e a API rejeita
  `tool_use` sem par, então toda mensagem seguinte falhava até o usuário clicar em "Limpar
  conversa". Agora as pendências são fechadas com um resultado de erro, que o modelo explica.
  Aparecia como bug de cosmética até existir escrita; com ações, era alteração aplicada sem
  registro nenhum na conversa.
- Clique repetido em Confirmar não aplica o efeito duas vezes: a pendência sai da fila antes
  de executar, então a segunda chamada não acha o id.

### Segurança

- `conversa`, `pendentes`, `resultados` e `iteracao` são `#[Locked]`. O checksum do snapshot
  do Livewire já barra adulteração do payload, mas não uma chamada legítima de `$wire.set()`
  — e `pendentes` guarda exatamente o input que será executado se o usuário aprovar.

## v1.0.2

### Corrigido

- Mandar uma mensagem devolvia o chat para o tema claro. O morph do Livewire ressincroniza
  os atributos da raiz a partir do HTML do servidor, que não conhece a escolha do usuário —
  então a classe `dark`, aplicada no cliente, era apagada a cada requisição. Só aparecia no
  tema escuro, porque no claro não há classe para perder. Um `MutationObserver` no atributo
  `class` repõe a classe; observar o atributo em vez de usar hook do Livewire porque os nomes
  de hook mudam entre a v3 e a v4 e o pacote suporta as duas.

## v1.0.1

### Corrigido

- O tema escuro não trocava o fundo do card, o header nem a área de conversa. A classe
  `dark` estava no mesmo elemento que carrega `dark:bg-gray-900`, e o Tailwind gera
  `.dark\:bg-gray-900:is(.dark *)` — seletor de ancestral, que não casa com o próprio
  elemento. As bolhas e o textarea escureciam (são descendentes), mas o card seguia
  `bg-white`, e header e área de conversa herdam o fundo dele. A raiz do componente passou
  a ser um div só de tema, com o card como descendente.

## v1.0.0

Sai do `0.x`. Não é uma promessa de estabilidade de API — o pacote ainda está em evolução e
mudança incompatível pode acontecer em minor; leia o changelog antes de subir de versão.

Quem está na v0.2.0 pode subir trocando a constraint para `^1.0`; não há nada a migrar.
Vindo da v0.1.x, os passos da seção abaixo continuam valendo.

### Adicionado

- **Seletor de tema no header** — cicla entre seguindo o sistema → claro → escuro, com a
  escolha no `localStorage`. Um `<script>` inline aplica a classe durante o parse do HTML,
  antes do primeiro paint, para quem escolheu escuro não ver um lampejo claro.
- `tema.alvo` decide onde a classe `dark` entra: `componente` (padrão, só o card do chat —
  funciona mesmo em aplicação sem tema escuro próprio) ou `documento` (o `<html>`, para
  aplicação que já tem tema escuro em todas as telas).
- `tema.seletor => false` esconde o botão para quem já tem seletor próprio, sem desligar o
  tema — quem havia escolhido antes não perde a escolha.

## v0.2.0

### Atualizando da v0.1.x

Quatro passos manuais — sem eles a atualização quebra a instalação:

```bash
composer update rogga/claudinho
php artisan migrate                                          # tabela de configurações
php artisan vendor:publish --tag=claudinho-assets --force     # logo do header
php artisan vendor:publish --tag=claudinho-config --force     # opcional, ver abaixo
```

E no `tailwind.config.js` (ou via `@source` no `app.css`, em Tailwind 4), inclua as views do
pacote no `content`:

```js
'./vendor/rogga/claudinho/resources/views/**/*.blade.php',
```

Isso **já era necessário** na v0.1.x — as classes arbitrárias (`min-h-[24rem]`, `max-h-[60vh]`)
nunca eram compiladas sem isso — mas não estava documentado. Agora que o tema escuro e o
modal dependem de mais classes, a falta fica visível.

Republicar o config é opcional: `mergeConfigFrom` entrega as chaves novas
(`permissao_admin`, `modelos`, `titulo`, `placeholder_vazio`, `logo`) a partir do pacote,
então uma cópia publicada antiga continua funcionando. Republique só se quiser os comentários
novos no arquivo.

### Mudança de padrão

- O modelo padrão passou de `claude-opus-5` para `claude-sonnet-5`. Quem define
  `ANTHROPIC_MODEL` no `.env` não é afetado. Vale saber que o prefixo mínimo cacheável no
  Sonnet 5 é 1024 tokens contra 512 no Opus 5 — abaixo disso o cache não é criado e não há
  erro, o sintoma é `cache_read_input_tokens` sempre em zero.

### Adicionado

- **Configuração em tela** — engrenagem no header abre um modal para trocar modelo e chave
  da API sem deploy, atrás do gate `permissao_admin` (padrão `claudinho_admin`). A chave vai
  criptografada com a `APP_KEY` e nunca volta para o navegador: o campo é só de escrita e o
  que a tela mostra é uma máscara. O que foi gravado vence o `config`/`.env`; valor vazio
  cai no `.env` de novo.
- **Tema claro e escuro** em todo o componente — container, bolhas, textarea, botões,
  markdown (`dark:prose-invert`) e os rótulos do gráfico. Funciona com `darkMode: 'media'`
  e `'class'`, sem configuração extra.
- **Logo no header** — quatro PNGs publicados em `public/vendor/claudinho`: ícone no mobile,
  assinatura horizontal de `sm` para cima, em versão clara e escura. `'logo' => false`
  desliga.
- **Indicador de digitação animado** — o cursor `▌` deu lugar à marca em SVG inline, com os
  olhos piscando e as antenas se movendo. Respeita `prefers-reduced-motion`.
- **Auto-scroll no chat** — acompanha mensagem nova e token de streaming, mas para enquanto
  o usuário está lendo o histórico mais acima. Enviar volta ao fim.
- **Botão enviar só com ícone**, com `aria-label` como nome acessível.
- `titulo`, `placeholder_vazio` e `logo` no arquivo de config — as duas primeiras já eram
  lidas pela view, mas não existiam no config publicado.
- `phpunit.xml`, que faltava: o `./vendor/bin/pest` documentado no README não rodava.

### Corrigido

- `app.key` no `TestCase` — qualquer render de Livewire estourava com
  `MissingAppKeyException`. Nenhum teste renderizava a view antes, então ninguém tinha
  batido nisso.
- `allow-plugins.pestphp/pest-plugin` no `composer.json` — o `composer install` não
  interativo abortava.

### Notas de teste

Os 13 testes de `ConfiguracoesTest` exigem a extensão `pdo_sqlite` para o banco em memória
do testbench. Sem ela eles pulam com a mensagem explicando o motivo, em vez de falhar.

## v0.1.2

- Remove a versão fixa do `composer.json`.

## v0.1.1

- Expõe a versão instalada do pacote.

## v0.1.0

- Versão inicial.
