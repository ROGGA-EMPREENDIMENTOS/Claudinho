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

    /**
     * Grava por cima sem nunca ler o valor que está no banco.
     *
     * Omitir `valor` do select não é economia de coluna: com ele carregado, o
     * isDirty() de dentro do save() decifra o valor gravado para comparar com o
     * novo, e linha cifrada com outra APP_KEY estoura DecryptException ali —
     * antes de qualquer escrita. Sem o original em mãos o Eloquent conta o
     * atributo como sujo sem comparar nada, então a gravação passa.
     *
     * O framework tem um atalho para isso, mas só quando APP_PREVIOUS_KEYS está
     * configurado (HasAttributes::originalIsEquivalent), o que não cobre chave
     * antiga perdida. E regravar pela tela é justamente o caminho de recuperação
     * que todas() promete ao engolir o DecryptException na leitura: se a escrita
     * também não tolerar linha ilegível, não há como sair do estado quebrado.
     */
    public static function definir(string $chave, ?string $valor): void
    {
        $configuracao = static::query()
            ->select(['id', 'chave'])
            ->firstOrNew(['chave' => $chave]);

        $configuracao->valor = $valor;

        $configuracao->save();
    }
}
