<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Rogga\Claudinho\Midia\MidiaRecebida;

/**
 * Onde a foto e o vídeo recebidos vão parar.
 *
 * O pacote recebe a mídia — baixa, confere o endereço, descobre o tipo pelos
 * bytes e descreve a imagem —, mas não sabe o que ela significa na aplicação.
 * Num sistema de atendimento vira anexo de chamado; num de vistoria, evidência
 * do item; num terceiro, nada. Guardar é decisão de domínio, e é por isso que
 * este contrato existe em vez de o pacote escolher um disco e uma pasta.
 *
 * Sem destino configurado a mídia ainda é descrita e a descrição entra na
 * conversa: o modelo passa a enxergar a foto mesmo numa aplicação que não tem o
 * que fazer com o arquivo.
 */
interface DestinoDeMidia
{
    /**
     * Guarda a mídia e, se quiser, acrescenta uma frase à anotação.
     *
     * A frase é o que só a aplicação sabe dizer — "vai como anexo do chamado que
     * você abrir", por exemplo. Devolver null deixa a anotação como o pacote a
     * montou.
     *
     * Só é chamado quando a mídia chegou inteira. Endereço recusado, download
     * que falhou e tipo não aceito não passam por aqui: não há arquivo, e a
     * anotação dessas falhas é a mesma em qualquer aplicação.
     *
     * Estourar aqui não derruba a conversa — o pacote registra e segue com a
     * anotação sem a frase.
     */
    public function guardar(Authenticatable $usuario, MidiaRecebida $midia): ?string;
}
