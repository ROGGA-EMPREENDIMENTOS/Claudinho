<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rogga\Claudinho\Canal;
use Rogga\Claudinho\Confirmacao;
use Rogga\Claudinho\Midia\Recebedor;
use Rogga\Claudinho\Models\Configuracao;
use Rogga\Claudinho\Models\Regra;
use Rogga\Claudinho\Transcricao;
use Throwable;

/**
 * Cadastro das configurações que mudam sem deploy. Componente separado do Chat de
 * propósito: o Livewire serializa as propriedades públicas em toda requisição, e
 * estado de administração não deve viajar junto de cada mensagem do chat.
 */
class Configuracoes extends Component
{
    /**
     * Id do Chat que abriu este modal.
     *
     * Existe porque a mesma página pode ter mais de um chat — o botão flutuante no
     * layout global e o card numa tela dedicada, por exemplo. Cada um renderiza o
     * seu modal, e sem isto uma clicada na engrenagem abria TODOS: o primeiro X
     * fechava o de cima e revelava o de baixo, dando a impressão de precisar clicar
     * duas vezes.
     *
     * Vazio significa "atende qualquer chamada", para quem monte este componente
     * sozinho, fora de um chat.
     */
    #[Locked]
    public string $dono = '';

    /**
     * Aba visível. Fica no servidor, e não no Alpine, porque toda ação do glossário
     * volta ao servidor: com a aba só no cliente, salvar uma regra devolveria o
     * usuário para a primeira aba a cada clique.
     */
    public string $aba = 'assistente';

    /** Quem é o assistente nesta aplicação. Cabeçalho do system prompt. */
    public string $contexto = '';

    /** Filtro da lista de regras — glossário maduro passa das dezenas. */
    public string $busca = '';

    /**
     * Assunto escolhido. Três estados, e os três significam coisas diferentes:
     *
     *   null  nenhum escolhido — a tela mostra só o índice de assuntos
     *   ''    o grupo "Sem assunto", que é a fila do que falta classificar
     *   'PPC' aquele assunto
     *
     * O índice é o padrão porque é assim que a pergunta nasce ("mostre o glossário de
     * PPC"), e porque abrir as dezenas de regras de uma vez manda ~200 KB de HTML em
     * TODA ação do Livewire — inclusive nas que não têm nada a ver com a lista.
     */
    public ?string $tema = null;

    public string $regraNova = '';

    public string $temaNovo = '';

    /** Id da regra aberta para edição; nenhuma quando null. */
    public ?int $emEdicao = null;

    public string $textoEmEdicao = '';

    public string $temaEmEdicao = '';

    public string $modelo = '';

    /**
     * Só escrita. A chave gravada nunca é carregada aqui — propriedade pública do
     * Livewire vai para o HTML e volta em cada requisição, e o segredo não pode
     * fazer esse caminho. Para exibir, existe o chaveEmUso(), que só devolve máscara.
     */
    public string $chaveNova = '';

    /** O botão flutuante aparece? Só tem efeito onde a aplicação colocou o componente. */
    public bool $flutuante = true;

    /** O endpoint aceita requisições? */
    public bool $api = false;

    /**
     * As regras do canal externo. Vazio significa "usa o config" — igual à chave e
     * ao contexto —, e por isso guardam o que está GRAVADO em tela, não o valor em
     * uso: prefilhados com o valor do arquivo, o primeiro Salvar congelaria no banco
     * o que o config diz hoje, e um deploy que mudasse o arquivo deixaria de ter
     * efeito sem ninguém entender por quê.
     */
    public string $minutosInatividade = '';

    public string $minutosConfirmacao = '';

    public string $palavras = '';

    public string $instrucoes = '';

    public string $hostsDeMidia = '';

    /** As ferramentas que alteram dados valem no canal externo? */
    public bool $acoes = true;

    /** O áudio que chega pelo canal externo vira texto pela API do Google? */
    public bool $transcricao = false;

    /**
     * Só escrita, pelo mesmo motivo da chave do Claude: propriedade pública do
     * Livewire vai para o HTML e volta em cada requisição, e segredo não pode fazer
     * esse caminho. Para exibir existe o chaveDeTranscricaoEmUso(), que só devolve
     * máscara.
     */
    public string $chaveDeTranscricaoNova = '';

    /**
     * Token recém-gerado, mostrado UMA vez.
     *
     * Ao contrário da chave do Claude, este segredo precisa ser lido: quem opera
     * tem de copiá-lo para o gateway. Some na próxima interação, e depois só resta
     * a máscara — perdeu, gera outro.
     */
    public string $tokenGerado = '';

    public function mount(): void
    {
        $this->autoriza();

        $this->modelo = (string) Configuracao::valor('model', config('claudinho.model'));
        $this->flutuante = Configuracao::booleano('flutuante_ativo', (bool) config('claudinho.flutuante.ativo', true));
        $this->api = Configuracao::booleano('api_ativa', (bool) config('claudinho.api.habilitado', false));
        $this->contexto = $this->contextoEmUso();

        $this->acoes = Canal::acoes();
        $this->minutosInatividade = (string) Configuracao::valor('api_minutos_inatividade', '');
        $this->minutosConfirmacao = (string) Configuracao::valor('api_minutos_confirmacao', '');
        $this->palavras = (string) Configuracao::valor('api_palavras_confirmacao', '');
        $this->instrucoes = (string) Configuracao::valor('api_instrucoes', '');
        $this->hostsDeMidia = (string) Configuracao::valor('api_midias_hosts', '');

        $this->transcricao = Transcricao::habilitada();
    }

    /**
     * Gera o token do chamador. Gerado e não digitado: é segredo de máquina, e
     * senha escolhida por gente aqui seria fraca sem necessidade.
     */
    public function gerarToken(): void
    {
        $this->autoriza();

        $this->tokenGerado = 'clau_'.Str::random(48);

        Configuracao::definir('api_token', $this->tokenGerado);

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    public function revogarToken(): void
    {
        $this->autoriza();

        Configuracao::definir('api_token', null);

        $this->tokenGerado = '';

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * De onde o token do chamador vem, sem revelá-lo.
     *
     * @return array{origem: 'tela'|'env'|'ausente', dica: string|null}
     */
    public function tokenEmUso(): array
    {
        $daTela = Configuracao::valor('api_token');

        if (filled($daTela)) {
            return ['origem' => 'tela', 'dica' => $this->mascara($daTela)];
        }

        $doEnv = config('claudinho.api.token');

        if (filled($doEnv)) {
            return ['origem' => 'env', 'dica' => $this->mascara((string) $doEnv)];
        }

        return ['origem' => 'ausente', 'dica' => null];
    }

    public function render()
    {
        return view('claudinho::livewire.configuracoes');
    }

    /**
     * As regras que a lista mostra, já filtradas pela busca e pelo assunto.
     *
     * Filtro em PHP e não em SQL: são dezenas de linhas, já carregadas na memória da
     * requisição, e uma consulta por tecla digitada seria pior em tudo. `mb_stripos`
     * porque glossário é texto em português — buscar "obra" tem de achar "Obra".
     *
     * @return array<int, Regra>
     */
    public function regras(): array
    {
        $termo = trim($this->busca);

        return Regra::todas()
            ->filter(fn (Regra $regra): bool => $termo === '' || mb_stripos($regra->regra, $termo) !== false)
            ->filter(fn (Regra $regra): bool => $this->tema === null || (string) $regra->tema === $this->tema)
            ->values()
            ->all();
    }

    /**
     * A tela mostra as regras, ou só o índice de assuntos?
     *
     * Índice só faz sentido quando há assunto para indexar: com o glossário ainda
     * pequeno, ou todo ele sem assunto, um índice de um item só seria um clique a
     * mais para chegar ao mesmo lugar. Busca preenchida também abre a lista — quem
     * está procurando não sabe em qual assunto está o que procura.
     */
    public function mostrandoRegras(): bool
    {
        return $this->tema !== null
            || filled(trim($this->busca))
            || count($this->temas()) <= 1;
    }

    /**
     * As regras da lista agrupadas por assunto, que é como a tela desenha.
     *
     * Sem tema vai na chave vazia e sai primeiro, igual ao prompt: é o bloco geral, e
     * jogá-lo para o fim depois dos assuntos o transformaria num apêndice.
     *
     * @return array<string, array<int, Regra>>
     */
    public function regrasPorTema(): array
    {
        $grupos = [];

        foreach ($this->regras() as $regra) {
            $grupos[(string) $regra->tema][] = $regra;
        }

        if (array_key_exists('', $grupos)) {
            $grupos = ['' => $grupos['']] + $grupos;
        }

        return $grupos;
    }

    /**
     * Os assuntos existentes e quantas regras cada um tem, para os botões de filtro e
     * para o datalist do cadastro. O datalist é o que segura a proliferação: sem a
     * lista à mão, "OBRA" e "OBRAS" viram dois assuntos na primeira semana.
     *
     * @return array<string, int>
     */
    public function temas(): array
    {
        return Regra::temas();
    }

    /**
     * De onde o glossário em uso está vindo e o que existe dos dois lados.
     *
     * A tela precisa dizer isso em voz alta: com regras cadastradas, o config para de
     * valer inteiro — e quem escreveu aquele arquivo merece saber disso antes de
     * procurar por que a regra dele sumiu do prompt.
     *
     * @return array{origem: 'tela'|'config'|'ausente', cadastradas: int, ativas: int, no_config: int, importaveis: int}
     */
    public function glossarioEmUso(): array
    {
        $cadastradas = Regra::todas();
        $noConfig = Regra::doConfig();

        $jaCadastradas = $cadastradas->map(fn (Regra $regra): string => trim($regra->regra))->all();
        $importaveis = array_filter(
            $noConfig,
            fn (array $regra): bool => ! in_array($regra['regra'], $jaCadastradas, true)
        );

        return [
            'origem' => match (true) {
                $cadastradas->isNotEmpty() => 'tela',
                $noConfig !== [] => 'config',
                default => 'ausente',
            },
            'cadastradas' => $cadastradas->count(),
            'ativas' => $cadastradas->filter(fn (Regra $regra): bool => $regra->ativo)->count(),
            'no_config' => count($noConfig),
            'importaveis' => count($importaveis),
        ];
    }

    public function adicionarRegra(): void
    {
        $this->autoriza();

        $this->validate([
            // Mínimo de 10: regra de negócio não cabe em uma palavra, e linha solta no
            // system prompt custa token em toda pergunta sem ensinar nada.
            'regraNova' => ['required', 'string', 'min:10', 'max:2000'],
            'temaNovo' => ['nullable', 'string', 'max:60'],
        ], attributes: ['regraNova' => 'regra', 'temaNovo' => 'assunto']);

        $tema = Regra::normalizarTema($this->temaNovo);

        Regra::create([
            'regra' => trim($this->regraNova),
            'tema' => $tema,
            'ativo' => true,
            'autor' => Auth::user()?->name,
        ]);

        $this->regraNova = '';
        // O assunto NÃO é limpo: cadastrar glossário é cadastrar em lote, uma regra de
        // PPC atrás da outra, e reescrever o tema a cada linha seria trabalho à toa.
        $this->temaNovo = (string) $tema;
        // Regra recém-criada não aparece se a busca de antes não a alcança, e some sem
        // explicação nenhuma para quem acabou de escrevê-la. O assunto vai junto: a
        // tela pula para o grupo onde a regra caiu, que é onde se quer conferir se
        // ela ficou boa.
        $this->busca = '';
        $this->tema = (string) $tema;

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    public function editarRegra(int $id): void
    {
        $this->autoriza();

        $regra = Regra::query()->find($id);

        if (! $regra instanceof Regra) {
            return;
        }

        $this->emEdicao = $id;
        $this->textoEmEdicao = $regra->regra;
        $this->temaEmEdicao = (string) $regra->tema;
    }

    public function cancelarEdicao(): void
    {
        $this->emEdicao = null;
        $this->textoEmEdicao = '';
        $this->temaEmEdicao = '';
        $this->resetValidation();
    }

    public function salvarRegra(): void
    {
        $this->autoriza();

        $this->validate([
            'textoEmEdicao' => ['required', 'string', 'min:10', 'max:2000'],
            'temaEmEdicao' => ['nullable', 'string', 'max:60'],
        ], attributes: ['textoEmEdicao' => 'regra', 'temaEmEdicao' => 'assunto']);

        $regra = Regra::query()->find($this->emEdicao);

        if (! $regra instanceof Regra) {
            // Apagada em outra aba enquanto esta estava aberta. Recriar seria
            // ressuscitar o que alguém decidiu tirar do prompt.
            $this->cancelarEdicao();

            return;
        }

        $regra->regra = trim($this->textoEmEdicao);
        $regra->tema = Regra::normalizarTema($this->temaEmEdicao);
        $regra->autor = Auth::user()?->name;
        $regra->save();

        // Reclassificar tiraria a regra da lista no instante em que ela foi salva; a
        // tela acompanha para onde ela foi.
        if ($this->tema !== null) {
            $this->tema = (string) $regra->tema;
        }

        $this->cancelarEdicao();

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * Desativar não apaga: a regra sai do prompt na hora e continua legível para quem
     * for reescrevê-la. É o que se faz quando uma regra piorou a resposta e ainda não
     * se sabe qual é a redação certa.
     */
    public function alternarRegra(int $id): void
    {
        $this->autoriza();

        $regra = Regra::query()->find($id);

        if (! $regra instanceof Regra) {
            return;
        }

        $regra->ativo = ! $regra->ativo;
        $regra->save();

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    public function removerRegra(int $id): void
    {
        $this->autoriza();

        Regra::query()->find($id)?->delete();

        if ($this->emEdicao === $id) {
            $this->cancelarEdicao();
        }

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * Traz para a tabela o que está no arquivo. Sem isto, quem já tem um glossário
     * grande no config começaria com a tela vazia — e é justamente quem mais usa o
     * glossário. Pula o que já está cadastrado, então clicar duas vezes não duplica.
     */
    public function importarDoConfig(): void
    {
        $this->autoriza();

        Regra::importarDoConfig(Auth::user()?->name);

        $this->busca = '';
        // Volta ao índice: importar traz dezenas de regras de uma vez, e a primeira
        // coisa a fazer com elas é ver em que assuntos caíram.
        $this->tema = null;

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * O contexto que está valendo: o gravado em tela, ou o do config como padrão.
     */
    public function contextoEmUso(): string
    {
        return (string) Configuracao::valor('contexto', (string) config('claudinho.contexto', ''));
    }

    public function contextoVemDaTela(): bool
    {
        return filled(Configuracao::valor('contexto'));
    }

    public function salvar(): void
    {
        // mount() roda uma vez; cada ação precisa revalidar por conta própria.
        $this->autoriza();

        $regras = [
            'modelo' => ['required', 'string', 'max:100'],
            // Sem validar o prefixo sk-ant-: quem usa gateway ou proxy tem chave
            // de outro formato e não deve ficar travado aqui.
            'chaveNova' => ['nullable', 'string', 'min:20', 'max:200'],
            // Nullable de propósito: esvaziar é como se volta ao texto do config,
            // igual ao Limpar da chave.
            'contexto' => ['nullable', 'string', 'max:4000'],
            'palavras' => ['nullable', 'string', 'max:500'],
            'instrucoes' => ['nullable', 'string', 'max:2000'],
            'hostsDeMidia' => ['nullable', 'string', 'max:1000'],
            // Sem validar o prefixo AIza: chave restrita por proxy da própria
            // empresa não tem esse formato, e travar aqui não protegeria nada —
            // chave errada só aparece na primeira transcrição, como erro do Google.
            'chaveDeTranscricaoNova' => ['nullable', 'string', 'min:20', 'max:200'],
        ];

        // Os minutos só entram na validação quando têm algo escrito. Não é o mesmo
        // que 'nullable': campo de número vazio chega como string vazia, que não é
        // null, e a regra 'integer' reprovaria justamente quem está limpando o campo
        // para voltar ao config.
        foreach (['minutosInatividade' => 1440, 'minutosConfirmacao' => 1440] as $campo => $teto) {
            $this->{$campo} = trim($this->{$campo});

            if ($this->{$campo} !== '') {
                $regras[$campo] = ['integer', 'min:1', 'max:'.$teto];
            }
        }

        $this->validate($regras, attributes: [
            'modelo' => 'modelo',
            'chaveNova' => 'chave da API',
            'contexto' => 'contexto',
            'minutosInatividade' => 'tempo de inatividade',
            'minutosConfirmacao' => 'prazo da confirmação',
            'palavras' => 'palavras de confirmação',
            'instrucoes' => 'instruções do canal',
            'hostsDeMidia' => 'hosts de mídia',
            'chaveDeTranscricaoNova' => 'chave do Google Speech-to-Text',
        ]);

        Configuracao::definir('model', $this->modelo);
        Configuracao::definirBooleano('flutuante_ativo', $this->flutuante);
        Configuracao::definirBooleano('api_ativa', $this->api);
        Configuracao::definir('contexto', trim($this->contexto));

        Configuracao::definirBooleano('api_acoes', $this->acoes);
        Configuracao::definir('api_minutos_inatividade', $this->minutosInatividade);
        Configuracao::definir('api_minutos_confirmacao', $this->minutosConfirmacao);
        Configuracao::definir('api_instrucoes', trim($this->instrucoes));

        // Guardado já separado por linha, e o host só com o host: o que fica gravado
        // é exatamente o que o Confirmacao e o Recebedor vão comparar depois. Quem
        // colou a URI assinada inteira vê o campo voltar com o domínio sozinho, em
        // vez de descobrir pelo log que a mídia foi recusada.
        $this->palavras = Canal::texto(Canal::lista($this->palavras));
        $this->hostsDeMidia = Canal::texto(array_map(
            fn (string $host): string => Canal::host($host),
            Canal::lista($this->hostsDeMidia)
        ));

        Configuracao::definir('api_palavras_confirmacao', $this->palavras);
        Configuracao::definir('api_midias_hosts', $this->hostsDeMidia);

        Configuracao::definirBooleano('transcricao_habilitada', $this->transcricao);

        if (filled($this->chaveNova)) {
            Configuracao::definir('api_key', trim($this->chaveNova));
        }

        // Igual à do Claude: campo em branco mantém a que está gravada, senão
        // salvar qualquer outra configuração apagaria a chave sem querer.
        if (filled($this->chaveDeTranscricaoNova)) {
            Configuracao::definir('transcricao_chave', trim($this->chaveDeTranscricaoNova));
        }

        // Relê em vez de manter o que foi digitado: quem esvaziou o campo precisa ver
        // o texto do config voltar, senão a tela afirma um contexto vazio que não é o
        // que o assistente está usando.
        $this->contexto = $this->contextoEmUso();

        $this->chaveNova = '';
        $this->chaveDeTranscricaoNova = '';
        // O token só aparece na resposta em que foi gerado.
        $this->tokenGerado = '';

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * Limpar não apaga o registro: grava vazio, e valor vazio cai no env de novo.
     */
    public function limparChave(): void
    {
        $this->autoriza();

        Configuracao::definir('api_key', null);

        $this->chaveNova = '';

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * De onde a chave em uso está vindo, sem revelar a chave.
     *
     * @return array{origem: 'tela'|'env'|'ausente', dica: string|null}
     */
    public function chaveEmUso(): array
    {
        $daTela = Configuracao::valor('api_key');

        if (filled($daTela)) {
            return ['origem' => 'tela', 'dica' => $this->mascara($daTela)];
        }

        $doEnv = config('claudinho.api_key');

        if (filled($doEnv)) {
            return ['origem' => 'env', 'dica' => $this->mascara((string) $doEnv)];
        }

        return ['origem' => 'ausente', 'dica' => null];
    }

    /**
     * Limpar não apaga o registro: grava vazio, e valor vazio cai no env de novo.
     */
    public function limparChaveDeTranscricao(): void
    {
        $this->autoriza();

        Configuracao::definir('transcricao_chave', null);

        $this->chaveDeTranscricaoNova = '';

        $this->dispatch('claudinho-configuracoes-salvas');
    }

    /**
     * De onde a chave do Google em uso está vindo, sem revelar a chave.
     *
     * @return array{origem: 'tela'|'env'|'ausente', dica: string|null}
     */
    public function chaveDeTranscricaoEmUso(): array
    {
        $daTela = Configuracao::valor('transcricao_chave');

        if (filled($daTela)) {
            return ['origem' => 'tela', 'dica' => $this->mascara($daTela)];
        }

        $doEnv = config('claudinho.transcricao.chave');

        if (filled($doEnv)) {
            return ['origem' => 'env', 'dica' => $this->mascara((string) $doEnv)];
        }

        return ['origem' => 'ausente', 'dica' => null];
    }

    /**
     * O que está valendo AGORA na transcrição de áudio.
     *
     * `habilitada` é o interruptor e `ativa` é o resultado: ligado sem chave não
     * transcreve nada, e a tela precisa dizer isso na hora — senão o áudio falha em
     * silêncio, um a um, e o motivo só aparece no log de quem tiver acesso a ele.
     *
     * @return array{habilitada: bool, ativa: bool, idioma: string, gravada_em_tela: bool}
     */
    public function transcricaoEmUso(): array
    {
        return [
            'habilitada' => Transcricao::habilitada(),
            'ativa' => Transcricao::ativa(),
            'idioma' => Transcricao::idioma(),
            'gravada_em_tela' => $this->gravadoEmTela('transcricao_habilitada'),
        ];
    }

    /**
     * O que ainda falta para o endpoint atender, em linguagem de quem opera.
     *
     * Três dos quatro itens se resolvem nesta tela. O quarto é o resolvedor de
     * usuário, que é uma CLASSE e por isso não tem como vir de formulário: é ele
     * que responde de quem é a permissão sobre os dados de cada número.
     *
     * @return array{ativa: bool, pronta: bool, url: string, itens: array<int, array{ok: bool, texto: string}>}
     */
    public function situacaoApi(): array
    {
        $prefixo = trim((string) config('claudinho.api.prefixo', 'claudinho'), '/');
        $resolvedor = (string) config('claudinho.api.resolvedor', '');
        $temToken = $this->tokenEmUso()['origem'] !== 'ausente';
        $temTabela = $this->tabelaDeConversas();

        $itens = [
            [
                'ok' => $this->api,
                'texto' => $this->api
                    ? 'Atendimento ligado.'
                    : 'Atendimento desligado: o endpoint responde 503.',
            ],
            [
                'ok' => $temToken,
                'texto' => $temToken
                    ? 'Token do chamador definido.'
                    : 'Sem token: gere um abaixo, senão o endpoint responde 503.',
            ],
            [
                // Único item que só o código resolve: é uma classe, não tem como vir
                // de um formulário.
                'ok' => $resolvedor !== '',
                'texto' => $resolvedor !== ''
                    ? 'Resolvedor de usuário: '.class_basename($resolvedor).'.'
                    : 'Falta a classe resolvedora em claudinho.api.resolvedor — sem ela o '
                        .'endpoint não sabe de quem é a permissão.',
            ],
            [
                'ok' => $temTabela,
                'texto' => $temTabela
                    ? 'Tabela de conversas migrada.'
                    : 'Falta rodar php artisan migrate (tabela claudinho_conversas).',
            ],
        ];

        return [
            'ativa' => $this->api,
            'pronta' => ! in_array(false, array_column($itens, 'ok'), true),
            'url' => url($prefixo.'/conversa'),
            'itens' => $itens,
        ];
    }

    /**
     * O que está valendo AGORA nas conversas do canal externo, já com o gravado em
     * tela vencendo o config — é o que a tela mostra abaixo de cada campo, para a
     * edição não virar um chute sobre qual dos dois lados ganhou.
     *
     * @return array{acoes: bool, minutos_inatividade: int, minutos_confirmacao: int, palavras: array<int, string>, instrucoes: string}
     */
    public function regrasDoCanal(): array
    {
        // Normalizadas, e não como foram escritas: é esta forma que a resposta de
        // quem está do outro lado tem de casar. Mostrar "Sim!" cru faria a tela
        // prometer uma pontuação que o casamento não leva em conta.
        $palavras = array_values(array_unique(array_filter(array_map(
            fn (string $palavra): string => Confirmacao::normalizar($palavra),
            Canal::palavras()
        ))));

        return [
            'acoes' => Canal::acoes(),
            'minutos_inatividade' => Canal::minutosInatividade(),
            'minutos_confirmacao' => Canal::minutosConfirmacao(),
            'palavras' => $palavras,
            'instrucoes' => Canal::instrucoes(),
        ];
    }

    /**
     * O que o ARQUIVO diz, em texto pronto para o campo.
     *
     * Vira placeholder: campo vazio usa o config, e mostrar ali o valor que vai
     * valer evita a dúvida de sempre — "vazio é zero, ou é o padrão?".
     *
     * @return array{minutos_inatividade: string, minutos_confirmacao: string, palavras: string, instrucoes: string, hosts: string}
     */
    public function padroesDoCanal(): array
    {
        $hosts = array_filter(array_map(
            fn ($host): string => Canal::host((string) $host),
            (array) config('claudinho.api.midias.hosts', [])
        ));

        $palavras = array_filter(array_map(
            fn ($palavra): string => trim((string) $palavra),
            (array) config('claudinho.api.palavras_confirmacao', ['sim'])
        ));

        return [
            'minutos_inatividade' => (string) (int) config('claudinho.api.minutos_inatividade', 30),
            'minutos_confirmacao' => (string) (int) config('claudinho.api.minutos_confirmacao', 5),
            'palavras' => implode(', ', $palavras),
            'instrucoes' => trim((string) config('claudinho.api.instrucoes', '')),
            // Sem hosts no arquivo o placeholder vira exemplo: não há valor de
            // arquivo para anunciar, e o campo em branco precisa dizer o formato.
            'hosts' => $hosts === [] ? 'mmg.whatsapp.net' : implode("\n", $hosts),
        ];
    }

    /**
     * O valor em uso foi digitado aqui, ou ainda vem do arquivo?
     *
     * A tela diz isso campo a campo pelo mesmo motivo da chave e do token: sem a
     * frase, esvaziar um campo parece apagar a regra, quando na verdade devolve o
     * valor do config.
     */
    public function gravadoEmTela(string $chave): bool
    {
        return filled(Configuracao::valor($chave));
    }

    /**
     * Foto e vídeo do canal externo, exatamente como o Recebedor vai enxergar.
     *
     * Os padrões repetidos aqui são os dele de propósito: o mergeConfigFrom é raso,
     * então quem publicou o config antes da 1.7 não tem a chave `midias` no arquivo
     * e continua sem ela depois de atualizar. Ler config() sem padrão faria a tela
     * anunciar "nenhum tipo aceito" numa instalação que aceita os sete.
     *
     * @return array{habilitado: bool, destino: string|null, hosts: array<int, string>, tipos: array<int, string>, max_mb: float, max_por_mensagem: int, timeout: int, instrucao_propria: bool}
     */
    public function midiasEmUso(): array
    {
        $destino = trim((string) config('claudinho.api.midias.destino', ''));

        return [
            'habilitado' => (bool) config('claudinho.api.midias.habilitado', false),
            'destino' => $destino === '' ? null : class_basename($destino),
            // Do Canal, e não do config: os hosts podem ter sido cadastrados em tela,
            // e é lá que a normalização acontece — a mesma que o Recebedor compara.
            'hosts' => Canal::hostsDeMidia(),
            'tipos' => array_values(array_map(
                fn ($tipo): string => mb_strtolower(trim((string) $tipo)),
                (array) config('claudinho.api.midias.tipos', Recebedor::TIPOS_PADRAO)
            )),
            'max_mb' => round(((int) config('claudinho.api.midias.max_bytes', 20 * 1024 * 1024)) / 1024 / 1024, 1),
            'max_por_mensagem' => (int) config('claudinho.api.midias.max_por_mensagem', 3),
            'timeout' => (int) config('claudinho.api.midias.timeout', 20),
            'instrucao_propria' => trim((string) config('claudinho.api.midias.instrucao_da_descricao', '')) !== '',
        ];
    }

    /**
     * Igual ao todas(): nada aqui pode derrubar a tela por causa de banco.
     */
    private function tabelaDeConversas(): bool
    {
        return $this->existeTabela('claudinho_conversas');
    }

    /**
     * Sem a tabela, o cadastro do glossário não aparece — em vez de mostrar um
     * formulário que engole o que for escrito nele. O chat segue pelo config.
     */
    public function tabelaDoGlossario(): bool
    {
        return $this->existeTabela('claudinho_glossario');
    }

    private function existeTabela(string $tabela): bool
    {
        try {
            return Schema::hasTable($tabela);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public function modelosDisponiveis(): array
    {
        $modelos = (array) config('claudinho.modelos', []);

        // Modelo gravado fora da lista (mudou o config depois) continua selecionável,
        // senão o select mudaria o modelo em produção sem ninguém pedir.
        if (filled($this->modelo) && ! array_key_exists($this->modelo, $modelos)) {
            $modelos = [$this->modelo => $this->modelo.' (fora da lista do config)'] + $modelos;
        }

        return $modelos;
    }

    public function podeAdministrar(): bool
    {
        $permissao = config('claudinho.permissao_admin', 'claudinho_admin');

        return blank($permissao) || (bool) Auth::user()?->can($permissao);
    }

    private function autoriza(): void
    {
        abort_unless($this->podeAdministrar(), 403);
    }

    /**
     * Primeiros e últimos caracteres só para dar confirmação visual de qual chave
     * está gravada — nunca o suficiente para reconstruir o segredo.
     */
    private function mascara(string $chave): string
    {
        if (mb_strlen($chave) <= 12) {
            return str_repeat('•', mb_strlen($chave));
        }

        return mb_substr($chave, 0, 8).str_repeat('•', 8).mb_substr($chave, -4);
    }
}
