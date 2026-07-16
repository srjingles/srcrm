<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

/**
 * Actions de mentira que representan las de un addon: el allowlist decide si llegan a
 * instanciarse y ejecutarse, que es justo lo que se está probando. Cada operación tiene su
 * firma (el servicio invoca create y update distinto), así que hacen falta las dos.
 */
final class StubAddonAction
{
    /** @param  array<string, mixed>  $data */
    public function execute(User $user, array $data, CreationSource $source): Model
    {
        return $user;
    }
}

final class StubAddonUpdateAction
{
    /**
     * Devuelve un escalar, no el modelo: approve() le pediría getMorphClass() al resultado y
     * la cobaya del test no está en el morph map. Da igual para lo que se prueba — al llegar
     * aquí, el allowlist ya ha dejado pasar action_class y _model_class.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Model $model, array $data): string
    {
        return 'done';
    }
}

/** @param  array<string, mixed>  $actionData */
function pendingActionFor(
    User $user,
    string $actionClass,
    PendingActionOperation $operation = PendingActionOperation::Create,
    array $actionData = [],
): PendingAction {
    $conversationId = 'conv-'.Str::random(12);

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => $actionClass,
        'operation' => $operation,
        'entity_type' => 'company',
        'action_data' => $actionData,
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

it('refuses to execute a pending action whose class is not allowlisted', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $pending = pendingActionFor($user, stdClass::class);

    expect(fn () => app(PendingActionService::class)->approve($pending, $user))
        ->toThrow(RuntimeException::class, 'Action class not allowlisted');
});

it('refuses an addon action that no config allowlists', function (): void {
    config()->set('chat.extra_allowed_action_classes', []);

    $user = User::factory()->withPersonalTeam()->create();

    $pending = pendingActionFor($user, StubAddonAction::class);

    expect(fn () => app(PendingActionService::class)->approve($pending, $user))
        ->toThrow(RuntimeException::class, 'Action class not allowlisted');
});

it('executes an addon action registered in chat.extra_allowed_action_classes', function (): void {
    config()->set('chat.extra_allowed_action_classes', [StubAddonAction::class]);

    $user = User::factory()->withPersonalTeam()->create();

    $pending = pendingActionFor($user, StubAddonAction::class);

    $resolved = app(PendingActionService::class)->approve($pending, $user);

    expect($resolved->status)->toBe(PendingActionStatus::Approved)
        ->and($resolved->result_data['id'])->toBe($user->getKey());
});

it('refuses a persisted _model_class that no config allowlists', function (): void {
    config()->set('chat.extra_allowed_action_classes', [StubAddonUpdateAction::class]);
    config()->set('chat.extra_allowed_model_classes', []);

    $user = User::factory()->withPersonalTeam()->create();

    // El _model_class viaja en la BD dentro de action_data: es la otra superficie que el
    // allowlist protege, aparte de action_class. La action SÍ está permitida aquí, para que
    // el único motivo posible de rechazo sea el modelo.
    $pending = pendingActionFor($user, StubAddonUpdateAction::class, PendingActionOperation::Update, [
        '_record_id' => 'whatever',
        '_model_class' => stdClass::class,
    ]);

    expect(fn () => app(PendingActionService::class)->approve($pending, $user))
        ->toThrow(RuntimeException::class, 'Invalid model class');
});

it('opens a model class that the host constant does not contain', function (): void {
    config()->set('chat.extra_allowed_action_classes', [StubAddonUpdateAction::class]);
    config()->set('chat.extra_allowed_model_classes', [AgentConversation::class]);

    $user = User::factory()->withPersonalTeam()->create();

    // AgentConversation hace de cobaya solo porque tiene team_id y NO está en
    // ALLOWED_MODEL_CLASSES: así el test demuestra que la config abre una clase nueva de
    // verdad. Usar Company no probaría nada — ya está en la constante del host.
    $pending = pendingActionFor($user, StubAddonUpdateAction::class, PendingActionOperation::Update);
    $pending->update(['action_data' => [
        '_record_id' => $pending->conversation_id,
        '_model_class' => AgentConversation::class,
    ]]);

    $resolved = app(PendingActionService::class)->approve($pending, $user);

    // Llegar a Approved prueba que pasaron resolveModelClass (allowlist) y resolveModel
    // (findOrFail sobre el registro real): ambos corren antes de ejecutar la action.
    expect($resolved->status)->toBe(PendingActionStatus::Approved);
});
