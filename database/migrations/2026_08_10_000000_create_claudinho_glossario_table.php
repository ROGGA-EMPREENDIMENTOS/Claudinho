<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claudinho_glossario', function (Blueprint $tabela): void {
            $tabela->id();

            // Uma linha por regra, e não um textão único: é o que permite desativar,
            // datar e atribuir autoria a cada uma separadamente. O glossário de uma
            // aplicação madura passa das dezenas, e nesse tamanho um campo só vira
            // arquivo de texto editado por várias pessoas ao mesmo tempo.
            $tabela->text('regra');

            // O assunto da regra (OBRAS, PPC, DOCUMENTOS...). Texto livre e não tabela
            // de temas: são poucos, mudam junto com o vocabulário da empresa, e uma
            // tabela de apoio só acrescentaria uma tela de cadastro para manter. Vem
            // maiúsculo do model, senão "Obras" e "obras" viram dois grupos.
            $tabela->string('tema')->nullable()->index();

            // Desativar em vez de apagar: regra que atrapalhou a resposta sai do prompt
            // na hora, mas continua legível para quem for reescrevê-la depois.
            $tabela->boolean('ativo')->default(true);

            // Nome, não id de usuário: o pacote não conhece o model de usuário da
            // aplicação e não vai criar chave estrangeira para uma tabela que pode nem
            // se chamar users. Quem escreveu importa para conversar sobre a regra, não
            // para juntar dados.
            $tabela->string('autor')->nullable();

            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claudinho_glossario');
    }
};
