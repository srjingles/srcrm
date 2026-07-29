<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Contracts\CustomFields\DynamicChoiceType;
use App\Models\CustomField;
use App\Models\User;
use App\Rules\ValidCustomFields;
use App\Support\CustomFields\DynamicChoices;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Validator;
use Relaticle\CustomFields\Facades\CustomFieldsType;

final readonly class CustomFieldsRequestValidator
{
    /**
     * Validate the LLM-submitted custom_fields payload for the given entity.
     *
     * Translates option labels into option IDs for choice fields, then runs
     * the same `ValidCustomFields` rule the MCP tools use. Returns a result
     * with either a clean payload (keys by code, values normalized for the
     * action layer) or an error string suitable for tool output.
     */
    public function validate(User $user, string $entityType, mixed $rawCustomFields): CustomFieldsValidationResult
    {
        if (! is_array($rawCustomFields) || $rawCustomFields === []) {
            return new CustomFieldsValidationResult(cleanFields: [], error: null);
        }

        $teamId = $user->currentTeam->getKey();

        $fields = $this->loadFields($teamId, $entityType, array_keys($rawCustomFields));

        $translated = $this->translateLabels($rawCustomFields, $fields, $teamId);

        if ($translated->error !== null) {
            return $translated;
        }

        $rules = new ValidCustomFields($teamId, $entityType, isUpdate: true)
            ->toRules($translated->cleanFields);

        $validator = Validator::make(['custom_fields' => $translated->cleanFields], $rules);

        if ($validator->fails()) {
            return new CustomFieldsValidationResult(
                cleanFields: [],
                error: 'custom_fields validation failed: '.implode('; ', $validator->errors()->all()),
            );
        }

        return new CustomFieldsValidationResult(cleanFields: $translated->cleanFields, error: null);
    }

    /**
     * @param  array<int, string>  $codes
     * @return Collection<int, CustomField>
     */
    private function loadFields(string $teamId, string $entityType, array $codes): Collection
    {
        /** @var Collection<int, CustomField> */
        return CustomField::query()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', $codes)
            ->with('options')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  Collection<int, CustomField>  $fields
     */
    private function translateLabels(array $raw, Collection $fields, string $teamId): CustomFieldsValidationResult
    {
        $clean = [];
        $byCode = $fields->keyBy('code');

        foreach ($raw as $code => $value) {
            $field = $byCode->get($code);

            if (! $field instanceof CustomField) {
                $clean[$code] = $value;

                continue;
            }

            $typeData = CustomFieldsType::getFieldType($field->type);
            $dataType = $typeData?->dataType;

            if ($dataType === null || ! $dataType->isChoiceField()) {
                $clean[$code] = $value;

                continue;
            }

            if ($typeData->acceptsArbitraryValues || $field->lookup_type !== null) {
                $clean[$code] = $value;

                continue;
            }

            $idsByLabel = $this->idsByLabel($field, $teamId);

            if ($dataType->isMultiChoiceField()) {
                if (! is_array($value)) {
                    return new CustomFieldsValidationResult(
                        cleanFields: [],
                        error: "custom_fields.{$code} must be an array of option labels.",
                    );
                }

                $translated = [];
                foreach ($value as $label) {
                    $id = $idsByLabel->get((string) $label);
                    if ($id === null) {
                        return new CustomFieldsValidationResult(
                            cleanFields: [],
                            error: "custom_fields.{$code} option \"{$label}\" is not one of the configured choices.",
                        );
                    }
                    $translated[] = $id;
                }

                $clean[$code] = $translated;

                continue;
            }

            if (! is_string($value) && ! is_int($value)) {
                return new CustomFieldsValidationResult(
                    cleanFields: [],
                    error: "custom_fields.{$code} must be a single option label string.",
                );
            }

            $id = $idsByLabel->get((string) $value);
            if ($id === null) {
                return new CustomFieldsValidationResult(
                    cleanFields: [],
                    error: "custom_fields.{$code} option \"{$value}\" is not one of the configured choices.",
                );
            }

            $clean[$code] = $id;
        }

        return new CustomFieldsValidationResult(cleanFields: $clean, error: null);
    }

    /**
     * Mapa label→id de las opciones del campo.
     *
     * Seam de addons: un field type con opciones dinámicas no las tiene en tabla, las aporta su
     * proveedor. Traduciéndolas aquí, el modelo sigue mandando el NOMBRE como en cualquier otro
     * choice field y nunca ve un id — que es lo que exige la regla 6 del prompt.
     *
     * @return SupportCollection<string, int|string>
     */
    private function idsByLabel(CustomField $field, string $teamId): SupportCollection
    {
        $provider = DynamicChoices::forField($field);

        if ($provider instanceof DynamicChoiceType) {
            /** @var SupportCollection<string, int|string> */
            return collect($provider->options($teamId))->flip();
        }

        /** @var SupportCollection<string, int|string> */
        return $field->options->pluck('id', 'name');
    }
}
