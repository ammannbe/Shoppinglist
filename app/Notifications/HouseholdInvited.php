<?php

namespace App\Notifications;

use App\Models\Household;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HouseholdInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private User $invitedBy,
        private Household $household,
        private string $token,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Invitation to the household ":household"', ['household' => $this->household->name]))
            ->line(__(':email invited you to the household ":household". Lists shared with the household are shared with all its members.', [
                'email' => $this->invitedBy->email,
                'household' => $this->household->name,
            ]))
            ->action(__('View invitation'), route('households.invitations.show', $this->token))
            ->line(__('The invitation is valid for :days days.', ['days' => config('households.invitation_days')]));
    }
}
