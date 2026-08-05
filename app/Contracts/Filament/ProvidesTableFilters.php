<?php

declare(strict_types=1);

namespace App\Contracts\Filament;

use Filament\Tables\Filters\BaseFilter;

/**
 * Seam de extensión para addons: aporta filtros extra al listado de un recurso.
 *
 * Un `Table::filters([...])` REEMPLAZA la lista, y `Table::configureUsing()` corre dentro
 * de `Table::make()` —o sea, ANTES de que el recurso llame a `filters()`—, así que un
 * paquete externo no tiene forma de añadir un filtro sin sustituir el recurso entero. Este
 * contrato es esa forma: se implementa, se registra en
 * `config('filament.extra_table_filters')` bajo la clase del modelo, y el recurso lo
 * fusiona con `pushFilters()`.
 *
 * Se resuelve por el contenedor, así que puede pedir dependencias por constructor.
 */
interface ProvidesTableFilters
{
    /**
     * @return array<BaseFilter>
     */
    public function filters(): array;
}
