<?php

declare(strict_types=1);

namespace Rogga\Claudinho\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Rogga\Claudinho\Conversa;

/**
 * A conversa de um canal externo (WhatsApp e afins), que não tem estado de Livewire
 * para guardar o histórico.
 *
 * Uma linha por canal+identificador: a conversa é contínua enquanto houver
 * atividade e recomeça depois do silêncio configurado.
 *
 * @property string $canal
 * @property string $identificador
 * @property int $user_id
 * @property array<string, mixed> $estado
 * @property Carbon $expira_em
 * @property Carbon|null $confirmar_ate
 */
class ConversaExterna extends Model
{
    protected $table = 'claudinho_conversas';

    protected $fillable = ['canal', 'identificador', 'user_id', 'estado', 'expira_em', 'confirmar_ate'];

    protected $casts = [
        'estado' => 'array',
        'expira_em' => 'datetime',
        // Sem este cast o valor volta do banco como string e o isPast() da checagem
        // de prazo estoura — 500 em toda resposta de confirmação.
        'confirmar_ate' => 'datetime',
        'user_id' => 'integer',
    ];

    /**
     * Localiza a conversa em andamento do canal, ou começa uma.
     *
     * Vencida não é apagada aqui: é reaproveitada com estado zerado. Isso mantém
     * uma linha por canal+identificador (o unique da tabela) e evita a corrida de
     * duas mensagens quase simultâneas tentarem inserir a mesma chave.
     *
     * Troca de usuário também zera: se o resolver passou a devolver outra pessoa
     * para o mesmo número, o histórico anterior foi respondido sob outro escopo de
     * permissão e não pode continuar no contexto.
     */
    public static function emAndamento(string $canal, string $identificador, int $usuarioId): self
    {
        $conversa = static::query()->firstOrNew([
            'canal' => $canal,
            'identificador' => $identificador,
        ]);

        if (! $conversa->exists || $conversa->venceu() || $conversa->user_id !== $usuarioId) {
            $conversa->fill([
                'user_id' => $usuarioId,
                'estado' => [],
            ]);
        }

        return $conversa->renovar();
    }

    public function venceu(): bool
    {
        return $this->expira_em === null || $this->expira_em->isPast();
    }

    /**
     * Empurra o vencimento para daqui a `minutos_inatividade`. Não grava: quem grava
     * é o guardar(), depois de a resposta ter sido produzida.
     */
    public function renovar(): self
    {
        $minutos = (int) config('claudinho.api.minutos_inatividade', 30);

        $this->expira_em = now()->addMinutes(max(1, $minutos));

        return $this;
    }

    public function motor(): Conversa
    {
        return Conversa::de((array) $this->estado);
    }

    public function guardar(Conversa $conversa): void
    {
        $this->estado = $conversa->estado();

        $this->renovar()->save();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVencidas(Builder $query): Builder
    {
        return $query->where('expira_em', '<', now());
    }
}
