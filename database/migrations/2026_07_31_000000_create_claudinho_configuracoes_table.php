<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claudinho_configuracoes', function (Blueprint $tabela): void {
            $tabela->id();

            // Chave/valor em vez de uma coluna por parâmetro: acrescentar configuração
            // pela tela não deve exigir migration.
            $tabela->string('chave')->unique();

            // Texto porque o valor vai criptografado — o ciphertext é bem maior que
            // o conteúdo e não caberia num string(255).
            $tabela->text('valor')->nullable();

            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claudinho_configuracoes');
    }
};
