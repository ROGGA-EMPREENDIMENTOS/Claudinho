# Changelog

## v1.9.0

### Adicionado

- **A transcrição saiu do papel: o áudio é mesmo mandado à API do Google e volta como texto.**
  A 1.8.0 entregou a configuração; faltava quem chamasse. O `Midia\Transcritor` faz a chamada,
  e o `Recebedor` troca o JSON do gateway pela transcrição — como já fazia com a descrição da
  foto.

  Usa o `speech:recognize` SÍNCRONO, e não o `longrunningrecognize`: quem está do outro lado
  está esperando a resposta chegar no aplicativo de mensagens, e o assíncrono exigiria o
  arquivo num bucket do Cloud Storage — ou seja, obrigaria a aplicação a ter um. O preço é o
  teto de um minuto por áudio, que é o recado de WhatsApp típico.

  A codificação e a taxa vão calculadas do próprio arquivo (cabeçalho OpusHead, quadro do MP3),
  e não chutadas: errar a taxa não degrada a transcrição, faz a API recusar o áudio inteiro. O
  tipo passa a ser comparado sem os parâmetros, porque o WhatsApp manda `audio/ogg; codecs=opus`
  e comparado inteiro ele não casava com lista nenhuma.

  Cada falha vira uma resposta diferente, e a diferença é o que a pessoa faz em seguida:
  áudio acima de um minuto e formato que a API não lê dizem explicitamente para **não** reenviar;
  áudio incompreensível pede que repita; falha de chamada pede o reenvio. É o mesmo cuidado que
  a foto já tomava entre "endereço bloqueado" e "download falhou".

  A anotação anuncia o texto como TRANSCRIÇÃO, e não como fala de quem enviou: o reconhecimento
  erra, e o modelo precisa saber disso para confirmar o que ficou dúbio em vez de agir sobre o
  palpite. O `DestinoDeMidia` da aplicação recebe o áudio já com o texto em
  `$midia->transcricao`, para não haver uma segunda transcrição só para guardar os dois juntos.

  A chave viaja na query string, que é como a API do Google aceita chave de projeto — e a
  mensagem de erro do cliente HTTP traz a URL inteira. Ela é apagada antes de qualquer registro:
  sem isso o log da aplicação passaria a guardar a credencial em texto puro a cada falha.

### Mudado

- **O interruptor da transcrição é independente do de foto e vídeo.** Quem só quer transcrever
  recado de voz não precisa ligar `api.midias.habilitado`, e o áudio não sai da lista
  `api.midias.tipos` — ela foi escrita por quem instalou o pacote antes de áudio existir nele, e
  o `mergeConfigFrom` é raso demais para acrescentar nada lá. Exigir a edição daquela lista
  deixaria o interruptor sem efeito nenhum em quem já tem o config publicado.

- **O `InterpretaMidia` passou a ser registrado sempre**, e é ele quem decide se tem o que
  fazer — igual ao `AutenticaCanal`, que já funcionava assim. O interruptor da transcrição mora
  no BANCO, e o registro da rota acontece no boot de toda requisição da aplicação: ler o banco
  ali sairia caro justamente nas requisições que nunca falam com o Claudinho. Com os dois
  interruptores desligados a requisição sai dele intacta, que é exatamente o que acontecia
  quando ele não era registrado.

## v1.8.0

### Adicionado

- **Transcrição de áudio pela API do Google Speech-to-Text**, configurável na aba *Canais* da
  engrenagem: um interruptor e a chave. O áudio chega pelo canal externo como a foto chega —
  `{"type":"audio/ogg","uri":"https://..."}` no campo `mensagem` —, só que o Claude lê e não
  ouve; quem transcreve é o Google, e o que entra na conversa é o texto.

  **Desligado por padrão**, e ligar é decisão de quem instala: passa a haver chamada paga a um
  serviço de terceiro, fora da Anthropic, com o áudio de quem está conversando dentro dela.
  Atualizar o pacote não liga isso sozinho.

  A chave vai criptografada com a `APP_KEY`, igual à do Claude, e o campo é só de escrita — o
  que a tela devolve é máscara. `GOOGLE_SPEECH_HABILITADO` e `GOOGLE_SPEECH_API_KEY` continuam
  valendo como padrão para quem prefere configurar por ambiente; o gravado em tela vence, e
  *Limpar* devolve o controle ao `.env`, como no resto do pacote.

  O idioma (`transcricao.idioma`, padrão `pt-BR`) fica só no arquivo: quem fala com o
  assistente é o mesmo público da aplicação, e trocar isso é decisão de instalação, não de
  operação.

- **`Rogga\Claudinho\Transcricao`.** O único lugar que responde se a transcrição está valendo
  agora e com que chave — mesmo papel do `Canal` para as regras do canal. Aqui o motivo é mais
  forte, porque um dos valores é segredo: `config('claudinho.transcricao.chave')` lido direto
  ignoraria a chave gravada em tela sem ninguém perceber.

  `habilitada()` é o interruptor e `ativa()` é o resultado. Ligado sem chave não é "meio
  ligado": é desligado com aparência de ligado, e separar os dois é o que permite à tela avisar
  em vez de deixar cada áudio falhar em silêncio no log.

## v1.7.2

### Adicionado

- **As regras do canal externo viraram campos.** O que a 1.7.1 mostrou em leitura na aba
  *Canais* agora se edita ali: minutos de inatividade que começam conversa nova, se as ações
  valem neste canal, as palavras que confirmam uma alteração e o prazo delas, as instruções do
  canal e os hosts de onde se aceita baixar mídia.

  O motivo é o intervalo entre descobrir e corrigir. Todas elas se descobrem erradas em
  produção, olhando conversa de verdade: o gateway trocou de domínio e a foto passou a ser
  recusada, o pessoal responde "ok" e não "sim", a resposta veio comprida demais para o
  WhatsApp. Nenhuma delas justifica um deploy, e enquanto ele não sai o canal fica meio quebrado.

  **Campo vazio volta ao config**, como na chave da API e no contexto. É o que permite desfazer
  uma edição sem precisar lembrar o valor do arquivo, e o que mantém o `config/claudinho.php`
  como a resposta padrão de quem versiona a configuração no git.

  Os hosts aceitam a URI assinada inteira, com esquema, caminho e porta — é o que está no log e
  no painel do gateway. Guardar `https://mmg.whatsapp.net/v/t62...` faria a comparação falhar
  sem explicação, já que só o host é comparado; então o campo devolve o domínio sozinho.

  Continuam só no arquivo, porque não são decisão de quem opera: o resolvedor de usuário e o
  destino da mídia (são classes), os tipos aceitos e os tetos de download.

- **`Rogga\Claudinho\Canal`.** Um lugar só para responder "o que está valendo agora" em cada
  uma dessas regras. Perguntam quatro pontos diferentes — o controller, o registro da conversa,
  o Recebedor e a tela —, e espalhada por `config()` em quatro arquivos a precedência entre
  arquivo e tela precisaria ser lembrada em cada um deles.

### Corrigido

- Dois testes do endpoint falhavam em ambiente com banco: chamavam `comEndpoint()` no corpo do
  teste, que recria a aplicação, e o `:memory:` do sqlite morre com a conexão anterior — a
  tabela migrada no `beforeEach` já não existia. Só apareciam onde o driver está instalado; nos
  demais eles pulavam, e um teste que pula não protege nada.

- A memória de requisição do `Configuracao` é estática e atravessava os testes. Não incomodava
  enquanto só o chat a lia; com o `Canal`, código sem banco nenhum passou a perguntar o que está
  gravado em tela, e herdava a resposta do teste anterior.

## v1.7.1

### Adicionado

- **Regras do canal externo em tela.** A aba *Canais* da engrenagem passa a mostrar, em leitura,
  o que o `config/claudinho.php` decide sobre o endpoint: minutos de inatividade que começam
  conversa nova, se as ações estão liberadas neste canal, as palavras que confirmam uma
  alteração e o prazo delas, as instruções acrescentadas ao system prompt, e as mídias — hosts
  liberados, tipos aceitos, tamanho, quantidade por mensagem e destino.

  Não virou formulário de propósito: palavra que aprova, prazo de confirmação e host de onde se
  aceita baixar arquivo são regra de autorização e de segurança, e mudam com revisão e deploy,
  não com um clique de quem está atendendo. Mas quem atende precisava poder responder "por que
  o 'ok' dele não confirmou nada?" sem abrir o arquivo no servidor — e ninguém enxergava que a
  lista de hosts vazia deixa o servidor aceitando qualquer endereço público que chegue dentro
  da mensagem.

  As palavras aparecem **normalizadas**, que é como elas casam: `Sim!` no config vira `sim` na
  tela, porque é `sim` que a resposta de quem está do outro lado precisa ser. A lista vazia, que
  não aprova nada e cancela toda alteração, é dita em amarelo — vista do WhatsApp, ela parece o
  assistente ignorando o "sim".

  Os valores de mídia caem nos mesmos padrões do `Recebedor` quando a chave não existe, pelo
  mesmo motivo da correção anterior: com o config publicado antes da 1.7, ler sem padrão faria a
  tela anunciar "nenhum tipo aceito" numa instalação que aceita os sete.

### Corrigido

- A documentação da API dentro da engrenagem afirmava que só `SIM` aprova uma alteração. Quem
  trocou `palavras_confirmacao` no config lia uma instrução errada na própria tela; agora ela
  aponta para a lista em uso.

## v1.7.0

### Adicionado

- **Foto e vídeo no canal externo.** O gateway não manda o arquivo: manda
  `{"type":"image/jpeg","uri":"https://..."}` no campo `mensagem`, com uma URI assinada que
  costuma vencer em meia hora. Repassado assim, o modelo recebia um endereço que não sabe
  abrir e respondia que não entendeu, enquanto o link vencia sem ninguém baixar nada.

  Ligado o `api.midias.habilitado`, o pacote baixa na hora, descobre o tipo pelos bytes,
  descreve a imagem e troca o JSON pela descrição. A descrição entra como TEXTO de propósito:
  mandar a imagem junto do histórico custaria os tokens dela em cada volta do loop de
  ferramenta. Vídeo a API não lê — é entregue igual, e o assistente pede a descrição em texto.

- **Contrato `DestinoDeMidia`.** O pacote não sabe o que a foto significa na aplicação — num
  sistema de atendimento vira anexo de chamado, num de vistoria é evidência do item. Guardar é
  decisão de domínio, e o destino ainda pode acrescentar uma frase à anotação ("vai como anexo
  do chamado que você abrir"). Sem destino configurado a imagem continua sendo descrita: o que
  se perde é o arquivo, não o entendimento.

- **`Claude::comEsforco()`** sobrepõe o esforço numa instância. A descrição da imagem usa
  `low`: dizer o que aparece numa foto não é raciocínio, e é o único ponto do fluxo em que
  quem está conversando espera duas chamadas à API em sequência.

### Sobre segurança

A URI vem DENTRO da mensagem, ou seja, de fora: qualquer um pode digitar
`{"type":"image/jpeg","uri":"https://169.254.169.254/..."}` no WhatsApp e o gateway repassa
como texto. Por isso:

- `api.midias.hosts` preenchida é a única barreira e dispensa DNS — é o modo de produção.
- Vazia, aceita qualquer endereço **público**: rede interna barrada (IP direto ou domínio que
  resolva para lá) e redirecionamento não seguido, porque host público que responde 302 para
  `169.254.169.254` anularia a checagem.
- Teto de tamanho conferido nos bytes baixados, e não pelo `Content-Length`, que é do gateway
  e pode não vir.

### Compatibilidade

Nada muda para quem já usa o pacote: `api.midias.habilitado` nasce `false`, e com ele desligado
o middleware não entra na pilha do endpoint. Atualizar da 1.6 não faz o servidor baixar arquivo
nenhum nem gastar chamada de visão até alguém ligar.

## v1.6.3

### Alterado

- **`laravel/framework` passou a aceitar o `^13.0`**, junto com o `^11.0` e o `^12.0` que já
  valiam. O pacote não usava nada que a 13 tenha tirado — o que barrava a instalação numa
  aplicação já atualizada era só a restrição do `composer.json`.
- **`orchestra/testbench` foi para `^9.0|^10.0|^11.0`**, para a suíte não só *instalar* como
  também *rodar* no Laravel 13: o testbench 10 exige `laravel/framework ^12`, então sem o
  `^11.0` os testes do pacote continuariam presos à 12 enquanto o pacote se anunciava
  compatível com a 13. A suíte passa igual nas duas — mesmo número de testes, mesmas
  asserções.
  - O testbench 11 pede **PHP ^8.3**, e o pacote continua em `^8.2` de propósito: quem
    desenvolve em 8.2 resolve para o testbench 10 e testa contra a 12, sem perder nada.

## v1.6.1

### Corrigido

- **Aplicação com Clockwork instalado não conseguia fazer nenhuma pergunta**: a primeira
  falhava com `Stream is not seekable`. A resposta do stream chega em pedaços e é lida linha
  por linha — o corpo não se rebobina —, e quem escuta os eventos do HTTP client do Laravel
  (Clockwork, Telescope, Debugbar) rebobina o corpo para registrar a resposta. O listener que
  não rebobina é ainda pior: não estoura, consome o stream que o chat ainda não leu e a
  resposta chega vazia. Não é bug de quem escuta — gravar uma resposta que só pode ser lida
  uma vez é impossível.
  - A chamada à API passou a usar uma `Factory` própria do HTTP client, **sem event
    dispatcher** (`Rogga\Claudinho\Http\ClienteHttp`): sem dispatcher não há
    `RequestSending` nem `ResponseReceived`, então a requisição não passa por listener
    nenhum. O HTTP client da aplicação fica intacto, com os eventos dele.
  - O preço é a chamada à API não aparecer no painel de HTTP dessas ferramentas. As duas
    chamadas do pacote — o stream e a mensagem avulsa — usam o mesmo cliente de propósito:
    transporte com dois caminhos de rede, um observável e outro não, custa mais do que a
    linha que se perde no painel.

### Alterado

- **`Http::fake()` não intercepta mais o Claudinho** nos testes de quem instala o pacote,
  pela mesma razão: a chamada não passa pelo facade. O fake vai no cliente do pacote —
  `app(ClienteHttp::class)->fake([...])` —, que é a mesma `Factory` do Laravel e mantém
  `sequence()`, `assertSent()` e `assertSentCount()`. Ver [Testes](README.md#testes).

## v1.6.0

### Corrigido

- **Trocar o modelo para Haiku 4.5 em tela quebrava o chat inteiro**, com HTTP 400 e
  `adaptive thinking is not supported on this model` em qualquer pergunta. O payload mandava
  `thinking: adaptive` e `output_config.effort` em toda requisição, e os dois campos só
  existem da geração 4.6 em diante. Não era uma resposta pior num modelo mais fraco: era o
  modelo do select do pacote derrubando o assistente na primeira pergunta.
  - Modelo fora da lista de suporte vai **sem** os dois campos, em vez de a requisição ser
    recusada. A lista é de modelos suportados, e não de exceções, de propósito: modelo
    desconhecido — inclusive o que alguém digitar fora do `config` — responde sem raciocinar,
    e não com 400.
  - O casamento é por prefixo, para os IDs com sufixo de data continuarem reconhecidos.

### Adicionado

- **`Claude::suportaRaciocinio(string $model)`**, público, para quem monta requisição própria
  à API — uma análise de arquivo em fila, por exemplo — perguntar pela mesma regra em vez de
  repetir a lista de modelos do lado da aplicação. Duas listas em lugares diferentes
  envelhecem separadas, e a segunda só dá sinal de vida quando alguém troca o modelo em tela
  e leva 400.

### Alterado

- **O botão *Limpar conversa* fica só com o ícone no mobile e no chat flutuante**, quadrado
  como os vizinhos do header. O flutuante não depende de breakpoint: o painel tem 26rem fixos
  mesmo no desktop, e era justamente lá que o rótulo espremia o título contra os outros
  botões. O nome acessível passou para `aria-label`, que vale nos dois casos.

## v1.5.0

### Adicionado

- **O glossário de negócio agora se cadastra em tela**, uma regra por linha, com assunto,
  autoria e data. Era a última coisa importante do assistente que ainda exigia deploy — e a
  pior de todas para exigir, porque quem sabe a regra de negócio é quem opera o sistema, não
  quem faz o deploy. O glossário crescer é o mecanismo de aprendizado do Claudinho; enquanto
  cada correção de frase custava um `git push`, ele crescia na velocidade das janelas de
  release.
  - **Cada regra tem um ASSUNTO, e a tela abre no índice deles** — `OBRAS 3`,
    `DOCUMENTOS 12`, `PPC 10` —, com as regras aparecendo ao escolher um. Uma lista plana de
    46 regras é um paredão: não se acha nada, não se compara nada, e cada ação do Livewire
    carregaria ~200 KB de HTML (pelo índice são ~23 KB). A busca por texto atravessa os
    assuntos, para quem não sabe em qual deles está o que procura.
  - **O assunto vira o título do bloco no system prompt**, não só um agrupamento de tela.
    Numa lista corrida, a regra de PPC e a de documentos chegam ao modelo com o mesmo peso e
    sem vizinhança, e ele perde a pista de que "status" quer dizer coisas diferentes em cada
    assunto. Sem nenhum assunto cadastrado, o texto sai exatamente como saía antes.
  - O assunto é texto livre com `datalist` do que já existe, normalizado para maiúsculas:
    `Obras`, `obras ` e `OBRAS` viram um assunto só. Sem isso seriam três grupos na tela e
    três blocos no prompt, cada um com um terço do assunto.
  - O `config` também aceita o formato por assunto (`'OBRAS' => [...]`), e a lista simples de
    antes continua funcionando. Organizar o arquivo antes de importar poupa classificar
    dezenas de regras uma a uma na tela: o botão *Importar* leva o assunto junto.
  - **Editar, desativar e remover, regra a regra.** *Desativar* é o que faltava: tira a regra
    do prompt na hora e mantém o texto legível, que é exatamente o que se quer quando uma
    regra piorou a resposta e ainda não se sabe qual é a redação certa. Jogar fora obrigaria
    a reescrever do zero para tentar de novo.
  - **A precedência é a mesma do modelo e da chave**, com um detalhe deliberado: o critério é
    a tabela ter ao menos UMA regra, não ter regras ativas. Somar config e tela mandaria duas
    versões da mesma regra ao modelo assim que alguém corrigisse uma frase, e a velha
    continuaria no prompt sem ninguém saber por quê. Cair no config quando todas estão
    desativadas ressuscitaria justamente o glossário que a pessoa acabou de silenciar.
  - **Botão que importa o glossário do config**, pulando o que já está cadastrado. Sem ele a
    tela nasceria vazia para quem tem dezenas de regras no arquivo — ou seja, para quem mais
    usa o glossário.
  - **As regras salvam na hora**, sem passar pelo *Salvar* do rodapé: são registros, não
    campos de formulário. Por isso o botão que fecha o modal passou a dizer *Fechar* — havia
    um *Cancelar* que não cancelava mais nada do que estava na tela.
  - Sem a migration, a aba diz o que falta rodar e o chat segue pelo config, em vez de
    mostrar um formulário que engole o que for escrito nele.
- **O contexto também se edita em tela**, com o config como padrão. Esvaziar o campo e salvar
  devolve o texto do arquivo, e o campo volta preenchido com ele — afirmar contexto vazio
  seria a tela mentir sobre o que o assistente está usando.
- Migration `claudinho_glossario` e o model `Rogga\Claudinho\Models\Regra`. Sem criptografia,
  ao contrário da `Configuracao`: glossário não é segredo, é documentação — e cifrar tiraria
  a busca por texto. Autoria é o **nome** de quem escreveu, não id: o pacote não conhece o
  model de usuário da aplicação e não vai criar chave estrangeira para uma tabela que pode
  nem se chamar `users`.

### Alterado

- **O modal de configurações virou três abas** — *Assistente*, *Modelo e chave*, *Canais*.
  Ele já estava alto demais antes do glossário; com ele, viraria rolagem sem fim. Abre na
  *Assistente* porque contexto e glossário são o que se mexe toda semana, enquanto modelo e
  chave se define uma vez. As abas são estado do servidor, e não do Alpine: toda ação do
  glossário volta ao Livewire, e com a aba só no cliente cada regra salva devolveria o
  usuário para a primeira.
- O modal ficou mais largo (`max-w-2xl`): regra de glossário é texto corrido de várias
  linhas, e numa coluna estreita cada uma vira um parágrafo alto demais para comparar com a
  de baixo.
- **O aviso de chave ausente saiu de dentro da aba** e passou a ficar no alto do modal. É a
  única condição em que o chat está quebrado, e escondê-la atrás de uma aba que ninguém abriu
  seria deixar de avisar justamente quem ainda não configurou nada.

## v1.4.2

### Alterado

- **A versão no "Sobre" perdeu o `v` da tag.** O Composer devolve o nome da tag como está, e a
  maioria dos pacotes PHP marca `v1.4.1` — então a janela mostrava `Claudinho v1.4.1` ao lado de
  `Laravel 11.x` e `PHP 8.3.x`, sem prefixo. Numa lista, o `v` solto lê como inconsistência e não
  como informação. A normalização está no `versaoDe()`, ponto único por onde toda versão do
  Composer passa, e é só do prefixo: `dev-main` de quem exige o pacote por branch continua
  aparecendo inteiro, porque é justamente essa informação que explica por que não há número ali.

## v1.4.1

### Corrigido

- **No mobile, focar o campo de pergunta dava zoom e a página não voltava.** É comportamento do
  Safari do iOS: campo com fonte abaixo de 16px é tratado como ilegível e o navegador aproxima
  sozinho ao receber o foco — mas não desfaz ao sair, então a tela fica deslocada até o usuário
  pinçar de volta. Valia tanto no card em tela quanto no painel flutuante, e no flutuante era
  pior: sendo `fixed`, ele se posiciona pelo viewport de layout e sai do lugar com o zoom. O
  campo agora é 16px no mobile e volta aos 14px do resto do chat a partir do `sm:`. Não havia
  como resolver pelo `viewport`, que é meta tag da aplicação — e travar `maximum-scale` seria
  tirar o zoom de quem precisa dele para ler.
- Mesmo ajuste no **modelo** e na **chave** do modal de configurações, pelo mesmo motivo: `select`
  e `input` disparam o zoom igual ao `textarea`.

## v1.4.0

### Adicionado

- **Janela "Sobre"**, pelo ⓘ do header. Duas coisas que antes não tinham lugar na tela:
  - **Como o assistente trabalha**, escrito para quem usa o chat e não para quem instala —
    consulta pelas ferramentas registradas, só vê o que aquele usuário vê, alteração espera
    confirmação, e **pode errar, inclusive ao somar**. O último item fica em destaque porque é
    o único que muda o que a pessoa faz com o número que leu ali.
  - **As versões** de Claudinho, Laravel, Livewire e PHP, mais o modelo em uso. É o que se
    pede a quem abre um chamado, e o valor está na combinação, não em cada número solto.
    Versão que o Composer não sabe informar sai da lista em vez de virar "desconhecida".
  - **Sem gate**: nada ali é segredo — chave e token continuam atrás do `permissao_admin`, na
    engrenagem — e a informação serve justamente a quem não administra o pacote. `'sobre' =>
    false` no config tira o botão e a janela.
  - No rodapé, a marca da **Rôgga Empreendimentos** em `data:` URI (~4 KB no HTML da página).
    Arquivo em `public/vendor/claudinho` seria mais leve, mas quebraria em quem não republicou
    os assets — e imagem quebrada no rodapé de uma janela "Sobre" desmente a janela. O PNG de
    origem (fundo branco) virou transparente com a tinta intocada; fica em `art/rogga.png`.
  - O modelo é lido de onde o `Claude` lê (gravado em tela, config como padrão). Ler do config
    direto mostraria o do `.env` enquanto as respostas vêm de outro.
  - Blade puro com Alpine, sem componente Livewire: o conteúdo não muda até a página recarregar,
    então abrir não tem por que ir ao servidor. O modal de configurações é Livewire porque lá
    há formulário e segredo em banco.
- `Claudinho::versaoDe()` e `Claudinho::ambiente()` — a versão de qualquer pacote instalado e o
  conjunto que a janela mostra. `Claudinho::versao()` passou a ser o caso particular do primeiro.

## v1.3.1

### Adicionado

- **Ligar a API e gerar o token pela tela**, sem `.env`. `api.habilitado` e `api.token` do
  config viraram apenas o *padrão*; o valor gravado em tela vence, como já valia para modelo e
  chave. O padrão segue desligado: atualizar o pacote não abre endpoint.
  - O **token do chamador** é gerado na tela, guardado criptografado, com rotação e revogação.
    Aparece **uma vez só**, na resposta em que é gerado — ao contrário da chave do Claude,
    este segredo precisa ser lido, porque quem opera tem de copiá-lo para o gateway. Depois
    disso, só a máscara; perdeu, gera outro e o anterior para de valer na hora.
  - As rotas passaram a ser registradas **sempre**, com o middleware decidindo. Decidir no
    boot exigiria ler o banco em toda requisição da aplicação, inclusive nas que nunca falam
    com o Claudinho — e é o banco que guarda o interruptor. Rota existir desligada não é
    brecha: o middleware recusa antes de qualquer processamento e **depois** do token, então
    quem não se autenticou recebe 401 nos dois casos e não usa a resposta como sensor de
    estado.
  - Consequência a registrar: sem trava de `.env`, quem passa no gate `permissao_admin` pode
    abrir um endpoint HTTP com acesso aos dados. O resolvedor de usuário continua sendo código
    (é uma classe), então nada atende sem ele — mas vale conferir quem tem essa permissão.
  - O diagnóstico da tela passou a resolver três dos quatro itens ali mesmo; o quarto é o
    resolvedor, que não tem como vir de formulário.

### Corrigido

- **O endpoint não exigia do remetente a permissão que abre o chat.** O `Chat::mount()` checa
  `claudinho.permissao`; o `ConversaController` não checava. Um número cadastrado no resolvedor
  era, portanto, um caminho paralelo para usar o assistente sem o gate que a aplicação exige na
  tela — exatamente o que este endpoint não pode ter. A recusa devolve a **mesma** mensagem de
  número desconhecido: distinguir os dois casos entregaria, a quem tem o token, uma sonda de
  quem tem acesso.
- **Uma clicada na engrenagem abria todos os modais de configuração da página.** Quando há mais
  de um chat — o botão flutuante no layout global e o card numa tela dedicada, por exemplo —
  cada um renderiza o seu modal, e todos escutavam o evento em `window`. O primeiro X fechava o
  de cima e revelava o de baixo, dando a impressão de precisar clicar duas vezes. Agora o
  evento diz de quem é, e cada modal só responde ao seu. O `.window` continua necessário: a
  engrenagem vive no card, que é irmão do modal, então o evento não passa por ele ao subir.
- `trim(null)` no `ConversaController`: `$prefixo` é null no caminho normal, e isso é deprecation
  no PHP 8 e **erro no PHP 9**. Passou por toda a suíte em silêncio e só apareceu num teste
  manual contra a aplicação — o handler de erros do Laravel engole deprecations antes do PHPUnit
  ver, então a suíte não cobre essa classe de problema.

### Alterado

- A borda do chat flutuante ficou **mais suave**: 1px a 50%, acento em vez de contorno, no anel
  do botão e na borda do painel. Isso a põe em 1,7:1 sobre branco, abaixo do 3:1 exigido de
  elemento de interface — aceitável porque não é a linha que identifica os componentes (o botão
  é o círculo branco com sombra e a marca dentro; o painel é o próprio fundo mais a sombra). O
  anel de **foco** continua sky-500 em 2px, intocado: é ele que precisa saltar e é nele que a
  exigência de contraste se aplica. No hover do botão a cor fecha, para o alvo dar retorno.

## v1.3.0

### Adicionado

- **Endpoint HTTP para canais externos**, para WhatsApp e afins conversarem com o mesmo
  assistente. Desligado por padrão (`api.habilitado`), exige `php artisan migrate`.
  - **Duas identidades, separadas de propósito.** O token do header autentica o *chamador*
    (o gateway); o `ResolvedorDeUsuario` da aplicação diz de qual *usuário* é a permissão. O
    endpoint autentica como ele, e daí em diante gates das ferramentas, Global Scopes por
    obra e nome no system prompt valem igual ao chat em tela — não há caminho paralelo de
    permissão. Token vazado dá acesso ao endpoint, não aos dados de todos.
  - O resolver é class-string no config, não closure: closure não sobrevive a `config:cache`.
  - **Confirmação de ação por texto.** Sem o card, o endpoint pausa e pede por escrito. Só
    aprovação EXATA aprova (`sim` aprova, `sim, pode cancelar` não) — casar por conteúdo faria
    `não, não confirmo` conter `confirmo` e autorizar o oposto. Qualquer outra resposta
    cancela, em vez de deixar pendência viva esperando um `sim` de outro assunto. Prazo
    próprio (`api.minutos_confirmacao`, padrão 5), e mais de uma pendência na rodada cancela
    todas, porque uma frase não distingue para qual delas o "sim" vale.
  - `api.acoes => false` deixa o canal somente-leitura sem desregistrar as ações, que
    continuam valendo na tela. A ferramenta não é declarada **e** é recusada na execução —
    tirar da lista impede de ser oferecida, não de ser pedida.
  - `api.instrucoes` entra no fim do system prompt só neste canal, e por padrão desfaz a regra
    de tabela markdown, que a tela renderiza bem e o WhatsApp não.
  - Conversa contínua por canal+identificador, recomeçando após `api.minutos_inatividade`
    (padrão 30) de silêncio. Comando `claudinho:limpar-conversas` para a faxina.
  - Throttle e middleware configuráveis; o middleware do token é sempre acrescentado pelo
    pacote, porque habilitar o endpoint sem autenticar o chamador não é opção a oferecer.
- **`Rogga\Claudinho\Conversa`**: o loop de tool use virou classe headless, sem UI. O
  componente Livewire e o endpoint usam o mesmo motor — o loop é a parte mais delicada do
  pacote (quem grava tool_result para cada tool_use, quem conta iterações, quem vai para a
  fila de confirmação), e duplicá-lo seria garantir que as duas cópias divergissem justamente
  onde um erro corrompe a conversa. O `Chat` caiu de 652 para 381 linhas.
- **`Rogga\Claudinho\Confirmacao`**: a interpretação do "sim" isolada numa classe, porque é o
  ponto onde um acerto frouxo vira escrita não autorizada. Testável sem banco, sem HTTP e sem
  API.
- **Interruptores de canal na tela de configurações**, com a documentação da API junto.
  - **Botão flutuante**: some o botão do canto sem tirar o componente do layout. Não afeta o
    card dentro de uma página — quem o colocou ali foi a aplicação, e não cabe a uma tela de
    configuração escondê-lo. Padrão em `flutuante.ativo`.
  - **Atendimento pela API**: o endpoint passa a responder 503. Dois níveis de propósito —
    *publicar* a rota é decisão de deploy (`api.habilitado`), porque endpoint HTTP com acesso
    a dados não se cria por formulário web; *ligar e desligar o atendimento* é operação, e
    fica na tela. Sem a rota publicada, o interruptor aparece desabilitado explicando o que
    falta, em vez de fingir que liga algo.
  - O interruptor da API é lido no **middleware**, não no boot do provider: ler o banco no
    boot custaria uma consulta em toda requisição da aplicação, inclusive nas que nunca falam
    com o Claudinho. E é checado **depois** do token — quem não se autenticou não descobre se
    o atendimento está ligado.
  - **Documentação embutida**, não link: só ela sabe a URL deste ambiente. Traz o contrato, um
    `curl` pronto com o endereço real, e serve de diagnóstico marcando o que falta (rota,
    token, resolvedor, migration).
  - `Configuracao::booleano()`/`definirBooleano()` guardam `'1'`/`'0'`. A coluna é texto, e
    sem isso `"false"` seria verdadeiro — o erro clássico de flag em tabela chave/valor.
- A borda do chat flutuante passou do azul para o **terracota da marca** (`#d3754c`, o mesmo
  hex das antenas do ícone) — no anel do botão e na borda do painel. Hex literal em vez de um
  laranja aproximado do Tailwind: a borda existe para amarrar o botão ao ícone, então tem de
  ser o mesmo tom. Sem variante `dark:`, como a própria marca, e com contraste verificado
  (3,27:1 sobre branco e 5,55:1 sobre `gray-900`, acima do mínimo de 3:1 para elemento de
  interface). O anel de **foco** continua sky, de propósito: foco precisa se distinguir do
  repouso, e laranja sobre laranja não se vê. O card inline segue com a borda cinza discreta.
- `Configuracao::todas()` passou a memorizar o resultado **dentro da requisição**. Não é cache
  store (que o docblock recusa, por causa da chave da API): é só não repetir a mesma consulta
  na mesma requisição. O Claude lê duas chaves por resposta e a tela agora lê mais três.

### Corrigido

- **`expira_em` se reescrevia sozinha no MySQL.** A coluna era o primeiro `TIMESTAMP NOT NULL`
  sem default da tabela, e o MySQL dá a essa coluna um `ON UPDATE CURRENT_TIMESTAMP`
  implícito (com `explicit_defaults_for_timestamp` desligado, que é o padrão). Qualquer UPDATE
  que não listasse a coluna empurrava o vencimento para agora, e a conversa expirava sozinha
  no acesso seguinte — perdendo até ação esperando confirmação. Agora é `nullable`, o que
  remove o comportamento implícito.
- `confirmar_ate` não estava nos `$casts` do model: voltava do banco como string e o
  `isPast()` da checagem de prazo estourava, dando 500 em toda resposta de confirmação.
- `ConfiguracoesTest` tinha um teste que **nunca havia rodado** (a suíte pulava tudo por
  ausência de `pdo_sqlite`) e que não podia passar: o `abort` acontece no `mount`, então
  encadear `set()`/`call()` depois responde 404 e esconde o 403. Virou dois testes, um para o
  mount e outro que revoga a permissão com a tela aberta — este verificando o invariante que o
  próprio componente documenta, de revalidar em cada ação.

### Testes

- O guarda dos testes de banco passou a checar se existe **conexão**, em vez de exigir
  especificamente `pdo_sqlite`. Um teste que pula não protege nada — e foi rodando a suíte
  contra MySQL que os dois bugs acima apareceram, nenhum deles visível no `:memory:`.
- `migrate:fresh` entre testes de banco: em banco persistente as linhas acumulavam, e o teste
  que faz `Schema::drop()` deixava a tabela faltando para todos os seguintes.
- Fixtures compartilhadas (ferramentas de teste, resolver, `comEndpoint()`) foram para o
  `Pest.php`: o Pest carrega cada arquivo isoladamente, então fixture definida num arquivo de
  teste não existe para os outros.

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
