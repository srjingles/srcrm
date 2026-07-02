<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/*
 | Comando sr-crm:sync-custom-fields para equipos existentes (instalación/upgrade).
 | Reutiliza el mismo motor (CustomFieldSynchronizer) que el listener, así que
 | equipos nuevos y existentes convergen al mismo estado de forma idempotente.
 */

it('es idempotente: re-ejecutar no crea duplicados ni falla', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $whatsapps = fn (): int => CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $team->id)->where('code', 'whatsapp')->count();

    expect($whatsapps())->toBe(1); // creado por el listener

    $exit = Artisan::call('sr-crm:sync-custom-fields', ['--team' => $team->id]);

    expect($exit)->toBe(0)
        ->and($whatsapps())->toBe(1); // sigue habiendo exactamente uno
});

it('en --dry-run no modifica nada', function (): void {
    // Creamos el equipo SIN aplicar removals para que linkedin quede ACTIVO y así
    // el dry-run tenga algo que (no) cambiar.
    config(['srcrm.custom_fields.apply_removals' => false]);
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $linkedinActivo = fn (): int => CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $team->id)->where('entity_type', 'people')
        ->where('code', 'linkedin')->where('active', true)->count();

    expect($linkedinActivo())->toBe(1);

    // Con removals activas pero en dry-run: previsualiza (would_deactivate) sin tocar BD.
    config(['srcrm.custom_fields.apply_removals' => true]);
    $exit = Artisan::call('sr-crm:sync-custom-fields', ['--team' => $team->id, '--dry-run' => true]);

    expect($exit)->toBe(0)
        ->and($linkedinActivo())->toBe(1); // dry-run no toca nada: sigue activo
});

it('reconcilia el nombre de un campo existente al re-sincronizar', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $whatsapp = fn (): CustomField => CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $team->id)->where('code', 'whatsapp')->firstOrFail();

    // Simulamos un nombre desalineado con el blueprint (p. ej. creado en otro locale).
    $whatsapp()->forceFill(['name' => 'Desalineado'])->saveQuietly();
    expect($whatsapp()->name)->toBe('Desalineado');

    Artisan::call('sr-crm:sync-custom-fields', ['--team' => $team->id]);

    // El blueprint manda: el nombre vuelve al valor traducido (locale en en tests).
    expect($whatsapp()->name)->toBe('WhatsApp');
});

it('con --prune elimina de la BD los campos a quitar', function (): void {
    // Creamos el equipo SIN aplicar removals para que linkedin quede presente.
    config(['srcrm.custom_fields.apply_removals' => false]);
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $linkedin = fn (): int => CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $team->id)->where('entity_type', 'people')
        ->where('code', 'linkedin')->count();

    expect($linkedin())->toBe(1);

    config(['srcrm.custom_fields.apply_removals' => true]);
    $exit = Artisan::call('sr-crm:sync-custom-fields', [
        '--team' => $team->id,
        '--prune' => true,
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($linkedin())->toBe(0); // eliminado de la base de datos
});
