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

it('appends instructions an addon pushed to chat.extra_instructions', function (): void {
    config()->set('chat.extra_instructions', '## Projects');

    $agent = new CrmAssistant;

    // Dentro del prompt estático a propósito: es fijo por despliegue, así que viaja en el
    // prefijo cacheado de Anthropic junto al resto de las instrucciones.
    expect($agent->staticInstructions())->toEndWith("\n\n## Projects")
        ->and($agent->instructions())->toContain('## Projects');
});

it('leaves the agent untouched when no addon pushes config', function (): void {
    config()->set('chat.extra_tools', []);
    config()->set('chat.extra_instructions', '');

    $agent = new CrmAssistant;

    expect(toolClassNames($agent))->not->toContain(AddonSeamStubTool::class)
        ->and($agent->staticInstructions())->toEndWith('refer to it by name only without a link.');
});
