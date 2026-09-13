<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Notifications\Concerns\RoutesSubscriptionChannels;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class SubscriptionOverdueNotification extends Notification
{
    use RoutesSubscriptionChannels;

    public function __construct(public Subscription $subscription) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->routesVia($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Overdue: '.$this->subscription->name)
            ->line($this->subscription->name.' is overdue.')
            ->line('Amount: '.$this->subscription->amount.' '.$this->subscription->currency)
            ->line('Due date: '.$this->dueDate());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'subscription_overdue',
            'subscription_id' => $this->subscription->id,
            'subscription_name' => $this->subscription->name,
            'amount' => $this->subscription->amount,
            'currency' => $this->subscription->currency,
            'due_date' => $this->dueDate(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhook(object $notifiable): array
    {
        return [
            'event' => 'subscription_overdue',
            'subscription' => [
                'id' => $this->subscription->id,
                'name' => $this->subscription->name,
            ],
            'due_date' => $this->dueDate(),
            'currency' => $this->subscription->currency,
            'amount' => $this->subscription->amount,
        ];
    }

    public function toTelegram(object $notifiable): string
    {
        return implode("\n", [
            'Overdue: '.$this->subscription->name,
            'Amount: '.$this->subscription->amount.' '.$this->subscription->currency,
            'Due date: '.$this->dueDate(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDiscord(object $notifiable): array
    {
        return [
            'username' => 'Wonko',
            'embeds' => [[
                'title' => 'Overdue: '.$this->subscription->name,
                'fields' => [
                    [
                        'name' => 'Amount',
                        'value' => $this->subscription->amount.' '.$this->subscription->currency,
                        'inline' => true,
                    ],
                    [
                        'name' => 'Due date',
                        'value' => $this->dueDate(),
                        'inline' => true,
                    ],
                ],
            ]],
        ];
    }

    public function toWebPush(object $notifiable, object $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Overdue: '.$this->subscription->name)
            ->icon('/apple-touch-icon.png')
            ->body($this->subscription->amount.' '.$this->subscription->currency.' · Due: '.$this->dueDate())
            ->action('Open subscription', 'open_subscription')
            ->data(['url' => route('subscriptions.index')])
            ->options(['TTL' => 86400]);
    }

    private function dueDate(): string
    {
        return CarbonImmutable::parse((string) $this->subscription->next_due_date)->toDateString();
    }
}
