<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Rogga\Claudinho\Models\Configuracao;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica o CHAMADOR (o gateway de WhatsApp, o n8n, o que for) por token
 * compartilhado. Não tem nada a ver com o usuário representado na conversa — quem
 * responde por isso é o ResolvedorDeUsuario.
 *
 * São duas camadas de propósito: o token diz "esta requisição vem do nosso
 * gateway", o resolver diz "e ela fala em nome desta pessoa". Um token vazado dá
 * acesso ao endpoint, não aos dados de todos: o escopo continua sendo o do usuário
 * que o resolver devolver para aquele identificador.
 */
class AutenticaCanal
{
    public function handle(Request $request, Closure $next): Response
    {
        // Gravado em tela vence o config, como em todo o resto do pacote. É o que
        // permite ligar o endpoint sem tocar no .env.
        $esperado = (string) Configuracao::valor('api_token', config('claudinho.api.token'));

        // Token em branco não libera: seria um endpoint aberto por esquecimento de
        // configurar, que é exatamente o acidente a evitar.
        if ($esperado === '') {
            abort(503, 'Endpoint do Claudinho sem token configurado.');
        }

        $recebido = (string) ($request->bearerToken() ?? '');

        // hash_equals para a comparação não vazar o tamanho nem o prefixo do token
        // pelo tempo de resposta.
        abort_unless($recebido !== '' && hash_equals($esperado, $recebido), 401, 'Token inválido.');

        // O interruptor da tela. Checado aqui, e não no boot do provider: o boot roda
        // em TODA requisição da aplicação, e ler o banco lá custaria consulta em
        // página que nunca fala com o Claudinho. Aqui só custa quando alguém bate no
        // endpoint.
        //
        // Depois do token de propósito: assim quem não se autenticou recebe 401
        // esteja o endpoint ligado ou desligado, e não consegue usar a resposta para
        // descobrir se existe endpoint neste ambiente.
        abort_unless(
            Configuracao::booleano('api_ativa', (bool) config('claudinho.api.habilitado', false)),
            503,
            'O atendimento por este canal está desligado nas configurações do Claudinho.'
        );

        return $next($request);
    }
}
