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
        public ?SubscriptionOccurrence $occurrence = null,
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

        if ($this->channelEnabled($notifiable, 'database')) {
            $channels[] = 'database';
        }

        if ($this->channelEnabled($notifiable, 'email')) {
            $channels[] = 'mail';
        }

        if ($this->channelEnabled($notifiable, 'webhook')) {
            $channels[] = WebhookChannel::class;
        }

        if ($this->channelEnabled($notifiable, 'telegram')) {
            $channels[] = TelegramChannel::class;
        }

        if ($this->channelEnabled($notifiable, 'discord')) {
            $channels[] = DiscordChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Subscription due: '.$this->subscription->name)
            ->line($this->subscription->name.' is due.')
            ->line('Amount: '.$this->amount().' '.$this->currency())
            ->line('Due date: '.$this->dueDate());
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
            'occurrence_id' => $this->occurrence?->id,
            'amount' => $this->amount(),
            'currency' => $this->currency(),
            'due_date' => $this->dueDate(),
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
                'id' => $this->occurrence?->id,
                'amount' => $this->amount(),
                'currency' => $this->currency(),
                'due_date' => $this->dueDate(),
            ],
        ];
    }

    public function toTelegram(object $notifiable): string
    {
        return implode("\n", [
            'Subscription due: '.$this->subscription->name,
            'Amount: '.$this->amount().' '.$this->currency(),
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
                'title' => 'Subscription due: '.$this->subscription->name,
                'fields' => [
                    [
                        'name' => 'Amount',
                        'value' => $this->amount().' '.$this->currency(),
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

    private function channelEnabled(User $notifiable, string $channel): bool
    {
        $overrides = $this->subscription->notification_channel_overrides;

        return is_array($overrides) && array_key_exists($channel, $overrides)
            ? (bool) $overrides[$channel]
            : $notifiable->notificationChannelEnabled($channel);
    }

    private function amount(): string
    {
        return (string) ($this->occurrence !== null ? $this->occurrence->amount : $this->subscription->amount);
    }

    private function currency(): string
    {
        return (string) ($this->occurrence !== null ? $this->occurrence->currency : $this->subscription->currency);
    }

    private function dueDate(): string
    {
        $dueDate = $this->occurrence !== null ? $this->occurrence->due_date : $this->subscription->next_due_date;

        return CarbonImmutable::parse((string) $dueDate)->toDateString();
    }
}
