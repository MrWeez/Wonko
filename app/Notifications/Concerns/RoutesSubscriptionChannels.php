<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Shared channel resolution for subscription notifications, honouring
 * per-subscription channel overrides.
 */
trait RoutesSubscriptionChannels
{
    /**
     * @return list<string>
     */
    public function routesVia(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $channels = [];

        if ($this->subscriptionChannelEnabled($notifiable, 'database')) {
            $channels[] = 'database';
        }

        if ($this->subscriptionChannelEnabled($notifiable, 'email')) {
            $channels[] = 'mail';
        }

        if ($this->subscriptionChannelEnabled($notifiable, 'webhook')) {
            $channels[] = WebhookChannel::class;
        }

        if ($this->subscriptionChannelEnabled($notifiable, 'telegram')) {
            $channels[] = TelegramChannel::class;
        }

        if ($this->subscriptionChannelEnabled($notifiable, 'discord')) {
            $channels[] = DiscordChannel::class;
        }

        if ($this->subscriptionChannelEnabled($notifiable, 'browser')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    private function subscriptionChannelEnabled(User $notifiable, string $channel): bool
    {
        $overrides = $this->subscription->notification_channel_overrides;

        return is_array($overrides) && array_key_exists($channel, $overrides)
            ? (bool) $overrides[$channel]
            : $notifiable->notificationChannelEnabled($channel);
    }
}
