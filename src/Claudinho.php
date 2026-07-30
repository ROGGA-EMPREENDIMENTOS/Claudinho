<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Composer\InstalledVersions;
use OutOfBoundsException;

class Claudinho
{
    public const PACOTE = 'rogga/claudinho';

    /**
     * Versão instalada do pacote, lida do Composer.
     *
     * Devolve null quando o pacote roda fora de uma instalação Composer (repositório
     * clonado direto, por exemplo) — quem exibe decide o que fazer com a ausência.
     */
    public static function versao(): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion(self::PACOTE);
        } catch (OutOfBoundsException) {
            return null;
        }
    }
}
