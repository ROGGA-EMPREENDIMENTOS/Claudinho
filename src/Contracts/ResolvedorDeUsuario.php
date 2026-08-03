<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Diz qual usuário da aplicação está do outro lado de um canal externo.
 *
 * É o ponto mais importante do endpoint. Todo o modelo de permissão do pacote sai
 * de Auth::user(): as ferramentas checam gates, os Global Scopes filtram por obra,
 * o system prompt usa o nome. O endpoint autentica como o usuário que este
 * resolver devolver e, dali em diante, tudo funciona igual ao chat em tela.
 *
 * Só a aplicação sabe mapear telefone (ou id de canal) para usuário — o pacote não
 * tem como adivinhar em que coluna isso mora. Por isso é contrato, e não config
 * com closure: closure não sobrevive a `config:cache`.
 *
 * Devolver null é a resposta correta para número desconhecido: o endpoint responde
 * 403 e nada é consultado. Nunca devolva um usuário "genérico" como fallback — o
 * filtro por obra deixaria de significar qualquer coisa.
 */
interface ResolvedorDeUsuario
{
    /**
     * @param  string  $canal  De onde a mensagem veio ('whatsapp', por exemplo).
     * @param  string  $identificador  Quem mandou, no formato do canal.
     */
    public function resolver(string $canal, string $identificador): ?Authenticatable;
}
