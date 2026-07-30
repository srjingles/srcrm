<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Preferencia de interfaz de un usuario, opcionalmente acotada a un equipo.
 *
 * Vive aparte de la fila de `users` a propósito: `users` se carga en cada petición
 * autenticada, y estas preferencias crecen con cada listado y cada equipo. Aquí se
 * consultan solo en la pantalla que las necesita.
 *
 * @property string $key
 * @property array<array-key, mixed> $value
 * @property string $user_id
 * @property string|null $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'team_id',
    'key',
    'value',
])]
final class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * El valor guardado, o null si el usuario no ha personalizado nada todavía.
     *
     * @return array<array-key, mixed>|null
     */
    public static function lookup(User $user, ?string $teamId, string $key): ?array
    {
        /** @var self|null $preference */
        $preference = self::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $teamId)
            ->where('key', $key)
            ->first();

        return $preference?->value;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
