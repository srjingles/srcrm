<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Contracts\CustomFields\DynamicChoiceType;
use Relaticle\CustomFields\Models\CustomField;

/**
 * Resuelve los proveedores de opciones dinámicas que los addons registran en
 * config('custom-fields.choice_labelers').
 *
 * @see DynamicChoiceType
 */
final class DynamicChoices
{
    /**
     * El proveedor del campo, o null si no aplica.
     *
     * Aquí vive la regla de precedencia, en un solo sitio: **las opciones de la tabla mandan**.
     * Solo se consulta al proveedor cuando el campo no tiene ninguna, que es la marca de un
     * tipo enteramente dinámico. Así un select normal nunca se ve afectado, aunque alguien
     * registrase un proveedor para su clave de tipo.
     */
    public static function forField(CustomField $field): ?DynamicChoiceType
    {
        if ($field->options->isNotEmpty()) {
            return null;
        }

        return self::forType($field->type);
    }

    public static function forType(string $type): ?DynamicChoiceType
    {
        /** @var array<string, class-string<DynamicChoiceType>> $providers */
        $providers = config('custom-fields.choice_labelers', []);

        $provider = $providers[$type] ?? null;

        return $provider === null ? null : resolve($provider);
    }
}
