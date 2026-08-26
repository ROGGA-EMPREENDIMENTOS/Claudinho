<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Midia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rogga\Claudinho\Transcricao;
use Throwable;

/**
 * Manda o áudio recebido para a API do Google Speech-to-Text e devolve o que foi
 * dito nele.
 *
 * O Claude lê, mas não ouve: sem isto, o áudio do WhatsApp chega ao assistente
 * como um endereço que ele não sabe abrir, e a conversa morre num "não entendi"
 * enquanto o link assinado vence.
 *
 * Usa o `speech:recognize` SÍNCRONO, e não o `longrunningrecognize`: quem está do
 * outro lado está esperando a resposta chegar no aplicativo de mensagens, e o
 * assíncrono exigiria o arquivo num bucket do Cloud Storage — ou seja, obrigaria a
 * aplicação a ter um. O preço é o teto de um minuto de áudio, que é o recado de
 * WhatsApp típico; acima disso a resposta diz isso com todas as letras, porque
 * reenviar o mesmo áudio daria no mesmo.
 *
 * Nada aqui derruba a conversa: toda falha vira um motivo, e o Recebedor o
 * transforma em anotação. Quem está do outro lado precisa de resposta, não de
 * silêncio.
 */
class Transcritor
{
    private const ENDPOINT = 'https://speech.googleapis.com/v1/speech:recognize';

    /**
     * Teto do `speech:recognize` síncrono: o áudio vai embutido no JSON, em base64,
     * e a API recusa acima disso. Conferir antes evita subir megabytes para receber
     * um 400 — e o base64 ainda infla o corpo em um terço.
     */
    private const BYTES_MAXIMOS = 10 * 1024 * 1024;

    /**
     * As taxas que o Google aceita para Opus. O que estiver fora não é chute nosso:
     * a API recusa o áudio inteiro com "sample rate must be one of".
     */
    private const TAXAS_DE_OPUS = [8000, 12000, 16000, 24000, 48000];

    /**
     * O que foi dito no áudio, ou por que não deu.
     *
     * O motivo importa tanto quanto o texto: cada um deles pede uma resposta
     * diferente de quem está do outro lado. Reenviar resolve `falha`; não resolve
     * `longo` nem `formato`, e mandar a pessoa tentar de novo à toa é pior do que
     * não ter transcrito nada.
     *
     * @return array{texto: string|null, motivo: 'ok'|'longo'|'formato'|'vazio'|'falha'}
     */
    public function transcrever(string $tipo, string $conteudo): array
    {
        $chave = Transcricao::chave();

        if ($chave === null) {
            // O interruptor está ligado e a chave não existe. A tela avisa disso em
            // voz alta; aqui fica o registro para quem só tem o log.
            Log::warning('Claudinho: transcrição ligada sem chave do Google — nenhum áudio será transcrito.');

            return ['texto' => null, 'motivo' => 'falha'];
        }

        $formato = $this->formato($tipo, $conteudo);

        if ($formato === null) {
            // AAC e M4A caem aqui: o WhatsApp manda Opus na gravação de voz, mas
            // arquivo encaminhado vem em qualquer coisa, e a API do Google não
            // decodifica esses dois.
            Log::info('Claudinho: áudio em formato que a API do Google não transcreve.', ['tipo' => $tipo]);

            return ['texto' => null, 'motivo' => 'formato'];
        }

        if (mb_strlen($conteudo, '8bit') > self::BYTES_MAXIMOS) {
            return ['texto' => null, 'motivo' => 'longo'];
        }

        try {
            $resposta = Http::timeout(Transcricao::timeout())
                ->post(self::ENDPOINT.'?key='.urlencode($chave), [
                    'config' => array_filter([
                        // Nulos saem: para WAV e FLAC o cabeçalho do próprio arquivo
                        // responde os dois, e mandar um palpite no lugar faria a API
                        // recusar um áudio que ela leria sozinha.
                        'encoding' => $formato['encoding'],
                        'sampleRateHertz' => $formato['taxa'],
                        'languageCode' => Transcricao::idioma(),
                        // Sem isto o texto chega numa linha só, sem vírgula nem ponto
                        // — e é esse texto que o modelo vai ler como se fosse a
                        // mensagem digitada por quem enviou.
                        'enableAutomaticPunctuation' => true,
                    ], static fn ($valor): bool => $valor !== null),
                    'audio' => ['content' => base64_encode($conteudo)],
                ]);
        } catch (Throwable $e) {
            $this->registrar('Claudinho: falha ao chamar a API de transcrição.', $e->getMessage(), $chave);

            return ['texto' => null, 'motivo' => 'falha'];
        }

        if (! $resposta->successful()) {
            $erro = (string) ($resposta->json('error.message') ?? '');

            $this->registrar('Claudinho: a API de transcrição recusou o áudio.', $erro, $chave, [
                'status' => $resposta->status(),
            ]);

            return ['texto' => null, 'motivo' => $this->longoDemais($erro) ? 'longo' : 'falha'];
        }

        $texto = $this->texto($resposta->json('results'));

        // Resposta boa e sem nada dentro é o caso do áudio mudo, do ruído e do
        // recado em outro idioma. Não é falha: reenviar o mesmo arquivo dá no
        // mesmo, e quem enviou precisa saber que não foi entendido.
        return $texto === null
            ? ['texto' => null, 'motivo' => 'vazio']
            : ['texto' => $texto, 'motivo' => 'ok'];
    }

    /**
     * O texto das alternativas, na ordem em que a API devolveu.
     *
     * A API quebra o áudio em trechos e devolve um `results` por trecho, cada um
     * com as alternativas ordenadas da mais provável para a menos. A primeira de
     * cada trecho, emendadas, é a frase inteira.
     *
     * @param  mixed  $resultados
     */
    private function texto($resultados): ?string
    {
        if (! \is_array($resultados)) {
            return null;
        }

        $trechos = array_filter(array_map(
            static fn ($resultado): string => \is_array($resultado)
                ? trim((string) ($resultado['alternatives'][0]['transcript'] ?? ''))
                : '',
            $resultados
        ));

        $texto = trim(preg_replace('/\s+/u', ' ', implode(' ', $trechos)) ?? '');

        return $texto === '' ? null : $texto;
    }

    /**
     * Como o áudio está codificado e em que taxa, no vocabulário da API.
     *
     * `encoding` e `taxa` nulos significam "o arquivo responde": é o caso do WAV e
     * do FLAC, os únicos dois em que a API lê o cabeçalho. Nos demais os dois são
     * obrigatórios, e errar a taxa não degrada a transcrição — a API recusa o
     * áudio inteiro.
     *
     * null é o formato que a API não transcreve de jeito nenhum.
     *
     * @return array{encoding: string|null, taxa: int|null}|null
     */
    private function formato(string $tipo, string $conteudo): ?array
    {
        return match ($this->semParametros($tipo)) {
            // A gravação de voz do WhatsApp. A taxa vem do cabeçalho do próprio
            // arquivo porque ela varia com quem gravou, e o palpite errado custaria
            // o áudio inteiro.
            'audio/ogg', 'audio/opus', 'audio/x-opus', 'application/ogg' => [
                'encoding' => 'OGG_OPUS',
                'taxa' => $this->taxaDoOpus($conteudo) ?? 16000,
            ],
            // Opus fora do Ogg é sempre 48 kHz, por definição do contêiner.
            'audio/webm' => ['encoding' => 'WEBM_OPUS', 'taxa' => 48000],
            // As duas taxas do AMR são fixas: é o que define cada um dos dois.
            'audio/amr', 'audio/3gpp' => ['encoding' => 'AMR', 'taxa' => 8000],
            'audio/amr-wb' => ['encoding' => 'AMR_WB', 'taxa' => 16000],
            'audio/mpeg', 'audio/mp3', 'audio/x-mp3', 'audio/mpeg3' => [
                'encoding' => 'MP3',
                'taxa' => $this->taxaDoMp3($conteudo) ?? 44100,
            ],
            'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' => ['encoding' => null, 'taxa' => null],
            'audio/flac', 'audio/x-flac' => ['encoding' => null, 'taxa' => null],
            default => null,
        };
    }

    /**
     * O tipo sem os parâmetros. O WhatsApp manda `audio/ogg; codecs=opus`, e
     * comparado inteiro ele não casa com nada.
     */
    private function semParametros(string $tipo): string
    {
        return trim(mb_strtolower(explode(';', $tipo)[0]));
    }

    /**
     * A taxa declarada no cabeçalho OpusHead, que abre o arquivo.
     *
     * Layout do cabeçalho, a partir da assinatura: `OpusHead` (8), versão (1),
     * canais (1), pre-skip (2) e então a taxa de entrada, 4 bytes little-endian —
     * daí o deslocamento de 12.
     */
    private function taxaDoOpus(string $conteudo): ?int
    {
        // Só no começo do arquivo: OpusHead é o primeiro pacote, e varrer megabytes
        // atrás dele seria procurar onde ele não está.
        $posicao = strpos(substr($conteudo, 0, 1024), 'OpusHead');

        if ($posicao === false) {
            return null;
        }

        $campo = substr($conteudo, $posicao + 12, 4);

        if (\strlen($campo) < 4) {
            return null;
        }

        $taxa = (int) (unpack('V', $campo)[1] ?? 0);

        // Fora da lista devolve null e o chamador usa 16000 — que é o que a
        // gravação de voz usa. Mandar a taxa exótica faria a API recusar o áudio.
        return \in_array($taxa, self::TAXAS_DE_OPUS, true) ? $taxa : null;
    }

    /**
     * A taxa do primeiro quadro do MP3.
     *
     * Vale a leitura porque recado de voz costuma vir em 16 ou 22 kHz, e não nos
     * 44,1 kHz de música: chutar o valor de música recusaria justamente o arquivo
     * que interessa.
     */
    private function taxaDoMp3(string $conteudo): ?int
    {
        $inicio = $this->depoisDoId3($conteudo);
        $limite = min(\strlen($conteudo) - 4, $inicio + 8192);

        // Índice da tabela de taxas do MPEG, por versão. 1 não existe (reservado).
        $tabela = [
            0 => [11025, 12000, 8000],  // MPEG 2.5
            2 => [22050, 24000, 16000], // MPEG 2
            3 => [44100, 48000, 32000], // MPEG 1
        ];

        for ($i = $inicio; $i < $limite; $i++) {
            // Sincronismo do quadro: onze bits em 1.
            if (\ord($conteudo[$i]) !== 0xFF || (\ord($conteudo[$i + 1]) & 0xE0) !== 0xE0) {
                continue;
            }

            $versao = (\ord($conteudo[$i + 1]) >> 3) & 0x03;
            $indice = (\ord($conteudo[$i + 2]) >> 2) & 0x03;

            if ($indice === 3 || ! isset($tabela[$versao])) {
                continue;
            }

            return $tabela[$versao][$indice];
        }

        return null;
    }

    /**
     * Onde o áudio começa de verdade: a etiqueta ID3v2 vem antes dele e o
     * sincronismo do quadro seria procurado dentro do texto dela.
     *
     * O tamanho da etiqueta é gravado em quatro bytes de sete bits cada — o oitavo
     * fica sempre em zero para não imitar um sincronismo.
     */
    private function depoisDoId3(string $conteudo): int
    {
        if (! str_starts_with($conteudo, 'ID3') || \strlen($conteudo) < 10) {
            return 0;
        }

        $bytes = unpack('C4', substr($conteudo, 6, 4));

        if ($bytes === false) {
            return 0;
        }

        return 10 + ((($bytes[1] & 0x7F) << 21) | (($bytes[2] & 0x7F) << 14) | (($bytes[3] & 0x7F) << 7) | ($bytes[4] & 0x7F));
    }

    /**
     * O erro é o do áudio acima de um minuto?
     *
     * A API não devolve código próprio para isso — é um INVALID_ARGUMENT como
     * qualquer outro, e o que distingue está na frase. Vale o casamento por texto
     * porque a alternativa é mandar a pessoa reenviar um áudio que vai ser
     * recusado de novo.
     */
    private function longoDemais(string $erro): bool
    {
        return str_contains($erro, 'too long') || str_contains($erro, 'LongRunningRecognize');
    }

    /**
     * Registra sem deixar a chave vazar.
     *
     * A chave viaja na QUERY STRING, que é como a API do Google aceita chave de
     * projeto — e a mensagem de erro do cliente HTTP traz a URL inteira dentro
     * dela. Sem esta troca, o log da aplicação passaria a guardar a credencial em
     * texto puro a cada falha.
     *
     * @param  array<string, mixed>  $contexto
     */
    private function registrar(string $titulo, string $mensagem, string $chave, array $contexto = []): void
    {
        Log::warning($titulo, $contexto + [
            'message' => str_replace($chave, '[chave]', $mensagem),
        ]);
    }
}
