<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionDueNotification extends Notification
{
    public function __construct(
        public Subscription $subscription,
        public SubscriptionOccurrence $occurrence,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $channels = [];

        if ($notifiable->notificationChannelEnabled('database')) {
            $channels[] = 'database';
        }

        if ($notifiable->notificationChannelEnabled('email')) {
            $channels[] = 'mail';
        }

        if ($notifiable->notificationChannelEnabled('webhook')) {
            $channels[] = WebhookChannel::class;
        }

        if ($notifiable->notificationChannelEnabled('telegram')) {
            $channels[] = TelegramChannel::class;
        }

        if ($notifiable->notificationChannelEnabled('discord')) {
            $channels[] = DiscordChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Subscription due: '.$this->subscription->name)
            ->line($this->subscription->name.' is due.')
            ->line('Amount: '.$this->occurrence->amount.' '.$this->occurrence->currency)
            ->line('Due date: '.CarbonImmutable::parse((string) $this->occurrence->due_date)->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'subscription_due',
            'subscription_id' => $this->subscription->id,
            'subscription_name' => $this->subscription->name,
            'occurrence_id' => $this->occurrence->id,
            'amount' => $this->occurrence->amount,
            'currency' => $this->occurrence->currency,
            'due_date' => CarbonImmutable::parse((string) $this->occurrence->due_date)->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhook(object $notifiable): array
    {
        return [
            'event' => 'subscription_due',
            'subscription' => [
                'id' => $this->subscription->id,
                'name' => $this->subscription->name,
            ],
            'occurrence' => [
                'id' => $this->occurrence->id,
                'amount' => $this->occurrence->amount,
                'currency' => $this->occurrence->currency,
                'due_date' => CarbonImmutable::parse((string) $this->occurrence->due_date)->toDateString(),
            ],
        ];
    }

    public function toTelegram(object $notifiable): string
    {
        return implode("\n", [
            'Subscription due: '.$this->subscription->name,
            'Amount: '.$this->occurrence->amount.' '.$this->occurrence->currency,
            'Due date: '.CarbonImmutable::parse((string) $this->occurrence->due_date)->toDateString(),
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
                'title' => 'Subscription due: '.$this->subscription->name,
                'fields' => [
                    [
                        'name' => 'Amount',
                        'value' => $this->occurrence->amount.' '.$this->occurrence->currency,
                        'inline' => true,
                    ],
                    [
                        'name' => 'Due date',
                        'value' => CarbonImmutable::parse((string) $this->occurrence->due_date)->toDateString(),
                        'inline' => true,
                    ],
                ],
            ]],
        ];
    }
}
