<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Team;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite DESPUÉS de que CreateTeamCustomFields haya sembrado los custom fields base
 * (y, en su caso, los datos de onboarding) de un equipo recién creado.
 *
 * Seam de extensión: permite a addons (p. ej. srjingles/sr-crm) reaccionar con el orden
 * garantizado —los campos base ya existen— sin depender del orden de los listeners de
 * Jetstream\Events\TeamCreated.
 */
final readonly class TeamCustomFieldsCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Team $team) {}
}
