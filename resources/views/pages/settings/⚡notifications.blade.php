<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    public bool $database = true;

    public bool $email = false;

    public bool $webhook = false;

    public bool $discord = false;

    public bool $telegram = false;

    public string $webhookUrl = '';

    public string $webhookSecret = '';

    public string $discordUrl = '';

    public string $telegramBotToken = '';

    public string $telegramChatId = '';

    public string $telegramMessageThreadId = '';

    public int $notificationDaysBefore = 0;

    public function mount(): void
    {
        $preferences = Auth::user()->notificationPreferences();

        $this->database = $preferences['database'];
        $this->email = $preferences['email'];
        $this->webhook = $preferences['webhook'];
        $this->discord = $preferences['discord'];
        $this->telegram = $preferences['telegram'];
        $this->notificationDaysBefore = Auth::user()->notificationDaysBefore();

        $configurations = Auth::user()->notificationChannelConfigurations();
        $this->webhookUrl = $configurations['webhook']['url'] ?? '';
        $this->telegramChatId = (string) ($configurations['telegram']['chat_id'] ?? '');
        $this->telegramMessageThreadId = (string) ($configurations['telegram']['message_thread_id'] ?? '');
    }

    public function save(): void
    {
        $this->validate([
            'webhookUrl' => ['nullable', 'url', 'max:2048'],
            'webhookSecret' => ['nullable', 'string', 'max:255'],
            'discordUrl' => ['nullable', 'url', 'max:2048'],
            'telegramBotToken' => ['nullable', 'string', 'max:255'],
            'telegramChatId' => ['nullable', 'string', 'max:255'],
            'telegramMessageThreadId' => ['nullable', 'integer', 'min:1'],
            'notificationDaysBefore' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $configurations = Auth::user()->notificationChannelConfigurations();
        $webhookSecret = $this->webhookSecret !== ''
            ? $this->webhookSecret
            : ($configurations['webhook']['secret'] ?? null);
        $discordUrl = $this->discordUrl !== ''
            ? $this->discordUrl
            : ($configurations['discord']['url'] ?? null);
        $telegramBotToken = $this->telegramBotToken !== ''
            ? $this->telegramBotToken
            : ($configurations['telegram']['bot_token'] ?? null);

        Auth::user()->setNotificationPreferences([
            'database' => $this->database,
            'email' => $this->email,
            'webhook' => $this->webhook,
            'discord' => $this->discord,
            'telegram' => $this->telegram,
        ]);
        Auth::user()->setNotificationDaysBefore($this->notificationDaysBefore);
        Auth::user()->setNotificationChannelConfigurations([
            'webhook' => ['url' => $this->webhookUrl, 'secret' => $webhookSecret],
            'discord' => ['url' => $discordUrl],
            'telegram' => [
                'bot_token' => $telegramBotToken,
                'chat_id' => $this->telegramChatId,
                'message_thread_id' => $this->telegramMessageThreadId !== '' ? (int) $this->telegramMessageThreadId : null,
            ],
        ]);

        Flux::toast(variant: 'success', text: __('Notification preferences saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notification Channels')" :subheading="__('Configure delivery channels and advance reminder timing for bills and subscriptions.')">
        <form wire:submit="save" class="my-6 w-full space-y-6">

            {{-- Delivery errors --}}
            @foreach (Auth::user()->notificationChannelFailureStatus() as $channel => $status)
                <flux:callout variant="danger" :heading="__(ucfirst($channel).' delivery failed')">
                    {{ $status['failed_at'] ?? '' }}: {{ $status['message'] ?? __('Unknown error occurred during delivery.') }}
                </flux:callout>
            @endforeach

            {{-- 1. Timing --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-3 border-b border-zinc-100 pb-4 dark:border-zinc-800">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                        <flux:icon icon="clock" class="size-4 text-zinc-600 dark:text-zinc-400" />
                    </div>
                    <div>
                        <flux:heading size="sm">{{ __('Reminder Timing') }}</flux:heading>
                        <flux:text size="xs" class="text-zinc-500">{{ __('How many days before the due date Wonko should alert you.') }}</flux:text>
                    </div>
                </div>
                <div class="mt-4 max-w-xs">
                    <flux:input wire:model="notificationDaysBefore" :label="__('Days before due date')" type="number" min="0" max="365" />
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('0 = on the day itself · 1 = 1 day early · 7 = one week early') }}</flux:text>
                </div>
            </div>

            {{-- 2. Simple channels: In-App & Email side by side --}}
            <div class="grid gap-4 sm:grid-cols-2">
                {{-- In-App --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon icon="bell" class="size-4 text-zinc-600 dark:text-zinc-400" />
                            </div>
                            <div>
                                <flux:heading size="sm">{{ __('In-App Alerts') }}</flux:heading>
                                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Badges and notifications inside the web interface.') }}</flux:text>
                            </div>
                        </div>
                        <flux:switch wire:model="database" />
                    </div>
                </div>

                {{-- Email --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon icon="envelope" class="size-4 text-zinc-600 dark:text-zinc-400" />
                            </div>
                            <div>
                                <flux:heading size="sm">{{ __('Email') }}</flux:heading>
                                <flux:text size="xs" class="mt-1 text-zinc-500">{{ Auth::user()->email }}</flux:text>
                            </div>
                        </div>
                        <flux:switch wire:model="email" />
                    </div>
                </div>
            </div>

            {{-- 3. Telegram --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon icon="paper-airplane" class="size-4 text-zinc-600 dark:text-zinc-400" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ __('Telegram Bot') }}</flux:heading>
                                @if ($telegram)
                                    <flux:badge size="sm" color="green">{{ __('Enabled') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Send messages to a private chat, group, or forum topic.') }}</flux:text>
                        </div>
                    </div>
                    <flux:switch wire:model="telegram" />
                </div>

                @if ($telegram)
                    <div class="mt-5 border-t border-zinc-100 pt-5 dark:border-zinc-800">
                        <flux:text size="xs" class="mb-3 font-medium uppercase tracking-wide text-zinc-500">{{ __('Configuration') }}</flux:text>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <flux:input wire:model="telegramBotToken" :label="__('Bot Token')" type="password" placeholder="{{ __('Leave blank to keep current') }}" />
                                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('From @BotFather on Telegram.') }}</flux:text>
                            </div>
                            <div>
                                <flux:input wire:model="telegramChatId" :label="__('Chat ID')" placeholder="{{ __('e.g. 123456789 or -100...') }}" />
                                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Your user ID or group chat ID.') }}</flux:text>
                            </div>
                            <div>
                                <flux:input wire:model="telegramMessageThreadId" :label="__('Topic ID')" type="number" min="1" placeholder="{{ __('Optional') }}" />
                                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('For forum-enabled supergroups only.') }}</flux:text>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- 4. Discord --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon icon="chat-bubble-left-right" class="size-4 text-zinc-600 dark:text-zinc-400" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ __('Discord Webhook') }}</flux:heading>
                                @if ($discord)
                                    <flux:badge size="sm" color="green">{{ __('Enabled') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Post rich embeds to a channel on your Discord server.') }}</flux:text>
                        </div>
                    </div>
                    <flux:switch wire:model="discord" />
                </div>

                @if ($discord)
                    <div class="mt-5 border-t border-zinc-100 pt-5 dark:border-zinc-800">
                        <flux:text size="xs" class="mb-3 font-medium uppercase tracking-wide text-zinc-500">{{ __('Configuration') }}</flux:text>
                        <flux:input wire:model="discordUrl" :label="__('Discord Webhook URL')" type="url" placeholder="{{ __('Leave blank to keep current URL') }}" />
                        <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Channel Settings → Integrations → Webhooks → Copy Webhook URL.') }}</flux:text>
                    </div>
                @endif
            </div>

            {{-- 5. Generic JSON Webhook --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon icon="code-bracket" class="size-4 text-zinc-600 dark:text-zinc-400" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ __('Generic JSON Webhook') }}</flux:heading>
                                @if ($webhook)
                                    <flux:badge size="sm" color="green">{{ __('Enabled') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('HTTP POST payload for custom automations — n8n, Home Assistant, etc.') }}</flux:text>
                        </div>
                    </div>
                    <flux:switch wire:model="webhook" />
                </div>

                @if ($webhook)
                    <div class="mt-5 border-t border-zinc-100 pt-5 dark:border-zinc-800">
                        <flux:text size="xs" class="mb-3 font-medium uppercase tracking-wide text-zinc-500">{{ __('Configuration') }}</flux:text>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <flux:input wire:model="webhookUrl" :label="__('Endpoint URL')" type="url" placeholder="https://example.com/api/wonko" />
                                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Receives POST requests on events.') }}</flux:text>
                            </div>
                            <div>
                                <flux:input wire:model="webhookSecret" :label="__('Signing Secret (Optional)')" type="password" placeholder="{{ __('Leave blank to keep current') }}" />
                                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Sent as X-Wonko-Webhook-Secret header.') }}</flux:text>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex justify-end pt-2">
                <flux:button type="submit" variant="primary">{{ __('Save notification settings') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
