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

    'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

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
