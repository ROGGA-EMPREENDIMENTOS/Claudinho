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
        'claude-haiku-4-5' => 'Haiku 4.5 — mais rápido e barato, para perguntas simples',
    ],

    'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 16000),

    // low | medium | high | xhigh | max — quanto o modelo raciocina antes de responder.
    // medium é o padrão por latência: o chat é síncrono.
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
    | Desligado por padrão: rota que passa a existir só por ter atualizado o
    | pacote seria surpresa de segurança.
    |
    |   POST /claudinho/conversa           {canal, identificador, mensagem}
    |   POST /claudinho/conversa/reiniciar {canal, identificador}
    |
    | Exige `php artisan migrate` (tabela claudinho_conversas) e um resolvedor.
    |
    */

    'api' => [

        'habilitado' => env('CLAUDINHO_API', false),

        // Autentica o CHAMADOR (o gateway), não o usuário da conversa. Sem token
        // o endpoint responde 503 em vez de ficar aberto por esquecimento.
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
    */

    'contexto' => 'Você é o assistente interno desta aplicação Laravel.',

    /*
    |--------------------------------------------------------------------------
    | Glossário de negócio
    |--------------------------------------------------------------------------
    |
    | Uma regra por item. É aqui que mora o conhecimento que NÃO está no schema
    | e que o modelo não tem como adivinhar — o mecanismo de "aprendizado" do
    | assistente é este arquivo crescer. Exemplos reais:
    |
    |   'funcionarios.is_obra guarda o id da obra em que a pessoa está
    |    trabalhando naquele momento; é foto do instante, não lotação do período.',
    |   'users.obra_scoped vazio significa acesso a todas as obras.',
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
