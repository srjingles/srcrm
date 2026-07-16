<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Concerns;

use App\Contracts\CustomFields\ResolvesChoiceLabels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldValue;

trait FormatsCustomFields
{
    protected function formatCustomFields(Model $record): \stdClass
    {
        if (! $record->relationLoaded('customFieldValues')) {
            return new \stdClass;
        }

        $result = $record->getRelation('customFieldValues')
            // Skip orphaned values whose custom field was deleted: the eager-loaded relation is null.
            ->filter(fn (CustomFieldValue $fieldValue): bool => isset($fieldValue->getRelations()['customField']))
            ->mapWithKeys(fn (CustomFieldValue $fieldValue): array => [
                $fieldValue->customField->code => $this->resolveFieldValue($fieldValue),
            ])
            ->all();

        return (object) $result;
    }

    private function resolveFieldValue(CustomFieldValue $fieldValue): mixed
    {
        $customField = $fieldValue->customField;
        $rawValue = $fieldValue->getValue();

        if (! $customField->typeData->dataType->isChoiceField()) {
            return $rawValue;
        }

        if ($customField->typeData->dataType->isMultiChoiceField()) {
            return $this->resolveMultiChoiceValue($customField, $rawValue);
        }

        return $this->resolveSingleChoiceValue($customField, $rawValue);
    }

    /**
     * @return array{id: string, label: string}|null
     */
    private function resolveSingleChoiceValue(CustomField $customField, mixed $rawValue): ?array
    {
        if ($rawValue === null) {
            return null;
        }

        $option = $customField->options->firstWhere('id', $rawValue);

        return [
            'id' => (string) $rawValue,
            'label' => $option !== null
                ? $option->name
                : ($this->resolveDynamicLabel($customField, $rawValue) ?? (string) $rawValue),
        ];
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    private function resolveMultiChoiceValue(CustomField $customField, mixed $rawValue): array
    {
        $values = $rawValue instanceof Collection ? $rawValue->all() : (array) ($rawValue ?? []);

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) || is_numeric($value))
            ->map(function (mixed $optionId) use ($customField): array {
                $stringId = (string) $optionId;
                $option = $customField->options->firstWhere('id', $optionId);

                return [
                    'id' => $stringId,
                    'label' => $option !== null
                        ? $option->name
                        : ($this->resolveDynamicLabel($customField, $optionId) ?? $stringId),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Seam de extensión para addons (p. ej. srjingles/sr-crm).
     *
     * Un field type cuyas opciones son dinámicas (no viven en `custom_field_options`) no se
     * puede resolver contra $customField->options, así que sin esto su label sería el id
     * crudo. El addon registra un resolutor por clave de tipo en
     * config('custom-fields.choice_labelers'). Sin addon, el mapa vacío deja el fallback al
     * id de siempre.
     *
     * @see ResolvesChoiceLabels
     */
    private function resolveDynamicLabel(CustomField $customField, mixed $rawValue): ?string
    {
        /** @var array<string, class-string<ResolvesChoiceLabels>> $labelers */
        $labelers = config('custom-fields.choice_labelers', []);

        $labeler = $labelers[$customField->type] ?? null;

        if ($labeler === null) {
            return null;
        }

        $label = resolve($labeler)->labelFor($rawValue);

        return $label === '' ? null : $label;
    }
}
