<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API do Claude
    |--------------------------------------------------------------------------
    |
    | Sem a chave o chat carrega mas falha na primeira pergunta, com mensagem
    | explícita. Em produção, prefira secret manager a .env na imagem.
    |
    */

    'api_key' => env('ANTHROPIC_API_KEY'),

    // Sonnet 5 entrega qualidade próxima de Opus em coding e uso de ferramentas
    // por ~40% do custo. Troque por claude-opus-5 se o glossário crescer ao ponto
    // de exigir raciocínio mais profundo sobre as regras de negócio.
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

    // Opções oferecidas no select da tela de configurações. É só a lista da UI: o
    // modelo em uso é o gravado em tela, ou este 'model' acima como fallback.
    'modelos' => [
        'claude-sonnet-5' => 'Sonnet 5 — padrão: melhor equilíbrio custo/capacidade',
        'claude-opus-5' => 'Opus 5 — mais capaz em raciocínio, custo mais alto',
        'claude-haiku-4-5' => 'Haiku 4.5 — mais rápido e barato, sem raciocínio adaptativo',
    ],

    'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 16000),

    // low | medium | high | xhigh | max — quanto o modelo raciocina antes de responder.
    // medium é o padrão por latência: o chat é síncrono. Só vale para modelos da
    // geração 4.6 em diante; em Haiku 4.5 e anteriores o campo nem é enviado,
    // porque a API recusa a requisição inteira quando ele aparece.
    'effort' => env('ANTHROPIC_EFFORT', 'medium'),

    'timeout' => env('ANTHROPIC_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Acesso
    |--------------------------------------------------------------------------
    |
    | Gate exigido para abrir o chat. null libera para qualquer usuário
    | autenticado. A permissão de cada ferramenta é separada desta.
    |
    */

    'permissao' => env('CLAUDINHO_PERMISSAO'),

    // Gate exigido para abrir a engrenagem e alterar modelo/chave da API em tela.
    // null libera para qualquer usuário autenticado — em produção, defina.
    'permissao_admin' => env('CLAUDINHO_PERMISSAO_ADMIN', 'claudinho_admin'),

    /*
    |--------------------------------------------------------------------------
    | Aparência
    |--------------------------------------------------------------------------
    |
    | O chat acompanha o tema da aplicação pelas variantes dark: do Tailwind —
    | funciona tanto com darkMode 'media' quanto 'class'.
    |
    */

    'titulo' => 'Assistente de IA',

    'placeholder_vazio' => 'Faça uma pergunta para começar.',

    // Exige `vendor:publish --tag=claudinho-assets`. Deixe false para o header
    // ficar só com o título, sem depender dos PNGs publicados.
    'logo' => true,

    // Botão no header que abre a janela "Sobre": versão instalada, versões de
    // Laravel/Livewire/PHP, modelo em uso e como o assistente trabalha. Não tem gate,
    // porque é o usuário do chat quem precisa dessa informação — e nenhum segredo
    // aparece lá. Deixe false para um header mais enxuto.
    'sobre' => true,

    'tema' => [

        // Botão no header que alterna sistema → claro → escuro. Deixe false se a
        // aplicação já tem o próprio seletor de tema, para não haver dois.
        'seletor' => true,

        // Onde a classe `dark` é aplicada:
        //
        //   'componente' — só no card do chat. É o padrão porque funciona em
        //                  qualquer aplicação, inclusive nas que não têm tema
        //                  escuro próprio: o resto da página não muda.
        //   'documento'  — no <html>, alternando a aplicação inteira. Use quando
        //                  a aplicação já tem tema escuro em todas as telas,
        //                  senão o chat escurece sozinho no meio de uma página clara.
        'alvo' => 'componente',

    ],

    /*
    |--------------------------------------------------------------------------
    | Chat flutuante
    |--------------------------------------------------------------------------
    |
    | Só aparência. Ligar o modo flutuante é decisão de onde o componente foi
    | colocado, não de config — a mesma aplicação pode ter a tela dedicada e o
    | botão no layout global:
    |
    |   @livewire('claudinho.chat')                        card na página
    |   @livewire('claudinho.chat', ['flutuante' => true]) botão fixo num canto
    |
    | No layout global, ponha uma vez antes do </body>. Fechar não descarta a
    | conversa: o componente segue montado e o painel só fica escondido.
    |
    */

    'flutuante' => [

        // Canto do botão e do painel: 'direita' ou 'esquerda'. Escolha o lado
        // livre — do outro costumam ficar os toasts de notificação.
        'posicao' => 'direita',

        // Nome acessível e tooltip do botão.
        'rotulo' => 'Abrir o assistente',

        // Já nasce aberto. Deixe false no layout global, senão o painel cobre a
        // tela em toda navegação. Serve para página dedicada ao assistente.
        'aberto' => false,

        // Padrão do interruptor que fica na tela de configurações. O valor gravado
        // lá vence este. Desligado, o botão some — mas só onde a aplicação usou
        // ['flutuante' => true]; no card da página o chat continua, porque quem o
        // colocou ali foi a aplicação, não uma configuração.
        'ativo' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Conversa
    |--------------------------------------------------------------------------
    */

    // Mensagens do histórico enviadas à API. Mais que isso encarece sem ganho.
    'limite_historico' => 20,

    // Voltas máximas do loop de tool use numa mesma pergunta.
    'max_iteracoes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Endpoint para canais externos (WhatsApp e afins)
    |--------------------------------------------------------------------------
    |
    | O mesmo assistente por HTTP, para um gateway de WhatsApp conversar com ele.
    |
    |   POST /claudinho/conversa           {canal, identificador, mensagem}
    |   POST /claudinho/conversa/reiniciar {canal, identificador}
    |
    | Ligar e gerar o token se faz pela TELA de configurações — estas chaves são
    | só o padrão para quem prefere ambiente. Exige `php artisan migrate` e o
    | resolvedor abaixo, que é classe e por isso não vem de formulário.
    |
    */

    'api' => [

        // Padrão do interruptor da tela, cujo valor gravado vence este. false para
        // atualizar o pacote não abrir endpoint em ambiente nenhum.
        'habilitado' => env('CLAUDINHO_API', false),

        // Autentica o CHAMADOR (o gateway), não o usuário da conversa. Também é só
        // o padrão: a tela gera e guarda o token, criptografado. Sem token de lado
        // nenhum, o endpoint responde 503 em vez de ficar aberto por esquecimento.
        'token' => env('CLAUDINHO_API_TOKEN'),

        // Classe que implementa Rogga\Claudinho\Contracts\ResolvedorDeUsuario e diz
        // qual usuário está do outro lado do número. É o item mais importante deste
        // bloco: o endpoint autentica como ele, e daí em diante gates das
        // ferramentas e Global Scopes valem igual ao chat em tela.
        //
        // Class-string e não closure porque closure não sobrevive a `config:cache`.
        'resolvedor' => null,

        'prefixo' => 'claudinho',

        // O que roda ANTES do token. O middleware do token é sempre acrescentado
        // pelo pacote — habilitar sem autenticar o chamador não é opção.
        'middleware' => ['api'],

        // Requisições por minuto, por IP. '' desliga.
        'throttle' => '30,1',

        // Foto e vídeo que chegam pela conversa. O gateway não manda o arquivo:
        // manda `{"type":"image/jpeg","uri":"https://..."}` no campo `mensagem`,
        // com uma URI assinada que costuma vencer em meia hora.
        //
        // Desligado por padrão: ligar significa o servidor passar a baixar
        // arquivo de endereço que vem na mensagem e a pagar uma chamada de visão
        // por imagem. É decisão de quem instala, não do pacote.
        'midias' => [

            'habilitado' => false,

            // Classe que implementa Rogga\Claudinho\Contracts\DestinoDeMidia e
            // decide o que fazer com o arquivo — virar anexo de chamado, evidência
            // de vistoria, nada. Sem ela a imagem ainda é DESCRITA e a descrição
            // entra na conversa; o que se perde é o arquivo.
            //
            // Class-string e não closure porque closure não sobrevive a
            // `config:cache`, igual ao resolvedor acima.
            'destino' => null,

            // Só o que a aplicação sabe receber. Imagem dos quatro primeiros o
            // modelo também consegue LER; vídeo ele não assiste, e por isso o
            // assistente pede a descrição em texto.
            'tipos' => [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'video/mp4',
                'video/3gpp',
                'video/quicktime',
            ],

            // De onde este servidor aceita baixar. A URI vem DENTRO da mensagem,
            // ou seja, de fora: qualquer um pode digitar o endereço da rede
            // interna no WhatsApp e o gateway repassa como texto.
            //
            // Preenchida, é a única barreira e não há consulta DNS — é o modo de
            // produção, com o host do gateway. Vazia, aceita qualquer endereço
            // PÚBLICO: a rede interna segue barrada e redirecionamento não é
            // seguido, mas é postura de desenvolvimento.
            'hosts' => [],

            // Teto do arquivo baixado. Vídeo de WhatsApp passa de 10 MB com
            // frequência.
            'max_bytes' => 20 * 1024 * 1024,

            // Mídias aproveitadas por mensagem. Cada uma é um download e uma
            // chamada de visão dentro da requisição que alguém está esperando.
            'max_por_mensagem' => 3,

            'timeout' => 20,

            // Sobrepõe a instrução da descrição da imagem. O padrão do pacote
            // serve a qualquer domínio; aqui se diz o que a SUA aplicação quer ver
            // descrito — o cômodo e o defeito, num sistema de assistência técnica.
            'instrucao_da_descricao' => null,

        ],

        // Silêncio maior que isto começa conversa nova. Histórico de horas atrás
        // confunde o modelo mais do que ajuda, e encarece cada resposta.
        'minutos_inatividade' => 30,

        // Ferramentas que ALTERAM dados neste canal. Deixe false para o canal
        // externo ficar somente-leitura sem desregistrar as ações, que continuam
        // valendo na tela. Com true, a alteração pede confirmação por texto.
        'acoes' => true,

        // Prazo da confirmação pendente, mais curto que o da conversa: um "sim"
        // solto tempo depois não pode autorizar alteração já esquecida.
        'minutos_confirmacao' => 5,

        // Só estas palavras aprovam, e o casamento é EXATO sobre o texto
        // normalizado (minúsculas, sem acento, sem pontuação). "sim" aprova;
        // "sim, pode cancelar" não. Casar por conteúdo faria "não, não confirmo"
        // conter "confirmo" e autorizar o oposto do pedido. Qualquer resposta que
        // não casa CANCELA a alteração — pendência viva esperaria um "sim" que
        // pode chegar em outro assunto.
        'palavras_confirmacao' => ['sim', 'confirmo', 'confirmar', 'autorizo'],

        // Acrescentado ao fim do system prompt, só nas conversas deste canal. Por
        // padrão desfaz a regra de tabela markdown, que o chat renderiza bem e o
        // WhatsApp não.
        'instrucoes' => 'Você está respondendo por um aplicativo de mensagens, não por uma tela: '
            .'não use tabela markdown, título nem bloco de código. Para listar, use linhas curtas '
            .'começando com hífen. Prefira respostas de até 4 linhas; se o assunto for longo, '
            .'responda o essencial e ofereça detalhar.',

    ],

    /*
    |--------------------------------------------------------------------------
    | Contexto do sistema
    |--------------------------------------------------------------------------
    |
    | Descreva aqui O QUE é a aplicação e o que ela controla. O pacote já
    | acrescenta por conta própria as regras invariantes (não inventar dados,
    | respeitar escopo, formatar em pt-BR, quando usar gráfico).
    |
    | Isto é o PADRÃO: a aba "Assistente" da engrenagem grava um contexto por
    | cima, e o gravado vence — como no modelo e na chave. Esvaziar o campo em
    | tela devolve o valor daqui.
    |
    */

    'contexto' => 'Você é o assistente interno desta aplicação Laravel.',

    /*
    |--------------------------------------------------------------------------
    | Glossário de negócio
    |--------------------------------------------------------------------------
    |
    | Uma regra por item. É aqui que mora o conhecimento que NÃO está no schema
    | e que o modelo não tem como adivinhar — o "aprendizado" do assistente é o
    | glossário crescer.
    |
    | Duas formas de escrever, e as duas valem. Por assunto (recomendada, porque
    | é como o glossário fica legível depois da vigésima regra):
    |
    |   'glossario' => [
    |       'OBRAS' => [
    |           'EMPREENDIMENTO e OBRA são a mesma coisa.',
    |           'obras.divisao é Prime ou Easy; obras.linha_negocio é a linha.',
    |       ],
    |       'PPC' => [
    |           'O "mês" do PPC é o período de medição, não o do calendário.',
    |       ],
    |   ],
    |
    | Ou em lista simples, como era antes dos assuntos existirem — continua
    | funcionando, e as regras entram todas no bloco "sem assunto":
    |
    |   'glossario' => [
    |       'users.obra_scoped vazio significa acesso a todas as obras.',
    |   ],
    |
    | O assunto não é enfeite de tela: ele vira o título do bloco no system
    | prompt. Numa lista corrida de dezenas de itens, a regra de PPC e a de
    | documentos chegam ao modelo com o mesmo peso e sem vizinhança, e ele perde
    | a pista de que "status" quer dizer coisas diferentes em cada assunto.
    |
    | Este array é o glossário de PARTIDA, para quem instala o pacote e para
    | quem prefere versionar as regras no git. A partir da PRIMEIRA regra
    | cadastrada na aba "Assistente" da engrenagem, ele deixa de valer inteiro:
    | quem manda passa a ser a tabela claudinho_glossario. A própria tela tem o
    | botão que importa o que estiver aqui, para não recadastrar nada à mão.
    |
    | Por que "deixa de valer inteiro" e não "soma": somar mandaria as duas
    | versões da mesma regra para o modelo assim que alguém corrigisse uma frase
    | em tela, e a regra velha seguiria no prompt sem ninguém saber por quê.
    |
    */

    'glossario' => [],

    /*
    |--------------------------------------------------------------------------
    | Ferramentas
    |--------------------------------------------------------------------------
    |
    | Classes que implementam Rogga\Claudinho\Contracts\Ferramenta. Cada uma
    | declara sua própria permissão e é exposta ao modelo somente se o usuário
    | puder usá-la.
    |
    | Consultas e ações vão no mesmo array. Consulta estende FerramentaBase;
    | ação (que altera dados) estende AcaoBase, exige gate declarado e pausa o
    | chat pedindo confirmação do usuário antes de executar. Ver o README.
    |
    */

    'ferramentas' => [
        // App\Claudinho\Ferramentas\BuscarFuncionario::class,
        // App\Claudinho\Acoes\CancelarPedido::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gráfico
    |--------------------------------------------------------------------------
    */

    'grafico' => [

        // Registra a ferramenta gerar_grafico, que desenha barras em SVG no servidor.
        'habilitado' => true,

        // Acima disso o gráfico deixa de ser legível dentro da bolha do chat.
        'max_series' => 12,

        // Hue única da série. Ao trocar, valide contra a superfície onde o
        // gráfico é renderizado (banda de luminosidade, chroma e contraste ≥ 3:1).
        'cor' => '#2a78d6',
    ],

];
