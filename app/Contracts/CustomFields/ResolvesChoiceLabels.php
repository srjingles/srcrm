<?php

declare(strict_types=1);

namespace App\Contracts\CustomFields;

use App\Http\Resources\V1\Concerns\FormatsCustomFields;

/**
 * Seam de extensión para addons (p. ej. srjingles/sr-crm).
 *
 * Los choice fields se serializan resolviendo el valor guardado contra
 * `custom_field_options`. Un field type cuyas opciones son DINÁMICAS (calculadas en runtime,
 * no filas de esa tabla) no tiene nada que buscar ahí, así que su label caería al id crudo.
 *
 * Quien aporta uno de esos tipos implementa este contrato y lo registra por clave de tipo en
 * config('custom-fields.choice_labelers'), p. ej. ['team_member' => TeamMemberLabeler::class].
 *
 * @see FormatsCustomFields
 */
interface ResolvesChoiceLabels
{
    /**
     * Nombre legible del valor guardado, o null si no se puede resolver (el serializador
     * cae entonces al id, que es el comportamiento de siempre).
     *
     * Recibe mixed porque es el valor crudo del custom field.
     */
    public function labelFor(mixed $rawValue): ?string;
}
