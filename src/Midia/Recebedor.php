<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Midia;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rogga\Claudinho\Claude;
use Rogga\Claudinho\Contracts\DestinoDeMidia;
use Throwable;

/**
 * Recebe a foto e o vídeo que chegam pelo canal externo.
 *
 * O gateway não manda o arquivo: manda o tipo e uma URI assinada, que costuma
 * vencer em meia hora. Baixar na hora é obrigatório — quando quem está
 * conversando terminar de responder as perguntas do assistente, o link já não
 * abre mais.
 *
 * A imagem é descrita AGORA e a descrição entra na conversa como texto. A
 * alternativa — mandar a imagem em toda requisição junto do histórico — custaria
 * os tokens dela em cada volta do loop de ferramenta. Descrever uma vez sai mais
 * barato e sobrevive ao corte do histórico.
 *
 * Vídeo a API não lê. Ele é entregue ao destino igual, e o assistente pede a
 * descrição em texto.
 *
 * Nada aqui derruba a conversa: toda falha vira anotação explicando o que houve,
 * porque quem está do outro lado precisa de resposta, não de silêncio.
 */
class Recebedor
{
    /** Os formatos que a API do modelo enxerga. Fora deles, só o arquivo. */
    private const FORMATOS_DE_VISAO = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * O que se aceita quando a configuração não diz.
     *
     * Existe porque o mergeConfigFrom é RASO: a aplicação que publicou o config
     * na 1.6 tem o bloco `api` inteiro dela, e a chave `midias` que este release
     * acrescenta não chega lá. Sem este padrão, ligar a funcionalidade num
     * consumidor antigo recusaria toda mídia com "tipo não aceito" — e o motivo
     * não estaria em lugar nenhum que ele fosse olhar.
     *
     * Vale para `tipos` e para todos os demais valores deste arquivo: o segundo
     * argumento de cada config() é o padrão de verdade, não um enfeite. Só
     * `habilitado` e `destino` precisam ser escritos pela aplicação.
     *
     * Pública porque a tela de configurações mostra os tipos aceitos e precisa
     * cair no mesmo padrão — duas listas iguais escritas em dois lugares só
     * ficam iguais até alguém mexer numa delas.
     */
    public const TIPOS_PADRAO = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'video/mp4',
        'video/3gpp',
        'video/quicktime',
    ];

    /** Acima disto a imagem é reduzida antes de subir: 1568px é o lado que o modelo aproveita. */
    private const BYTES_PARA_REDUZIR = 1_000_000;

    private const LADO_MAXIMO = 1568;

    /**
     * Baixa, confere e descreve. Devolve a anotação que entra na conversa no
     * lugar do JSON do gateway — é o que o modelo lê.
     */
    public function receber(Authenticatable $usuario, string $tipo, string $uri, ?string $legenda = null): string
    {
        $tipo = mb_strtolower(trim($tipo));
        $ehVideo = str_starts_with($tipo, 'video/');
        $arquivo = $ehVideo ? 'um vídeo' : 'uma foto';
        $ele = $ehVideo ? 'ele' : 'ela';

        if (! \in_array($tipo, $this->aceitos(), true)) {
            // O tipo vem da mensagem, e vai para dentro do prompt: cortado,
            // porque um valor de mil caracteres empurraria a conversa para fora
            // do limite.
            return '[Chegou nesta conversa um arquivo do tipo '.Str::limit($tipo, 40).', que não é '
                .'aceito neste canal. Diga isso a quem enviou.]';
        }

        // Endereço bloqueado é diferente de download que falhou, e a diferença
        // importa para quem está do outro lado: reenviar o mesmo arquivo do mesmo
        // lugar dá exatamente no mesmo.
        if (! $this->enderecoLiberado($uri)) {
            return "[Chegou {$arquivo} nesta conversa, mas {$ele} veio de um endereço que este "
                .'servidor não acessa. NÃO peça reenvio — vai dar no mesmo. Diga que o anexo não '
                .'passou e siga o atendimento sem ele.]';
        }

        $conteudo = $this->baixar($uri);

        if ($conteudo === null) {
            return "[Chegou {$arquivo} nesta conversa, mas {$ele} não chegou até mim. Peça o reenvio.]";
        }

        // Do download em diante manda o arquivo, não o que o gateway disse dele.
        $tipo = $this->tipoReal($conteudo, $tipo);

        $midia = new MidiaRecebida(
            tipo: $tipo,
            conteudo: $conteudo,
            nome: $this->nome($tipo),
            descricao: $ehVideo ? null : $this->descrever($tipo, $conteudo),
            legenda: $legenda,
        );

        return $this->anotacao($midia, $this->entregar($usuario, $midia));
    }

    /**
     * A anotação que o modelo lê, com a frase que o destino quis acrescentar.
     */
    private function anotacao(MidiaRecebida $midia, ?string $doDestino): string
    {
        $sufixo = filled($doDestino) ? ' '.trim((string) $doDestino) : '';

        if ($midia->ehVideo()) {
            return '[Vídeo recebido nesta conversa. Não consigo assistir a vídeo: peça a descrição '
                ."em texto do que aparece nele.{$sufixo}]";
        }

        return $midia->descricao === null
            ? '[Foto recebida nesta conversa. Não consegui enxergá-la: peça a descrição em texto do '
                ."que aparece nela.{$sufixo}]"
            : "[Foto recebida nesta conversa. O que aparece nela: {$midia->descricao}{$sufixo}]";
    }

    /**
     * Entrega ao destino da aplicação, se houver um configurado.
     *
     * Falha aqui não pode custar a conversa: a descrição já existe e é o que o
     * modelo precisa para responder. O arquivo perdido é o preço, e ele fica
     * registrado.
     */
    private function entregar(Authenticatable $usuario, MidiaRecebida $midia): ?string
    {
        $classe = (string) config('claudinho.api.midias.destino', '');

        if ($classe === '') {
            return null;
        }

        try {
            $destino = app($classe);

            if (! $destino instanceof DestinoDeMidia) {
                Log::warning('Claudinho: destino de mídia não implementa o contrato.', [
                    'classe' => $classe,
                ]);

                return null;
            }

            return $destino->guardar($usuario, $midia);
        } catch (Throwable $e) {
            Log::warning('Claudinho: o destino não conseguiu guardar a mídia.', [
                'classe' => $classe,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * O tipo que os BYTES dizem ser, e não o que o gateway declarou.
     *
     * O gateway erra: já chegou `image/jpeg` apontando para um `.webp`, e a API
     * recusa a imagem inteira ("specified using the image/jpeg media type, but
     * the image appears to be a image/webp image"). O arquivo ia para a
     * aplicação com a extensão errada e sem descrição nenhuma.
     *
     * O declarado ainda serve para a checagem que roda ANTES do download — ali
     * não há bytes para olhar. Aqui há.
     */
    private function tipoReal(string $conteudo, string $declarado): string
    {
        $detectado = mb_strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($conteudo));

        if ($detectado === $declarado || ! \in_array($detectado, $this->aceitos(), true)) {
            return $declarado;
        }

        Log::info('Claudinho: tipo declarado pelo gateway não bate com o arquivo.', [
            'declarado' => $declarado,
            'real' => $detectado,
        ]);

        return $detectado;
    }

    /**
     * O que o modelo enxerga na imagem, em texto curto.
     *
     * Falha aqui não derruba nada: o arquivo continua indo para a aplicação e o
     * assistente pede a descrição a quem enviou.
     */
    private function descrever(string $tipo, string $conteudo): ?string
    {
        if (! \in_array($tipo, self::FORMATOS_DE_VISAO, true)) {
            return null;
        }

        [$tipo, $conteudo] = $this->reduzir($tipo, $conteudo);

        try {
            // Esforço no mínimo, e não o da conversa: dizer o que aparece numa
            // foto não é raciocínio, e este é o único ponto do fluxo em que quem
            // está conversando espera DUAS chamadas à API em sequência — a visão
            // e, logo depois, a resposta do assistente.
            $descricao = (new Claude)->comEsforco('low')->mensagem([[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $tipo,
                            'data' => base64_encode($conteudo),
                        ],
                    ],
                    ['type' => 'text', 'text' => $this->instrucaoDaDescricao()],
                ],
            ]]);
        } catch (Throwable $e) {
            Log::warning('Claudinho: não foi possível descrever a imagem recebida.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $descricao = trim(preg_replace('/\s+/u', ' ', $descricao) ?? '');

        return $descricao === '' ? null : Str::limit($descricao, 700);
    }

    private function instrucaoDaDescricao(): string
    {
        $daAplicacao = trim((string) config('claudinho.api.midias.instrucao_da_descricao', ''));

        if ($daAplicacao !== '') {
            return $daAplicacao;
        }

        return <<<'TEXTO'
        Descreva esta imagem em no máximo três frases, em português do Brasil, começando direto pela
        descrição — sem introdução.

        Diga o que se vê, onde parece ser e o que há de relevante nela. Sendo documento, diga o tipo e
        os dados legíveis. Dê tamanho e extensão aproximados quando der para estimar pela imagem.

        Não descreva pessoas nem características físicas de ninguém. Não conclua causa nem
        responsabilidade — descreva só o que está na imagem. Se ela estiver escura, tremida ou não
        mostrar nada aproveitável, diga exatamente isso.
        TEXTO;
    }

    /**
     * Foto de celular chega com 3 a 5 MB e resolução muito acima do que o modelo
     * aproveita: acima de 1568px no lado maior a própria API reduz antes de ler,
     * e o que se paga a mais em token não vira detalhe nenhum.
     *
     * @return array{0: string, 1: string}
     */
    private function reduzir(string $tipo, string $conteudo): array
    {
        // Sem GD a redução simplesmente não acontece: é economia, não requisito,
        // e o pacote não vai exigir extensão por causa dela.
        if (mb_strlen($conteudo, '8bit') <= self::BYTES_PARA_REDUZIR || ! \function_exists('imagecreatefromstring')) {
            return [$tipo, $conteudo];
        }

        try {
            $imagem = imagecreatefromstring($conteudo);

            if ($imagem === false) {
                return [$tipo, $conteudo];
            }

            $largura = imagesx($imagem);
            $altura = imagesy($imagem);
            $escala = self::LADO_MAXIMO / max($largura, $altura);

            if ($escala >= 1) {
                imagedestroy($imagem);

                return [$tipo, $conteudo];
            }

            $reduzida = imagescale($imagem, (int) round($largura * $escala), (int) round($altura * $escala));
            imagedestroy($imagem);

            if ($reduzida === false) {
                return [$tipo, $conteudo];
            }

            ob_start();
            imagejpeg($reduzida, null, 75);
            $bytes = (string) ob_get_clean();
            imagedestroy($reduzida);

            return $bytes === '' ? [$tipo, $conteudo] : ['image/jpeg', $bytes];
        } catch (Throwable $e) {
            // GIF animado, formato que o GD não abre, imagem corrompida: vai como
            // veio. Só encarece a chamada, não impede de descrever.
            return [$tipo, $conteudo];
        }
    }

    /**
     * A URI vem de dentro da MENSAGEM, ou seja, de fora: qualquer um pode digitar
     * {"type":"image/jpeg","uri":"https://169.254.169.254/..."} no WhatsApp e o
     * gateway repassa como texto.
     *
     * Separado do baixar() porque as duas falhas pedem respostas diferentes:
     * endereço bloqueado não adianta reenviar, download que falhou sim.
     */
    private function enderecoLiberado(string $uri): bool
    {
        $host = mb_strtolower((string) parse_url($uri, PHP_URL_HOST));
        $hosts = array_map('strtolower', (array) config('claudinho.api.midias.hosts', []));

        if ($host === '' || parse_url($uri, PHP_URL_SCHEME) !== 'https') {
            Log::warning('Claudinho: mídia recusada, endereço não é https.', ['host' => $host]);

            return false;
        }

        // Lista preenchida: só ela, e sem consultar DNS — quem cadastrou o host
        // respondeu por ele. É o modo de produção, com o host do gateway.
        if ($hosts !== []) {
            if (\in_array($host, $hosts, true)) {
                return true;
            }

            // O host vai para o log porque é o que se acrescenta na configuração
            // quando o gateway muda de endereço.
            Log::warning('Claudinho: mídia recusada por endereço não liberado.', [
                'host' => $host,
                'liberados' => $hosts,
            ]);

            return false;
        }

        return $this->enderecoPublico($host);
    }

    /**
     * O host resolve para endereço público?
     *
     * Vale só quando a lista de hosts está vazia — o modo aberto, que existe para
     * desenvolvimento e para gateway que troca de domínio sem avisar. Com a lista
     * preenchida ela já é a barreira, e uma consulta DNS por arquivo seria
     * latência sem contrapartida.
     */
    private function enderecoPublico(string $host): bool
    {
        $publico = static fn (string $ip): bool => filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        // Endereço já em número não precisa de DNS — e é justamente a forma em
        // que a tentativa de alcançar a rede interna costuma chegar.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($publico($host)) {
                return true;
            }

            Log::warning('Claudinho: mídia recusada, endereço de rede interna.', ['host' => $host]);

            return false;
        }

        $enderecos = array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );

        if ($enderecos === []) {
            Log::warning('Claudinho: mídia recusada, host não resolveu.', ['host' => $host]);

            return false;
        }

        foreach ($enderecos as $endereco) {
            if (! $publico((string) $endereco)) {
                Log::warning('Claudinho: mídia recusada, host aponta para rede interna.', [
                    'host' => $host,
                    'endereco' => $endereco,
                ]);

                return false;
            }
        }

        return true;
    }

    private function baixar(string $uri): ?string
    {
        $maximo = (int) config('claudinho.api.midias.max_bytes', 20 * 1024 * 1024);

        try {
            // Sem seguir redirecionamento: host público que responde 302 para
            // 169.254.169.254 anularia a checagem de endereço — a que vale é a do
            // endereço que este servidor realmente acessa.
            $resposta = Http::withoutRedirecting()
                ->timeout((int) config('claudinho.api.midias.timeout', 20))
                ->get($uri);

            if (! $resposta->successful()) {
                Log::warning('Claudinho: mídia não baixou.', ['status' => $resposta->status()]);

                return null;
            }

            $conteudo = $resposta->body();
        } catch (Throwable $e) {
            Log::warning('Claudinho: falha ao baixar a mídia.', ['message' => $e->getMessage()]);

            return null;
        }

        // Depois de baixar, e não pelo Content-Length: o cabeçalho é do gateway e
        // pode não vir. O teto existe para não estourar a memória do processo.
        if ($conteudo === '' || mb_strlen($conteudo, '8bit') > $maximo) {
            Log::warning('Claudinho: mídia vazia ou acima do limite.', [
                'bytes' => mb_strlen($conteudo, '8bit'),
            ]);

            return null;
        }

        return $conteudo;
    }

    private function nome(string $tipo): string
    {
        return 'claudinho-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(5)).'.'.$this->extensao($tipo);
    }

    private function extensao(string $tipo): string
    {
        return match ($tipo) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'video/quicktime' => 'mov',
            default => 'bin',
        };
    }

    /**
     * @return list<string>
     */
    private function aceitos(): array
    {
        return array_map('strtolower', (array) config('claudinho.api.midias.tipos', self::TIPOS_PADRAO));
    }
}
