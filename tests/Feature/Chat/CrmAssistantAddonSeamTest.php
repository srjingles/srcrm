<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;

// Seam de extensión para addons (p. ej. srjingles/sr-crm): el addon aporta las tools de sus
// propios módulos y el texto que se los enseña al modelo empujando config desde su service
// provider, sin tocar CrmAssistant ni sustituirlo. Cada test fija la config de partida: con
// el addon instalado, las claves ya vienen rellenas del register().

final class AddonSeamStubTool implements Tool
{
    public function description(): string
    {
        return 'Stub tool contributed by an addon.';
    }

    public function handle(Request $request): string
    {
        return '{}';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/** @return list<class-string> */
function toolClassNames(CrmAssistant $agent): array
{
    return array_map(fn (Tool $tool): string => $tool::class, $agent->tools());
}

it('appends tools an addon pushed to chat.extra_tools', function (): void {
    config()->set('chat.extra_tools', []);
    $native = toolClassNames(new CrmAssistant);

    config()->set('chat.extra_tools', [AddonSeamStubTool::class]);
    $extended = toolClassNames(new CrmAssistant);

    expect($extended)->toBe([...$native, AddonSeamStubTool::class]);
});

it('dedupes tool classes so a duplicated push cannot break the request', function (): void {
    // Regresión de un fallo REAL en producción (2026-07-16): `config:cache` arranca la app, así
    // que el register() del addon empuja sus tools Y config:cache las serializa; luego, en cada
    // job, Laravel carga esa caché (que ya las trae) y register() las empuja otra vez. Dos tools
    // con el mismo nombre ⇒ el proveedor rechaza la petición entera con 400 y el chat no
    // responde. El addon ya empuja de forma idempotente; esto es el cinturón del host.
    config()->set('chat.extra_tools', [AddonSeamStubTool::class, AddonSeamStubTool::class]);

    $names = toolClassNames(new CrmAssistant);

    expect(array_count_values($names)[AddonSeamStubTool::class])->toBe(1)
        ->and($names)->toBe(array_unique($names));
});

it('joins the instruction blocks of every addon', function (): void {
    // El seam es un mapa CON CLAVE, no una cadena: así empujar es idempotente aunque el
    // register() del addon corra dos veces (mismo motivo que arriba).
    config()->set('chat.extra_instructions', ['sr-crm' => '## Projects', 'otro' => '## Widgets']);

    $agent = new CrmAssistant;

    // Dentro del prompt estático a propósito: es fijo por despliegue, así que viaja en el
    // prefijo cacheado de Anthropic junto al resto de las instrucciones.
    expect($agent->staticInstructions())->toEndWith("\n\n## Projects\n\n## Widgets")
        ->and($agent->instructions())->toContain('## Projects');
});

it('leaves the agent untouched when no addon pushes config', function (): void {
    config()->set('chat.extra_tools', []);
    config()->set('chat.extra_instructions', []);

    $agent = new CrmAssistant;

    expect(toolClassNames($agent))->not->toContain(AddonSeamStubTool::class)
        ->and($agent->staticInstructions())->toEndWith('refer to it by name only without a link.');
});
