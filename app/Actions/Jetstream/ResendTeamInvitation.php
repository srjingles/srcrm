<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Laravel\Jetstream\TeamInvitation as TeamInvitationModel;

final readonly class ResendTeamInvitation
{
    /**
     * Reenvía una invitación, renovando antes su caducidad.
     *
     * La renovación no es un extra: el enlace del email lleva el id de la invitación y quien lo abre
     * pasa por AcceptTeamInvitationController, que rechaza las caducadas. Sin renovar, reenviar una
     * invitación vencida manda un enlace muerto —el invitado ve "invitación caducada"— y desde el
     * panel no había forma de revivirla: solo revocar y volver a invitar. Se usa la misma ventana
     * que InviteTeamMember al crearla.
     */
    public function resend(TeamInvitationModel $invitation): void
    {
        $invitation->forceFill([
            'expires_at' => now()->addDays((int) config('jetstream.invitation_expiry_days', 7)),
        ])->save();

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));
    }
}
