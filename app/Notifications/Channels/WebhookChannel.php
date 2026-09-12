<?php

namespace App\Notifications\Channels;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toWebhook')) {
            return;
        }

        $configuration = $notifiable->notificationChannelConfigurations()['webhook'] ?? [];
        $url = $configuration['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            $request = Http::connectTimeout(3)->timeout(5);

            if (is_string($configuration['secret'] ?? null) && $configuration['secret'] !== '') {
                $request = $request->withHeader('X-Wonko-Webhook-Secret', $configuration['secret']);
            }

            $request->post($url, $notification->toWebhook($notifiable))->throw();
        } catch (Throwable $exception) {
            Log::warning('Notification webhook delivery failed.', [
                'user_id' => $notifiable->getKey(),
                'url' => $url,
                'notification' => $notification::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
