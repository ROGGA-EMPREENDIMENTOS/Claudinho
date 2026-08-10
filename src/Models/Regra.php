<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use PDOException;

/**
 * Uma regra do glossário de negócio, cadastrada em tela.
 *
 * O glossário é o conhecimento que não está no schema e que o modelo não tem como
 * adivinhar — o mecanismo de aprendizado do assistente é ele crescer. Enquanto morou
 * só no config, cada correção custava um deploy, e quem sabe a regra (quem opera) não
 * é quem faz o deploy.
 *
 * Aqui não há criptografia, ao contrário de {@see Configuracao}: glossário não é
 * segredo, é documentação — e cifrar tiraria a possibilidade de procurar por texto.
 *
 * @property int $id
 * @property string $regra
 * @property string|null $tema
 * @property bool $ativo
 * @property string|null $autor
 */
class Regra extends Model
{
    protected $table = 'claudinho_glossario';

    protected $fillable = ['regra', 'tema', 'ativo', 'autor'];

    protected $casts = ['ativo' => 'boolean'];

    /**
     * Memória da requisição, pelo mesmo motivo da Configuracao: o systemPrompt() é
     * montado uma vez por resposta, mas a tela lê a lista várias vezes por render.
     * Não é cache store — nada sobrevive ao fim do processo.
     *
     * @var Collection<int, self>|null
     */
    private static ?Collection $memoria = null;

    /**
     * O glossário que vai para o system prompt, agrupado por tema.
     *
     * A precedência é a mesma do resto do pacote (tela vence config), com um detalhe:
     * o critério é a tabela ter ao menos UMA regra, não ter regras ativas. Desativar
     * todas é uma decisão de quem administra, e cair no config nesse momento
     * ressuscitaria justamente o glossário que a pessoa acabou de silenciar.
     *
     * A chave é o tema; regra sem tema vai na chave vazia, que sai PRIMEIRO — é o
     * bloco geral, e enfiá-lo no fim depois dos assuntos o transformaria num apêndice.
     *
     * @return array<string, array<int, string>>
     */
    public static function glossario(): array
    {
        $cadastradas = static::todas();

        $regras = $cadastradas->isEmpty()
            ? static::doConfig()
            : $cadastradas
                ->filter(fn (self $regra): bool => $regra->ativo)
                ->map(fn (self $regra): array => ['tema' => $regra->tema, 'regra' => trim($regra->regra)])
                ->values()
                ->all();

        return static::agrupar($regras);
    }

    /**
     * @param  array<int, array{tema: string|null, regra: string}>  $regras
     * @return array<string, array<int, string>>
     */
    private static function agrupar(array $regras): array
    {
        $grupos = [];

        foreach ($regras as $regra) {
            $grupos[(string) $regra['tema']][] = $regra['regra'];
        }

        // Sem tema primeiro; os demais na ordem em que apareceram, que é a ordem de
        // cadastro. Ordenar por nome trocaria a organização de quem cadastrou por uma
        // alfabética que não quer dizer nada.
        if (array_key_exists('', $grupos)) {
            $grupos = ['' => $grupos['']] + $grupos;
        }

        return $grupos;
    }

    /**
     * Um tema é sempre maiúsculo e sem espaço sobrando: "Obras", "obras " e "OBRAS"
     * são o mesmo assunto, e sem isto virariam três grupos na tela e três blocos no
     * prompt. Vazio vira null, que é como "sem tema" se guarda.
     */
    public static function normalizarTema(?string $tema): ?string
    {
        $tema = mb_strtoupper(trim((string) $tema));

        return $tema === '' ? null : $tema;
    }

    /**
     * Os temas em uso e quantas regras cada um tem, para os filtros da tela.
     *
     * @return array<string, int>
     */
    public static function temas(): array
    {
        $temas = [];

        foreach (static::todas() as $regra) {
            $tema = (string) $regra->tema;

            $temas[$tema] = ($temas[$tema] ?? 0) + 1;
        }

        return $temas;
    }

    /**
     * Todas as regras cadastradas, ativas ou não, na ordem em que foram criadas.
     *
     * Nada aqui pode derrubar o chat: sem migration, sem driver de banco ou sem
     * conexão, o pacote continua funcionando pelo config.
     *
     * @return Collection<int, self>
     */
    public static function todas(): Collection
    {
        if (static::$memoria !== null) {
            return static::$memoria;
        }

        return static::$memoria = static::consultar();
    }

    /**
     * @return Collection<int, self>
     */
    private static function consultar(): Collection
    {
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return new Collection;
            }

            return static::query()->orderBy('id')->get();
        } catch (PDOException|QueryException) {
            return new Collection;
        }
    }

    /**
     * O glossário do config, que é o padrão de fábrica de quem nunca abriu a tela.
     *
     * Aceita as duas formas de escrever o array, porque as duas são legítimas:
     *
     *   'glossario' => ['regra', 'outra regra']              // lista simples
     *   'glossario' => ['OBRAS' => ['regra'], 'PPC' => [...]] // por assunto
     *
     * Misturar as duas também funciona: chave numérica com string é regra sem tema.
     * Quem escreveu o config antes dos temas não precisa mexer em nada.
     *
     * @return array<int, array{tema: string|null, regra: string}>
     */
    public static function doConfig(): array
    {
        $regras = [];

        foreach ((array) config('claudinho.glossario', []) as $chave => $valor) {
            $tema = is_string($chave) ? static::normalizarTema($chave) : null;

            foreach (is_array($valor) ? $valor : [$valor] as $regra) {
                $regra = trim((string) $regra);

                if ($regra !== '') {
                    $regras[] = ['tema' => $tema, 'regra' => $regra];
                }
            }
        }

        return $regras;
    }

    /**
     * Copia o glossário do config para a tabela, pulando o que já está lá.
     *
     * É o caminho de mudança: quem já tem dezenas de regras no arquivo não vai
     * recadastrá-las à mão, e sem isto a tela nasceria vazia justamente para quem mais
     * usa o glossário. Depois da importação o config vira histórico — pode ser
     * esvaziado no próximo deploy, sem pressa, porque a tabela já venceu.
     *
     * O tema vem junto: organizar o arquivo em assuntos antes de importar poupa
     * classificar dezenas de regras uma a uma na tela depois.
     *
     * @return int quantas foram importadas
     */
    public static function importarDoConfig(?string $autor = null): int
    {
        $existentes = static::todas()
            ->map(fn (self $regra): string => trim($regra->regra))
            ->all();

        $importadas = 0;

        foreach (static::doConfig() as $regra) {
            // A comparação é só pelo texto: a mesma regra reclassificada em outro tema
            // continua sendo a mesma regra, e reimportá-la criaria uma cópia.
            if (in_array($regra['regra'], $existentes, true)) {
                continue;
            }

            static::create([
                'regra' => $regra['regra'],
                'tema' => $regra['tema'],
                'ativo' => true,
                'autor' => $autor,
            ]);

            $existentes[] = $regra['regra'];
            $importadas++;
        }

        static::esquecer();

        return $importadas;
    }

    /**
     * Descarta a memória da requisição. Toda escrita passa por aqui.
     */
    public static function esquecer(): void
    {
        static::$memoria = null;
    }

    /**
     * Toda escrita invalida a memória, inclusive a feita direto pelo model — a tela
     * lê a lista logo depois de gravar, e uma lista velha ali é o tipo de bug que
     * faz quem cadastrou achar que perdeu o texto.
     */
    protected static function booted(): void
    {
        static::saved(static function (): void {
            static::esquecer();
        });

        static::deleted(static function (): void {
            static::esquecer();
        });
    }
}
