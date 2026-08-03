<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Console;

use Illuminate\Console\Command;
use Rogga\Claudinho\Models\ConversaExterna;

class LimparConversas extends Command
{
    protected $signature = 'claudinho:limpar-conversas {--dias=7 : Apaga vencidas há mais dias que isto}';

    protected $description = 'Apaga conversas de canais externos já vencidas.';

    /**
     * A conversa vencida não é apagada na hora de propósito: o emAndamento() a
     * reaproveita com estado zerado, o que evita corrida no unique da tabela. Este
     * comando é só a faxina do que ficou para trás — agende no schedule da aplicação.
     */
    public function handle(): int
    {
        $dias = max(0, (int) $this->option('dias'));

        $apagadas = ConversaExterna::query()
            ->vencidas()
            ->where('updated_at', '<', now()->subDays($dias))
            ->delete();

        $this->info("Conversas apagadas: {$apagadas}.");

        return self::SUCCESS;
    }
}
