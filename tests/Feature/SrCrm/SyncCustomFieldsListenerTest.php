<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\User;

/*
 | Integración del addon srjingles/sr-crm: al crear un equipo, el listener
 | SeedTeamCustomFields aplica el SrCrmFieldBlueprint (ensure + remove) justo
 | después del listener base de Relaticle (CreateTeamCustomFields), de modo que
 | los campos nativos ya existen. Con la config por defecto
 | (srcrm.custom_fields.apply_removals = true) las eliminaciones se aplican como
 | desactivaciones (no borrado). Ver SrCrmFieldBlueprint / CustomFieldSynchronizer.
 */

it('añade el teléfono "WhatsApp" a People justo después de phone_number', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $whatsapp = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->id)
        ->where('entity_type', 'people')
        ->where('code', 'whatsapp')
        ->first();

    expect($whatsapp)->not->toBeNull()
        ->and($whatsapp->name)->toBe('WhatsApp') // locale en en tests
        ->and($whatsapp->type)->toBe('phone')
        ->and((bool) $whatsapp->active)->toBeTrue();

    // Orden dentro de la sección: whatsapp debe ir inmediatamente tras phone_number.
    $order = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->id)
        ->where('entity_type', 'people')
        ->where('custom_field_section_id', $whatsapp->custom_field_section_id)
        ->orderBy('sort_order')
        ->pluck('code')
        ->all();

    $phonePos = array_search('phone_number', $order, true);
    $whatsappPos = array_search('whatsapp', $order, true);

    expect($phonePos)->not->toBeFalse()
        ->and($whatsappPos)->toBe($phonePos + 1);
});

it('añade el select "Client Status" a Company con sus opciones del blueprint', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $clientStatus = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->id)
        ->where('entity_type', 'company')
        ->where('code', 'client_status')
        ->first();

    expect($clientStatus)->not->toBeNull()
        ->and($clientStatus->type)->toBe('select')
        ->and((bool) $clientStatus->active)->toBeTrue()
        ->and(
            $clientStatus->options()->withoutGlobalScopes()->orderBy('sort_order')->pluck('name')->all()
        )->toBe([
            'Lead / Prospecto',
            'Cliente Activo - Proyecto',
            'Cliente Activo - Fee/Recurrente',
            'Ex-Cliente / Inactivo',
        ]);
});

it('añade el "IBAN" cifrado a Company al crear el equipo', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $iban = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->id)
        ->where('entity_type', 'company')
        ->where('code', 'iban')
        ->first();

    expect($iban)->not->toBeNull()
        ->and($iban->type)->toBe('text')
        ->and((bool) $iban->active)->toBeTrue();
});

it('desactiva (no borra) "linkedin" en People al crear el equipo', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $linkedin = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->id)
        ->where('entity_type', 'people')
        ->where('code', 'linkedin')
        ->first();

    expect($linkedin)->not->toBeNull()
        ->and((bool) $linkedin->active)->toBeFalse();
});

it('desactiva (no borra) "domains" en Company al crear el equipo', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->personalTeam();

    $domains = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    expect($domains)->not->toBeNull()
        ->and((bool) $domains->active)->toBeFalse();
});
