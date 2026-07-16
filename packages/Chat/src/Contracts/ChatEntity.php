<?php

declare(strict_types=1);

namespace Relaticle\Chat\Contracts;

use App\Models\Team;

/**
 * Seam de extensión para addons (p. ej. srjingles/sr-crm).
 *
 * El chat conoce sus cinco entidades (empresas, contactos, oportunidades, tareas y notas)
 * por lista fija en cuatro sitios: el recuento del resumen, la búsqueda global y la
 * resolución de url/label de las citas. Un addon que aporta una entidad propia implementa
 * este contrato y empuja la clase a config('chat.extra_entities') desde su service provider;
 * el host la recorre en esos cuatro puntos. Sin addon, la lista vacía lo deja idéntico.
 *
 * Las tools de lectura/escritura de la entidad son aparte: van por config('chat.extra_tools').
 */
interface ChatEntity
{
    /**
     * Clave singular con la que se cita un registro de esta entidad, la misma que devuelve
     * el `citationType()` de sus tools (p. ej. 'project').
     */
    public function type(): string;

    /**
     * Clave plural bajo la que la entidad aparece en el recuento del resumen y en los
     * resultados de la búsqueda global (p. ej. 'projects').
     */
    public function key(): string;

    /**
     * Cuántos registros tiene el equipo. Alimenta GetCrmSummaryTool.
     */
    public function count(Team $team): int;

    /**
     * Registros del equipo que casan con la búsqueda por palabra clave, ya serializados.
     * Alimenta SearchCrmTool, que impone el límite.
     *
     * OJO: $query llega ya escapado para LIKE (los comodines que escribiera el usuario son
     * literales). Interpólalo tal cual entre `%` — volver a escaparlo lo rompe.
     *
     * @return list<array<string, mixed>>
     */
    public function search(Team $team, string $query, int $limit): array;

    /**
     * URL de la ficha del registro en el panel, o null si no se puede construir. Es lo que
     * convierte una cita en un enlace profundo.
     */
    public function urlFor(Team $team, string $recordId): ?string;

    /**
     * Nombre legible del registro, o null si no existe. Se usa para rehidratar los enlaces
     * de mensajes antiguos con el nombre actual.
     */
    public function labelFor(Team $team, string $recordId): ?string;
}
