<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    // firstOrCreate y no create: crear el equipo ya siembra la sección 'general' de cada
    // entidad cuando las secciones están activas (las enciende el addon srjingles/sr-crm),
    // y un insert a pelo choca contra custom_field_sections_entity_type_code_tenant_id_unique.
    $section = CustomFieldSection::query()->firstOrCreate(
        [
            'tenant_id' => $this->team->getKey(),
            'entity_type' => 'company',
            'code' => 'general',
        ],
        [
            'name' => 'General',
            'type' => 'section',
            'sort_order' => 0,
            'active' => true,
        ],
    );

    $this->field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'company',
        'code' => 'lead_source',
        'name' => 'Lead source',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);
});

it('logs a custom_field_changes activity when a value is created', function (): void {
    $company = Company::factory()->for($this->team)->create();
    Activity::withoutGlobalScopes()->delete();

    $company->saveCustomFields(['lead_source' => 'referral']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('custom_field_changes')
        ->and($activity->properties['custom_field_changes'][0]['code'])->toBe('lead_source')
        ->and($activity->properties['custom_field_changes'][0]['new']['label'])->toBe('referral')
        ->and($activity->properties['custom_field_changes'][0]['old']['value'])->toBeNull();
});

it('logs a custom_field_changes activity when a value is updated', function (): void {
    $company = Company::factory()->for($this->team)->create();
    $company->saveCustomFields(['lead_source' => 'referral']);
    Activity::withoutGlobalScopes()->delete();

    $company->saveCustomFields(['lead_source' => 'linkedin']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('custom_field_changes')
        ->and($activity->properties['custom_field_changes'][0]['old']['label'])->toBe('referral')
        ->and($activity->properties['custom_field_changes'][0]['new']['label'])->toBe('linkedin');
});

it('renders link-field values as plain URLs, not escaped JSON', function (): void {
    // Código propio del test ('activity_website' y no 'website') para no chocar con
    // custom_fields_code_entity_type_tenant_id_unique: el addon srjingles/sr-crm siembra
    // un 'website' en cada equipo. Lo que se prueba es el log de un campo de tipo link.
    $linkField = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_section_id' => $this->field->custom_field_section_id,
        'entity_type' => 'company',
        'code' => 'activity_website',
        'name' => 'Website',
        'type' => 'link',
        'sort_order' => 2,
        'active' => true,
        'validation_rules' => [],
    ]);

    $company = Company::factory()->for($this->team)->create();
    Activity::withoutGlobalScopes()->delete();

    $company->saveCustomFields(['activity_website' => ['https://www.linkedin.com/company/airbnb']]);

    $activity = Activity::query()->latest('id')->first();
    $change = $activity->properties['custom_field_changes'][0];

    expect($change['code'])->toBe('activity_website')
        ->and($change['new']['label'])->toBe('https://www.linkedin.com/company/airbnb')
        ->and($change['new']['label'])->not->toContain('\\/')
        ->and($change['new']['label'])->not->toContain('[');
});

it('does not log when saving an empty value for a previously empty field', function (): void {
    $company = Company::factory()->for($this->team)->create();
    Activity::withoutGlobalScopes()->delete();

    $company->saveCustomFields(['lead_source' => null]);

    expect(Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->count())->toBe(0);
});

it('does not log a link change that is only a URL-scheme normalization', function (): void {
    // Código propio del test ('activity_website' y no 'website') para no chocar con
    // custom_fields_code_entity_type_tenant_id_unique: el addon srjingles/sr-crm siembra
    // un 'website' en cada equipo. Lo que se prueba es el log de un campo de tipo link.
    $linkField = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_section_id' => $this->field->custom_field_section_id,
        'entity_type' => 'company',
        'code' => 'activity_website',
        'name' => 'Website',
        'type' => 'link',
        'sort_order' => 2,
        'active' => true,
        'validation_rules' => [],
    ]);

    $company = Company::factory()->for($this->team)->create();
    $company->saveCustomFields(['activity_website' => ['https://airbnb.com']]);
    Activity::withoutGlobalScopes()->delete();

    $company->saveCustomFields(['activity_website' => ['airbnb.com']]);

    expect(Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->count())->toBe(0);
});

it('still logs a genuine link value change', function (): void {
    // Código propio del test ('activity_website' y no 'website') para no chocar con
    // custom_fields_code_entity_type_tenant_id_unique: el addon srjingles/sr-crm siembra
    // un 'website' en cada equipo. Lo que se prueba es el log de un campo de tipo link.
    $linkField = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_section_id' => $this->field->custom_field_section_id,
        'entity_type' => 'company',
        'code' => 'activity_website',
        'name' => 'Website',
        'type' => 'link',
        'sort_order' => 2,
        'active' => true,
        'validation_rules' => [],
    ]);

    $company = Company::factory()->for($this->team)->create();
    $company->saveCustomFields(['activity_website' => ['airbnb.com']]);
    Activity::withoutGlobalScopes()->delete();

    $company->saveCustomFields(['activity_website' => ['google.com']]);

    $activity = Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['custom_field_changes'][0]['old']['label'])->toBe('airbnb.com')
        ->and($activity->properties['custom_field_changes'][0]['new']['label'])->toBe('google.com');
});
