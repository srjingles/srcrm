<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Relaticle\Chat\Contracts\ChatEntity;

/**
 * Resuelve las entidades que los addons aportan al chat por config('chat.extra_entities').
 *
 * @see ChatEntity
 */
final readonly class ChatEntities
{
    /**
     * @return list<ChatEntity>
     */
    public static function all(): array
    {
        /** @var array<int, class-string<ChatEntity>> $classes */
        $classes = config('chat.extra_entities', []);

        return array_values(array_map(
            static fn (string $class): ChatEntity => resolve($class),
            $classes,
        ));
    }

    /**
     * La entidad cuya clave singular es $type, o null si ningún addon la aporta.
     */
    public static function forType(string $type): ?ChatEntity
    {
        foreach (self::all() as $entity) {
            if ($entity->type() === $type) {
                return $entity;
            }
        }

        return null;
    }
}
