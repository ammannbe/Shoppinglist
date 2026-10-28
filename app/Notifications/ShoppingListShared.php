<?php

namespace App\Notifications;

use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class ShoppingListShared extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private User $sharedBy,
        private ShoppingList $list,
        private bool $invited,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Shopping list shared'))
            ->line(__(':email shared the shopping list ":list" with you.', [
                'email' => $this->sharedBy->email,
                'list' => $this->list->name,
            ]));

        if (! $this->invited) {
            return $message->action(__('Open shopping list'), url('/'));
        }

        // Invited users choose a password, which also verifies their email address.
        $url = url(route('password.reset', [
            'token' => Password::broker()->createToken($notifiable),
            'email' => $notifiable->email,
        ], false));

        return $message
            ->line(__('An account has been created for you. Choose a password to get started.'))
            ->action(__('Choose password'), $url);
    }
}
