<?php

declare(strict_types=1);

namespace App\Contracts\CustomFields;

/**
 * Seam de extensión para addons: convierte el valor CRUDO de un campo personalizado en la
 * etiqueta que se le enseña a una persona.
 *
 * El historial de actividad guarda el valor tal cual está en la columna. Para los tipos de
 * campo nativos eso ya se lee (un texto, una fecha, la etiqueta de una opción), pero un
 * tipo con representación propia —una duración en minutos, por ejemplo— acaba pintando su
 * entraña: la línea de tiempo dice «300 → 180» en vez de «5h → 3h».
 *
 * Quien aporta uno de esos tipos implementa este contrato y lo registra por clave de tipo
 * en config('custom-fields.value_labelers'), p. ej. ['duration' => DurationLabeler::class].
 * Si no hay nadie registrado para el tipo, se sigue usando el valor crudo, así que sin
 * addons no cambia nada.
 *
 * Es hermano de DynamicChoiceType, que resuelve lo mismo para los campos de elección cuyas
 * opciones no están en tabla.
 */
interface FormatsFieldValue
{
    /**
     * Etiqueta legible del valor, o null para dejar que decida quien llama.
     */
    public function labelFor(mixed $value): ?string;
}
