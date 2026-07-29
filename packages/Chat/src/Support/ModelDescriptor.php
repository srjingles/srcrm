<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\Plan;
use Relaticle\Chat\Enums\WriteGuard;

final readonly class ModelDescriptor
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $provider,
        public ?string $model,
        public Plan $minPlan,
        public float $creditMultiplier,
        public bool $supportsTools,
        public WriteGuard $writeGuard,
        public bool $selfHosted,
    ) {}

    /**
     * @param  array{id:string,label:string,provider:?string,model:?string,min_plan:string,credit_multiplier:int|float,supports_tools:bool,write_guard:string,self_hosted:bool}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            id: $config['id'],
            label: $config['label'],
            provider: $config['provider'] ?? null,
            model: $config['model'] ?? null,
            minPlan: Plan::from($config['min_plan']),
            creditMultiplier: (float) $config['credit_multiplier'],
            supportsTools: (bool) $config['supports_tools'],
            writeGuard: WriteGuard::from($config['write_guard']),
            selfHosted: (bool) $config['self_hosted'],
        );
    }

    /**
     * Servable on this install: tool-capable, has a model tag, and its provider
     * connection is configured. Self-hosted providers need a base URL; cloud
     * providers need a credential, which is an API key for the stock drivers but
     * may instead be a service-account credential for a provider registered by an
     * addon (Claude via Google Vertex AI authenticates that way — no API key).
     */
    public function isAvailable(): bool
    {
        if (! $this->supportsTools || $this->model === null || $this->model === '') {
            return false;
        }

        /** @var array<string, mixed> $connection */
        $connection = config("ai.providers.{$this->provider}", []);

        if ($this->selfHosted) {
            return filled($connection['url'] ?? null);
        }

        return filled($connection['key'] ?? null)
            || filled($connection['credentials'] ?? null);
    }

    public function allowedForPlan(Plan $plan): bool
    {
        return $plan->rank() >= $this->minPlan->rank();
    }

    /**
     * Label shown in the picker. Self-hosted models surface their configured
     * model tag (e.g. "qwen3:8b") so operators recognise their own model; cloud
     * models use their curated label.
     */
    public function displayLabel(): string
    {
        return $this->selfHosted && $this->model !== null && $this->model !== ''
            ? $this->model
            : $this->label;
    }
}
