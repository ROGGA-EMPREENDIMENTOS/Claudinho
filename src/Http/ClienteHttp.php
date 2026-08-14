<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Http;

use Illuminate\Http\Client\Factory;

/**
 * O cliente HTTP do pacote: uma Factory do HTTP client do Laravel sem event
 * dispatcher.
 *
 * A resposta do stream tem corpo não-seekable — chega em pedaços, é lida linha por
 * linha e não se rebobina. Quem escuta os eventos do HTTP client (Clockwork,
 * Telescope, Debugbar) rebobina o corpo para registrar a resposta, e a requisição
 * morre em "Stream is not seekable" — foi assim que apareceu, com o Clockwork ativo
 * em local derrubando a primeira pergunta do chat. Listener que lê sem rebobinar é
 * ainda pior: não estoura, consome o stream que ainda não foi lido e o chat responde
 * vazio. Nada disso é bug de quem escuta: gravar uma resposta que só pode ser lida
 * uma vez é impossível.
 *
 * Factory sem dispatcher não dispara RequestSending nem ResponseReceived, então a
 * chamada à API não passa por listener nenhum. O preço é essa chamada não aparecer no
 * painel de HTTP dessas ferramentas. As duas chamadas do pacote — o stream e a
 * mensagem avulsa — usam o mesmo cliente de propósito: transporte com dois caminhos
 * de rede, um observável e outro não, custa mais do que a linha que se perde no
 * painel.
 *
 * Herdar, em vez de instanciar a Factory direto, dá uma chave de container própria
 * sem disputar o binding do facade Http: o HTTP client da aplicação continua com os
 * eventos dele.
 */
class ClienteHttp extends Factory
{
    /**
     * Sem argumento nenhum de propósito: assim nem o container tem por onde injetar
     * o dispatcher da aplicação no lugar do null.
     */
    public function __construct()
    {
        parent::__construct(dispatcher: null);
    }
}
