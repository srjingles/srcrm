<?php

declare(strict_types=1);

use App\Models\Note;
use App\Models\User;
use Filament\Facades\Filament;
use SrJingles\SrCrm\CustomFields\Forms\MentionRichEditorComponent;

/*
 | @menciones inline (addon srjingles/sr-crm): al guardar el valor de un campo
 | rich-editor (p. ej. el 'body' de una Nota) con un <span data-type="mention">, el
 | MentionNotificationObserver notifica por la campana de Filament a los compañeros
 | recién mencionados. Solo las menciones NUEVAS, nunca al actor, solo miembros reales
 | del equipo, y sin duplicar al re-guardar.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    $this->member = User::factory()->create();
    $this->team->users()->attach($this->member, ['role' => 'editor']);
});

/** HTML tal como lo serializa el editor (plugin @tiptap/suggestion de Filament). */
function mentionHtml(User $user): string
{
    return '<p>Hola <span data-type="mention" data-id="'.$user->getKey()
        .'" data-label="'.e((string) $user->name).'" data-char="@">@'.e((string) $user->name).'</span></p>';
}

it('notifica al compañero mencionado en el body de una nota', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Reunión kickoff']);

    $note->saveCustomFields(['body' => mentionHtml($this->member)]);

    expect($this->member->notifications()->count())->toBe(1);

    $data = $this->member->notifications()->first()->data;
    expect($data['viewData']['reason'])->toBe('mention')
        ->and($data['viewData']['record_id'])->toBe((string) $note->getKey());
});

it('no duplica la notificación al re-guardar el mismo body', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Nota']);
    $html = mentionHtml($this->member);

    $note->saveCustomFields(['body' => $html]);
    $note->saveCustomFields(['body' => $html]); // re-guardado idéntico

    expect($this->member->notifications()->count())->toBe(1);
});

it('solo notifica las menciones NUEVAS al editar', function (): void {
    $second = User::factory()->create();
    $this->team->users()->attach($second, ['role' => 'editor']);

    $note = Note::factory()->for($this->team)->create(['title' => 'Nota']);

    // Primer guardado: menciona al member.
    $note->saveCustomFields(['body' => mentionHtml($this->member)]);
    // Edición: añade al segundo (conservando al member).
    $note->saveCustomFields(['body' => mentionHtml($this->member).mentionHtml($second)]);

    expect($this->member->notifications()->count())->toBe(1) // no re-notificado
        ->and($second->notifications()->count())->toBe(1);   // notificado una vez
});

it('ignora ids que no son miembros del equipo', function (): void {
    $outsider = User::factory()->create(); // no está en el equipo

    $note = Note::factory()->for($this->team)->create(['title' => 'Nota']);
    $note->saveCustomFields(['body' => mentionHtml($outsider)]);

    expect($outsider->notifications()->count())->toBe(0);
});

it('no notifica al actor cuando se menciona a sí mismo', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Nota']);
    $note->saveCustomFields(['body' => mentionHtml($this->owner)]);

    expect($this->owner->notifications()->count())->toBe(0);
});

it('resuelve el nombre de las menciones guardadas al reabrir el editor', function (): void {
    // getLabelsUsing: sin él, al reabrir el registro solo se vería el '@' (Filament no
    // resuelve el nombre de la mención guardada y getLabels cae a items, vacío).
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $bodyField = Note::factory()->for($this->team)->create()
        ->customFields()->where('code', 'body')->firstOrFail();

    $editor = app(MentionRichEditorComponent::class)->create($bodyField);

    // Extraemos el MentionProvider configurado y comprobamos que resuelve id -> nombre
    // (es lo que Filament usa al reabrir para re-pintar el chip de la mención guardada).
    $providers = (new ReflectionProperty($editor, 'mentions'))->getValue($editor);
    $labels = $providers[0]->getLabels([(string) $this->member->getKey()]);

    expect($labels)->toBe([(string) $this->member->getKey() => (string) $this->member->name]);
});
