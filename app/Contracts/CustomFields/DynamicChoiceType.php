<?php

declare(strict_types=1);

namespace App\Contracts\CustomFields;

use App\Support\CustomFields\DynamicChoices;

/**
 * Seam de extensión para addons (p. ej. srjingles/sr-crm).
 *
 * Un choice field normal saca sus opciones de `custom_field_options`: contra esa tabla se
 * describe al modelo, se valida lo que envía, se pinta la propuesta y se serializa el valor.
 * Un field type cuyas opciones son DINÁMICAS (calculadas en runtime, p. ej. los miembros del
 * equipo) no tiene filas ahí, así que sin este contrato queda roto en las cuatro: el modelo no
 * sabe qué valores existen, `Rule::in([])` rechaza todos, y los labels caen al id crudo.
 *
 * Quien aporta uno de esos tipos implementa este contrato y lo registra por clave de tipo en
 * config('custom-fields.choice_labelers'), p. ej. ['team_member' => TeamMemberLabeler::class].
 * El proveedor solo se consulta cuando el campo NO tiene opciones en tabla — ver DynamicChoices.
 *
 * @see DynamicChoices  el resolutor y la regla de precedencia
 */
interface DynamicChoiceType
{
    /**
     * Nombre legible del valor guardado, o null si no se puede resolver (quien llama cae
     * entonces al id, que es el comportamiento de siempre).
     *
     * Resuelve CUALQUIER valor ya guardado, aunque hoy no sea elegible: un miembro que dejó el
     * equipo debe seguir mostrando su nombre en los registros antiguos. Por eso no basta con
     * mirar en options().
     *
     * Recibe mixed porque es el valor crudo del custom field.
     */
    public function labelFor(mixed $rawValue): ?string;

    /**
     * Lo elegible AHORA para ese equipo, como `[id => label]`. Alimenta la descripción que ve
     * el modelo, la traducción de label→id de lo que envía, y el `Rule::in` que lo valida.
     *
     * Recibe el id del equipo en vez de leer el tenant de Filament: esto corre también en el
     * job del chat, donde no hay tenant de Filament que valga.
     *
     * @return array<int|string, string>
     */
    public function options(string $teamId): array;
}
