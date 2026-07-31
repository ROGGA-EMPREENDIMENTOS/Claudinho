<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use PDOException;

/**
 * Configuração definida em tela, que se sobrepõe ao config/env. O valor vai
 * criptografado com a APP_KEY porque a tabela guarda a chave da API.
 *
 * @property string $chave
 * @property string|null $valor
 */
class Configuracao extends Model
{
    protected $table = 'claudinho_configuracoes';

    protected $fillable = ['chave', 'valor'];

    protected $casts = ['valor' => 'encrypted'];

    /**
     * De propósito sem cache: o valor decifrado da chave da API não deve ir para
     * o cache store, que é menos protegido que o banco. São duas queries leves
     * por resposta do chat, não por render.
     *
     * Nada aqui pode derrubar o chat: sem migration, sem driver de banco ou sem
     * conexão, o pacote precisa continuar funcionando pelo config/env.
     *
     * @return array<string, string|null>
     */
    public static function todas(): array
    {
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return [];
            }

            // all() e não pluck(): pluck devolve o valor cru do banco, sem passar
            // pelo cast, o que entregaria o ciphertext.
            return static::all()
                ->mapWithKeys(fn (self $configuracao): array => [
                    $configuracao->chave => $configuracao->valorDecifrado(),
                ])
                ->all();
        } catch (PDOException|QueryException) {
            return [];
        }
    }

    /**
     * APP_KEY rotacionada deixa o valor gravado indecifrável. Tratar como ausente
     * faz o sistema voltar ao env e permite regravar pela tela, em vez de derrubar
     * o chat com um erro de criptografia.
     */
    private function valorDecifrado(): ?string
    {
        try {
            return $this->valor;
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Valor ausente e valor vazio são a mesma coisa aqui: os dois caem no padrão.
     * É o que permite limpar a chave em tela e voltar a usar a do env.
     */
    public static function valor(string $chave, ?string $padrao = null): ?string
    {
        $valor = static::todas()[$chave] ?? null;

        return filled($valor) ? $valor : $padrao;
    }

    public static function definir(string $chave, ?string $valor): void
    {
        static::query()->updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }
}
