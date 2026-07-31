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
    | Conversa
    |--------------------------------------------------------------------------
    */

    // Mensagens do histórico enviadas à API. Mais que isso encarece sem ganho.
    'limite_historico' => 20,

    // Voltas máximas do loop de tool use numa mesma pergunta.
    'max_iteracoes' => 5,

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
    */

    'ferramentas' => [
        // App\Claudinho\Ferramentas\BuscarFuncionario::class,
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
