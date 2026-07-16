<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Contracts\ChatEntity;
use Relaticle\Chat\Support\ChatEntities;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Tools\GetCrmSummaryTool;
use Relaticle\Chat\Tools\SearchCrmTool;

// Seam de extensión para addons (p. ej. srjingles/sr-crm): una entidad aportada por un addon
// entra en el recuento del resumen, en la búsqueda global y en la resolución de citas sin que
// el host la conozca. Cada test fija config('chat.extra_entities'): con el addon instalado ya
// viene rellena del register().

final class StubChatEntity implements ChatEntity
{
    public function type(): string
    {
        return 'widget';
    }

    public function key(): string
    {
        return 'widgets';
    }

    public function count(Team $team): int
    {
        return 7;
    }

    /** @return list<array<string, mixed>> */
    public function search(Team $team, string $query, int $limit): array
    {
        return [['id' => 'w1', 'name' => "match for {$query}", 'limit' => $limit]];
    }

    public function urlFor(Team $team, string $recordId): ?string
    {
        return $recordId === 'ghost' ? null : "https://example.test/widgets/{$recordId}";
    }

    public function labelFor(Team $team, string $recordId): ?string
    {
        return $recordId === 'ghost' ? null : "Widget {$recordId}";
    }
}

it('resolves addon entities from config by singular type', function (): void {
    config()->set('chat.extra_entities', [StubChatEntity::class]);

    expect(ChatEntities::all())->toHaveCount(1)
        ->and(ChatEntities::forType('widget'))->toBeInstanceOf(StubChatEntity::class)
        ->and(ChatEntities::forType('company'))->toBeNull();
});

it('announces addon entities in the search tool description', function (): void {
    config()->set('chat.extra_entities', [StubChatEntity::class]);

    // Si no se anuncian, el modelo no sabe que la búsqueda global las cubre.
    expect((new SearchCrmTool)->description())->toContain('widgets');
});

it('adds addon entity counts to the CRM summary', function (): void {
    config()->set('chat.extra_entities', [StubChatEntity::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $summary = json_decode((new GetCrmSummaryTool)->handle(new Request([])), true);

    expect($summary['record_counts']['widgets'])->toBe(7)
        ->and($summary['record_counts'])->toHaveKey('companies');
});

it('adds addon entity results to the global search', function (): void {
    config()->set('chat.extra_entities', [StubChatEntity::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $results = json_decode((new SearchCrmTool)->handle(new Request(['query' => 'acme'])), true);

    expect($results['widgets'])->toBe([['id' => 'w1', 'name' => 'match for acme', 'limit' => 5]])
        ->and($results)->toHaveKey('companies');
});

it('resolves url and label of an addon entity citation', function (): void {
    config()->set('chat.extra_entities', [StubChatEntity::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $ref = resolve(RecordReferenceResolver::class)->resolve('widget', 'w1');

    expect($ref)->toMatchArray([
        'id' => 'w1',
        'type' => 'widget',
        'url' => 'https://example.test/widgets/w1',
        'label' => 'Widget w1',
    ]);
});

it('returns null when an addon entity cannot resolve the record', function (): void {
    config()->set('chat.extra_entities', [StubChatEntity::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    // Sin url no hay cita: el host descarta la referencia entera, no la deja a medias.
    expect(resolve(RecordReferenceResolver::class)->resolve('widget', 'ghost'))->toBeNull();
});

it('leaves the host untouched when no addon registers entities', function (): void {
    config()->set('chat.extra_entities', []);

    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $summary = json_decode((new GetCrmSummaryTool)->handle(new Request([])), true);
    $results = json_decode((new SearchCrmTool)->handle(new Request(['query' => 'acme'])), true);

    expect(array_keys($summary['record_counts']))->toBe(['companies', 'people', 'opportunities', 'tasks', 'notes'])
        ->and(array_keys($results))->toBe(['companies', 'people', 'opportunities', 'tasks', 'notes'])
        ->and((new SearchCrmTool)->description())->not->toContain('widgets')
        ->and(resolve(RecordReferenceResolver::class)->resolve('widget', 'w1'))->toBeNull();
});
