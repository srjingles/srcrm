<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Contracts\CustomFields\FormatsFieldValue;

/**
 * Resuelve la etiqueta legible de un valor consultando lo que los addons registren en
 * config('custom-fields.value_labelers'), por clave de tipo de campo.
 *
 * Sin nadie registrado devuelve null y quien llama se queda con el valor crudo de siempre.
 * Ver App\Contracts\CustomFields\FormatsFieldValue.
 */
final readonly class FieldValueLabels
{
    public static function for(string $fieldType, mixed $value): ?string
    {
        /** @var array<string, class-string> $labelers */
        $labelers = config('custom-fields.value_labelers', []);

        $labeler = $labelers[$fieldType] ?? null;

        if ($labeler === null) {
            return null;
        }

        $instance = app($labeler);

        return $instance instanceof FormatsFieldValue ? $instance->labelFor($value) : null;
    }
}
