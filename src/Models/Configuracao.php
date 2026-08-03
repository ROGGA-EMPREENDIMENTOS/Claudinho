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
    /**
     * Memória da requisição. Não é cache store — é justamente o que o docblock de
     * todas() recusa —, é só não repetir a mesma consulta dentro de uma requisição:
     * o Claude lê duas chaves por resposta, e a tela agora lê mais três. Morre com
     * o processo, e definir() a invalida.
     *
     * @var array<string, string|null>|null
     */
    private static ?array $memoria = null;

    public static function todas(): array
    {
        if (static::$memoria !== null) {
            return static::$memoria;
        }

        return static::$memoria = static::consultar();
    }

    /**
     * @return array<string, string|null>
     */
    private static function consultar(): array
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

        static::esquecer();
    }

    /**
     * Descarta a memória da requisição. Chamado por definir(); exposto porque teste
     * que grava direto na tabela precisa avisar.
     */
    public static function esquecer(): void
    {
        static::$memoria = null;
    }

    /**
     * Liga/desliga gravado em tela, com o config como padrão.
     *
     * Guardado como '1'/'0' porque a coluna é texto: sem isto, "false" (string)
     * seria verdadeiro, que é o erro clássico de flag em tabela chave/valor.
     */
    public static function booleano(string $chave, bool $padrao): bool
    {
        $valor = static::todas()[$chave] ?? null;

        return $valor === null || $valor === '' ? $padrao : $valor === '1';
    }

    public static function definirBooleano(string $chave, bool $valor): void
    {
        static::definir($chave, $valor ? '1' : '0');
    }
}
