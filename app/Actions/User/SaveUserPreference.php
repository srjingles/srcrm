<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use App\Models\UserPreference;

final readonly class SaveUserPreference
{
    /**
     * Guarda (o reemplaza) una preferencia de interfaz del usuario.
     *
     * El ámbito es el equipo: `$teamId` a null guarda una preferencia global del
     * usuario. No hay comprobación de permisos porque un usuario solo puede escribir
     * las suyas — el llamante pasa el usuario autenticado, nunca un id de la petición.
     *
     * @param  array<array-key, mixed>  $value
     */
    public function execute(User $user, ?string $teamId, string $key, array $value): void
    {
        UserPreference::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'team_id' => $teamId,
                'key' => $key,
            ],
            ['value' => $value],
        );
    }
}
