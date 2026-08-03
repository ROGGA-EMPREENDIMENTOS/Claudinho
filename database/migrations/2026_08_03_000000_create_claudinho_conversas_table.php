<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claudinho_conversas', function (Blueprint $tabela): void {
            $tabela->id();

            // Canal + identificador em vez de só o telefone: o mesmo número pode
            // chegar por WhatsApp e por outro gateway, e são conversas distintas.
            $tabela->string('canal', 40);
            $tabela->string('identificador');

            // Sem foreign key: a tabela de usuários da aplicação não tem nome nem
            // chave garantidos, e o pacote não pode presumir. O resolver da
            // aplicação é quem responde por este id.
            $tabela->unsignedBigInteger('user_id');

            // JSON em claro, não criptografado. O histórico só contém dado que este
            // usuário já tinha permissão de ver, e vive no mesmo banco de onde
            // saiu — cifrar não muda quem alcança a tabela, e traria de volta a
            // fragilidade de rotação de APP_KEY que a v1.1.1 corrigiu.
            $tabela->json('estado');

            // Silêncio longo começa conversa nova: histórico de dias atrás confunde
            // o modelo mais do que ajuda, e encarece cada resposta.
            //
            // nullable() não é sobre permitir vazio: no MySQL, a PRIMEIRA coluna
            // TIMESTAMP declarada NOT NULL e sem default ganha, implicitamente,
            // "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" (a menos que
            // explicit_defaults_for_timestamp esteja ligado, e o padrão é desligado).
            // Com isso, qualquer UPDATE que não liste esta coluna reescrevia o
            // vencimento para agora — e a conversa expirava sozinha no próximo
            // acesso, perdendo até ação esperando confirmação. venceu() já trata
            // null como vencido, que é o lado seguro.
            $tabela->timestamp('expira_em')->nullable()->index();

            // Prazo da confirmação pendente, mais curto que o da conversa: um "sim"
            // solto meia hora depois não pode autorizar uma alteração que a pessoa
            // já esqueceu que pediu.
            $tabela->timestamp('confirmar_ate')->nullable();

            $tabela->timestamps();

            $tabela->unique(['canal', 'identificador']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claudinho_conversas');
    }
};
