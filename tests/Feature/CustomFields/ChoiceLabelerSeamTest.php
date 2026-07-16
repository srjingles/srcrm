<?php

declare(strict_types=1);

use App\Contracts\CustomFields\ResolvesChoiceLabels;
use App\Http\Resources\V1\Concerns\FormatsCustomFields;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\CustomFieldValue;

// Seam de extensión para addons (p. ej. srjingles/sr-crm): un field type cuyas opciones son
// dinámicas no se resuelve contra custom_field_options, así que su label caería al id crudo.
// Se usa 'select' (tipo nativo) a propósito: el seam despacha por clave de tipo y no debe
// saber nada de los tipos que aporte un addon.

final class StubChoiceLabeler implements ResolvesChoiceLabels
{
    public function labelFor(mixed $rawValue): ?string
    {
        // Responde a todo menos al valor huérfano: así el test de precedencia demuestra de
        // verdad que la opción real gana (si no, el labeler devolvería null y pasaría solo).
        return $rawValue === 'deleted-user' ? null : 'Chema Trigueros';
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
