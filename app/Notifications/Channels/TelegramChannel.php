<?php

namespace App\Notifications\Channels;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $configuration = $notifiable->notificationChannelConfigurations()['telegram'] ?? [];
        $botToken = $configuration['bot_token'] ?? null;
        $chatId = $configuration['chat_id'] ?? null;

        if (! is_string($botToken) || $botToken === '' || ! is_string($chatId) || $chatId === '') {
            return;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $notification->toTelegram($notifiable),
        ];

        if (is_int($configuration['message_thread_id'] ?? null) && $configuration['message_thread_id'] > 0) {
            $payload['message_thread_id'] = $configuration['message_thread_id'];
        }

        $url = 'https://api.telegram.org/bot'.$botToken.'/sendMessage';

        try {
            Http::connectTimeout(3)
                ->timeout(5)
                ->post($url, $payload)
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Notification Telegram delivery failed.', [
                'user_id' => $notifiable->getKey(),
                'chat_id' => $chatId,
                'notification' => $notification::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
