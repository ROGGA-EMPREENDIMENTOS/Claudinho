<?php

declare(strict_types=1);

namespace Rogga\Claudinho;

use Composer\InstalledVersions;
use OutOfBoundsException;

class Claudinho
{
    public const PACOTE = 'rogga/claudinho';

    public const LICENCA = 'MIT';

    public const DESENVOLVEDOR = 'Rôgga Empreendimentos';

    /**
     * Versão instalada do pacote, lida do Composer.
     *
     * Devolve null quando o pacote roda fora de uma instalação Composer (repositório
     * clonado direto, por exemplo) — quem exibe decide o que fazer com a ausência.
     */
    public static function versao(): ?string
    {
        return self::versaoDe(self::PACOTE);
    }

    /**
     * O mesmo para qualquer pacote instalado.
     *
     * O `v` da tag sai fora: o Composer devolve o nome da tag como está, e a maioria
     * dos pacotes PHP marca `v1.4.1`. Numa lista ao lado de Laravel e PHP — que vêm
     * sem prefixo — um `v` solto lê como inconsistência, não como informação. O ltrim
     * é só do prefixo, então `dev-main` de quem exige o pacote por branch passa
     * intacto: essa informação importa, e é justamente ela que diz por que não há
     * número ali.
     */
    public static function versaoDe(string $pacote): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            $versao = InstalledVersions::getPrettyVersion($pacote);
        } catch (OutOfBoundsException) {
            return null;
        }

        // Null continua null, e não '': o contrato de ausência é o mesmo do pacote
        // que não está instalado, e quem exibe já sabe tratá-lo.
        return $versao === null ? null : ltrim($versao, 'v');
    }

    /**
     * Versões de tudo que a janela "Sobre" mostra, na ordem em que aparecem.
     *
     * Vem junto e não em quatro chamadas soltas porque o valor está na combinação:
     * num suporte, "Claudinho 1.4 em Laravel 11 com Livewire 3" é o que localiza o
     * problema — cada número sozinho não diz nada.
     *
     * Versão ausente é removida em vez de virar "?" ou "desconhecida": linha que não
     * informa nada só ocupa espaço, e quem clona o repositório direto (sem instalar
     * pelo Composer) não tem o que fazer com a informação de que falta metadado.
     *
     * @return array<string, string>
     */
    public static function ambiente(): array
    {
        $versoes = [
            'Claudinho' => self::versao(),
            'Laravel' => app()->version(),
            'Livewire' => self::versaoDe('livewire/livewire'),
            'PHP' => PHP_VERSION,
        ];

        return array_filter($versoes, static fn (?string $versao): bool => filled($versao));
    }
}
