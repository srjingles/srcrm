<?php

declare(strict_types=1);

use App\Contracts\CustomFields\DynamicChoiceType;
use App\Http\Resources\V1\Concerns\FormatsCustomFields;
use App\Models\CustomField as CustomFieldModel;
use App\Models\Task;
use App\Models\User;
use App\Rules\ValidCustomFields;
use App\Support\CustomFields\DynamicChoices;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Services\Tools\CustomFieldsSchemaDescriber;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\CustomFieldValue;

// Seam de extensión para addons (p. ej. srjingles/sr-crm): un field type cuyas opciones son
// dinámicas no se resuelve contra custom_field_options, así que su label caería al id crudo.
// Se usa 'select' (tipo nativo) a propósito: el seam despacha por clave de tipo y no debe
// saber nada de los tipos que aporte un addon.

final class StubChoiceLabeler implements DynamicChoiceType
{
    public function labelFor(mixed $rawValue): ?string
    {
        // Responde a todo menos al valor huérfano: así el test de precedencia demuestra de
        // verdad que la opción real gana (si no, el labeler devolvería null y pasaría solo).
        // Y modela lo real: labelFor() resuelve valores que ya no son elegibles.
        return $rawValue === 'deleted-user' ? null : 'Chema Trigueros';
    }

    /** @return array<int|string, string> */
    public function options(string $teamId): array
    {
        return ['known-id' => 'Chema Trigueros'];
    }
}

/**
 * formatCustomFields() es protected: se expone a través de un consumidor mínimo del trait,
 * igual que hacen los JsonResource reales.
 */
function choiceFormatter(): object
{
    return new class
    {
        use FormatsCustomFields;

        public function format(Model $record): stdClass
        {
            return $this->formatCustomFields($record);
        }
    };
}

/** @param  list<CustomFieldOption>  $options */
function taskWithChoiceValue(mixed $raw, array $options = []): Task
{
    $field = new CustomField(['code' => 'owner', 'type' => 'select']);
    $field->setRelation('options', collect($options));

    $value = new CustomFieldValue;
    $value->setRelation('customField', $field);
    $value->setValue($raw);

    $task = new Task;
    $task->setRelation('customFieldValues', collect([$value]));

    return $task;
}

it('falls back to the raw id when nothing can resolve the label', function (): void {
    config()->set('custom-fields.choice_labelers', []);

    $formatted = choiceFormatter()->format(taskWithChoiceValue('known-id'));

    // Comportamiento histórico: sin resolutor, el label es el id. Feo, pero no se rompe.
    expect($formatted->owner)->toBe(['id' => 'known-id', 'label' => 'known-id']);
});

it('resolves the label through a labeler registered for the field type', function (): void {
    config()->set('custom-fields.choice_labelers', ['select' => StubChoiceLabeler::class]);

    $formatted = choiceFormatter()->format(taskWithChoiceValue('known-id'));

    expect($formatted->owner)->toBe(['id' => 'known-id', 'label' => 'Chema Trigueros']);
});

it('falls back to the raw id when the labeler cannot resolve the value', function (): void {
    config()->set('custom-fields.choice_labelers', ['select' => StubChoiceLabeler::class]);

    $formatted = choiceFormatter()->format(taskWithChoiceValue('deleted-user'));

    expect($formatted->owner)->toBe(['id' => 'deleted-user', 'label' => 'deleted-user']);
});

it('prefers a real option over the labeler', function (): void {
    config()->set('custom-fields.choice_labelers', ['select' => StubChoiceLabeler::class]);

    // CustomFieldOption castea `id` a int: las opciones de verdad son numéricas y el valor
    // guardado llega como string, así que el match de la tabla es laxo a propósito. Un
    // team_member guarda un ULID, que nunca puede casar aquí -- de ahí el labeler.
    $option = new CustomFieldOption;
    $option->forceFill(['id' => 42, 'name' => 'Alta']);

    $formatted = choiceFormatter()->format(taskWithChoiceValue('42', [$option]));

    // Las opciones de la tabla mandan: el labeler solo cubre lo que no está ahí.
    expect($formatted->owner)->toBe(['id' => '42', 'label' => 'Alta']);
});

it('ignores labelers registered for other field types', function (): void {
    config()->set('custom-fields.choice_labelers', ['team_member' => StubChoiceLabeler::class]);

    $formatted = choiceFormatter()->format(taskWithChoiceValue('known-id'));

    expect($formatted->owner)->toBe(['id' => 'known-id', 'label' => 'known-id']);
});

// --- Regla de precedencia (vive en un solo sitio: DynamicChoices::forField) ---

it('only consults the provider when the field has no options in the table', function (): void {
    config()->set('custom-fields.choice_labelers', ['select' => StubChoiceLabeler::class]);

    $withoutOptions = new CustomField(['code' => 'owner', 'type' => 'select']);
    $withoutOptions->setRelation('options', collect());

    $option = new CustomFieldOption;
    $option->forceFill(['id' => 42, 'name' => 'Alta']);
    $withOptions = new CustomField(['code' => 'priority', 'type' => 'select']);
    $withOptions->setRelation('options', collect([$option]));

    $unregistered = new CustomField(['code' => 'stage', 'type' => 'radio']);
    $unregistered->setRelation('options', collect());

    expect(DynamicChoices::forField($withoutOptions))->toBeInstanceOf(StubChoiceLabeler::class)
        ->and(DynamicChoices::forField($withOptions))->toBeNull()
        ->and(DynamicChoices::forField($unregistered))->toBeNull();
});

// --- Consumidores de escritura: sin esto el campo es inescribible ---

/** Un choice field SIN opciones en tabla, que es la marca de un tipo dinámico. */
function dynamicField(User $user): CustomFieldModel
{
    $tenantKey = (string) config('custom-fields.database.column_names.tenant_foreign_key');

    return CustomFieldModel::factory()->create([
        $tenantKey => $user->currentTeam->getKey(),
        'entity_type' => 'task',
        'code' => 'owner',
        'name' => 'Owner',
        'type' => 'select',
        'system_defined' => false,
        'active' => true,
    ]);
}

it('announces the provider options to the model', function (): void {
    config()->set('custom-fields.choice_labelers', ['select' => StubChoiceLabeler::class]);

    $user = User::factory()->withPersonalTeam()->create();
    dynamicField($user);

    $described = resolve(CustomFieldsSchemaDescriber::class)->describe($user->currentTeam, 'task');

    // Sin esto el modelo no vería ni un valor válido y no podría rellenar el campo.
    expect($described)->toContain('owner (single-choice, one of: "Chema Trigueros")');
});

it('translates a dynamic option label into its id', function (): void {
    config()->set('custom-fields.choice_labelers', ['select' => StubChoiceLabeler::class]);

    $user = User::factory()->withPersonalTeam()->create();
    dynamicField($user);

    $result = resolve(CustomFieldsRequestValidator::class)->validate($user, 'task', ['owner' => 'Chema Trigueros']);

    // El modelo manda el NOMBRE, como en cualquier otro choice field: nunca ve un id.
    expect($result->error)->toBeNull()
        ->and($result->cleanFields)->toBe(['owner' => 'known-id']);
});

it('rejects a label the provider does not offer', function (): void {
    config()->set('custom-fields.choice_labelers', ['select' => StubChoiceLabeler::class]);

    $user = User::factory()->withPersonalTeam()->create();
    dynamicField($user);

    $result = resolve(CustomFieldsRequestValidator::class)->validate($user, 'task', ['owner' => 'Fulano']);

    expect($result->error)->toContain('is not one of the configured choices');
});

it('keeps Rule::in closed, sourcing the ids from the provider', function (): void {
    config()->set('custom-fields.choice_labelers', ['select' => StubChoiceLabeler::class]);

    $user = User::factory()->withPersonalTeam()->create();
    dynamicField($user);

    $rules = new ValidCustomFields((string) $user->currentTeam->getKey(), 'task', isUpdate: true)
        ->toRules(['owner' => 'known-id']);

    // Este es el guardián de MCP y de la API REST, no solo del chat: antes era Rule::in([])
    // y rechazaba TODO. Ahora acepta lo que el proveedor declara -- y solo eso.
    expect(Validator::make(['custom_fields' => ['owner' => 'known-id']], $rules)->fails())->toBeFalse()
        ->and(Validator::make(['custom_fields' => ['owner' => 'otro-cualquiera']], $rules)->fails())->toBeTrue();
});

it('leaves fields without a provider rejecting everything, as before', function (): void {
    config()->set('custom-fields.choice_labelers', []);

    $user = User::factory()->withPersonalTeam()->create();
    dynamicField($user);

    $rules = new ValidCustomFields((string) $user->currentTeam->getKey(), 'task', isUpdate: true)
        ->toRules(['owner' => 'known-id']);

    expect(Validator::make(['custom_fields' => ['owner' => 'known-id']], $rules)->fails())->toBeTrue();
});
