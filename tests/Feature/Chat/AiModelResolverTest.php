<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Support\ModelDescriptor;

mutates(AiModelResolver::class, ModelRegistry::class, ModelDescriptor::class);

it('falls back to Sonnet when the users preference is not allowed by their plan', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->ai_preferences = ['default_model' => 'claude-opus'];
    $user->save();
    $user->refresh();

    $resolved = resolve(AiModelResolver::class)->resolve($user, null);

    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('honors the users preference when their plan allows it', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->currentTeam->plan = Plan::Pro;
    $user->currentTeam->save();
    $user->ai_preferences = ['default_model' => 'claude-opus'];
    $user->save();
    $user->refresh();

    $resolved = resolve(AiModelResolver::class)->resolve($user, null);

    expect($resolved['model'])->toBe('claude-opus-4-7');
});

it('falls back to Sonnet when an override is disallowed by the plan', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'gpt-5-5');

    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('resolves Auto to Sonnet for any plan', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('falls back to ClaudeSonnet when a Gemini model is requested', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $user->currentTeam->forceFill(['plan' => Plan::Pro])->save();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'gemini-3-flash');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('resolves an explicit Ollama request when Ollama is configured', function (): void {
    config()->set('chat.models.6.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'ollama');

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('qwen3:14b');
});

it('falls back to Sonnet when Ollama is requested but not configured', function (): void {
    config()->set('chat.models.6.model', null);
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'ollama');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('resolves Auto to Ollama when no cloud provider is configured', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.openai.key', null);
    config()->set('chat.models.6.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('qwen3:14b');
});

it('resolves Auto to Sonnet when Anthropic is configured alongside Ollama', function (): void {
    config()->set('chat.models.6.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('falls back to an available plan-gated model when the plan allows no configured provider', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('chat.models.6.model', null);
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('openai');
    expect($resolved['model'])->toBe('gpt-5.5');
});

it('falls back to Sonnet when no provider is configured at all', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.openai.key', null);
    config()->set('chat.models.6.model', null);
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('honors an Ollama default-model preference when configured', function (): void {
    config()->set('chat.models.6.model', 'llama3.1:70b');
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();
    $user->ai_preferences = ['default_model' => 'ollama'];
    $user->save();
    $user->refresh();

    $resolved = resolve(AiModelResolver::class)->resolve($user, null);

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('llama3.1:70b');
});

it('throws a clear error when no chat model is configured', function (): void {
    config([
        'chat.models' => [],
        'chat.auto_chain' => [],
        'chat.self_hosted' => ['url' => null, 'key' => '', 'models' => null],
    ]);

    $resolver = new AiModelResolver(new ModelRegistry);
    $user = User::factory()->withPersonalTeam()->create();

    expect(fn (): array => $resolver->resolve($user))
        ->toThrow(RuntimeException::class, 'No chat model is configured');
});

// Seam para proveedores registrados por un addon que no se autentican con API key.
// El addon srjingles/sr-crm registra 'vertex' (Claude a traves de Google Cloud, con
// credenciales de service account) y reapunta ahi los modelos Claude: en produccion
// no hay ANTHROPIC_API_KEY porque la facturacion va por GCP.
it('serves a cloud model whose provider authenticates by credentials instead of an API key', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.vertex', [
        'driver' => 'vertex',
        'project_id' => 'p',
        'location' => 'eu',
        'credentials' => '/srv/secrets/service-account.json',
    ]);
    config()->set('chat.models.0.provider', 'vertex');
    config()->set('chat.auto_chain', ['claude-sonnet']);
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('vertex');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('does not serve a cloud model whose provider has neither key nor credentials', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.vertex', ['driver' => 'vertex', 'project_id' => 'p', 'location' => 'eu']);
    config()->set('chat.models.0.provider', 'vertex');
    config()->set('chat.models.6.model', null);
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalTeam()->create();
    $user->currentTeam->forceFill(['plan' => Plan::Pro])->save();

    // Sin credencial no es servible, asi que una peticion explicita de ese modelo
    // cae al auto_chain en vez de intentar una llamada que no puede autenticarse.
    $resolved = resolve(AiModelResolver::class)->resolve($user, 'claude-sonnet');

    expect($resolved['provider'])->toBe('openai');
});
