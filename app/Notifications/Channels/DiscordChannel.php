<?php

namespace App\Notifications\Channels;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiscordChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toDiscord')) {
            return;
        }

        $configuration = $notifiable->notificationChannelConfigurations()['discord'] ?? [];
        $url = $configuration['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::connectTimeout(3)
                ->timeout(5)
                ->post($url, $notification->toDiscord($notifiable))
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Notification Discord delivery failed.', [
                'user_id' => $notifiable->getKey(),
                'url' => $url,
                'notification' => $notification::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
