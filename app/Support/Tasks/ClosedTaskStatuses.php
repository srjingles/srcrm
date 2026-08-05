<?php

declare(strict_types=1);

namespace App\Support\Tasks;

/**
 * Etiquetas del estado de tarea que cuentan como terminales.
 *
 * Punto único desde el que los servicios que solo miran trabajo VIVO (el resumen diario
 * por correo y "mis tareas" del chat) deciden qué excluir. Ver config/tasks.php para el
 * porqué de que sea configurable.
 */
final readonly class ClosedTaskStatuses
{
    /**
     * @return list<string> nunca vacío: si la config queda mal, se cae al estado de serie
     */
    public static function labels(): array
    {
        $labels = array_values(array_filter(
            array_map(strval(...), (array) config('tasks.closed_status_labels', [])),
            static fn (string $label): bool => $label !== '',
        ));

        return $labels === [] ? ['Done'] : $labels;
    }
}
