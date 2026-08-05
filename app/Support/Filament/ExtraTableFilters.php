<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Contracts\Filament\ProvidesTableFilters;
use Filament\Tables\Filters\BaseFilter;

/**
 * Reúne los filtros extra que los addons declaran para el listado de un modelo.
 *
 * Sin addons registrados devuelve un array vacío y los listados quedan exactamente igual.
 * Ver App\Contracts\Filament\ProvidesTableFilters para el porqué del seam.
 */
final readonly class ExtraTableFilters
{
    /**
     * @param  class-string  $model
     * @return array<BaseFilter>
     */
    public static function for(string $model): array
    {
        /** @var array<class-string, array<class-string>> $registered */
        $registered = config('filament.extra_table_filters', []);

        $filters = [];

        foreach ($registered[$model] ?? [] as $provider) {
            $instance = app($provider);

            if (! $instance instanceof ProvidesTableFilters) {
                continue;
            }

            foreach ($instance->filters() as $filter) {
                $filters[] = $filter;
            }
        }

        return $filters;
    }
}
