<?php

use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Minishlink\WebPush\ContentEncoding;

new #[Title('Notification settings')] class extends Component {
    public bool $database = true;

    public bool $email = false;

    public bool $webhook = false;

    public bool $discord = false;

    public bool $telegram = false;

    public bool $browser = false;

    public string $webhookUrl = '';

    public string $webhookSecret = '';

    public string $discordUrl = '';

    public string $telegramBotToken = '';

    public string $telegramChatId = '';

    public string $telegramMessageThreadId = '';

    public int $notificationDaysBefore = 0;

    /**
     * @var list<int>
     */
    public array $notificationReminders = [];

    public bool $notificationOverdue = false;

    public int $notificationOverdueFrequency = 3;

    public function mount(): void
    {
        $preferences = Auth::user()->notificationPreferences();

        $this->database = $preferences['database'];
        $this->email = $preferences['email'];
        $this->webhook = $preferences['webhook'];
        $this->discord = $preferences['discord'];
        $this->telegram = $preferences['telegram'];
        $this->browser = $preferences['browser'];
        $this->notificationDaysBefore = Auth::user()->notificationDaysBefore();
        $this->notificationReminders = Auth::user()->notificationReminders();
        $this->notificationOverdue = Auth::user()->notificationOverdue();
        $this->notificationOverdueFrequency = Auth::user()->notificationOverdueFrequency();

        $configurations = Auth::user()->notificationChannelConfigurations();
        $this->webhookUrl = $configurations['webhook']['url'] ?? '';
        $this->telegramChatId = (string) ($configurations['telegram']['chat_id'] ?? '');
        $this->telegramMessageThreadId = (string) ($configurations['telegram']['message_thread_id'] ?? '');
    }

    public function addReminder(): void
    {
        $this->notificationReminders[] = 1;
    }

    public function removeReminder(int $index): void
    {
        unset($this->notificationReminders[$index]);
        $this->notificationReminders = array_values($this->notificationReminders);
    }

    /**
     * @return Collection<int, array{endpoint: string, label: string}>
     */
    #[Computed]
    public function pushDevices(): Collection
    {
        return Auth::user()->pushSubscriptions
            ->sortBy('id')
            ->map(fn (App\Models\PushSubscription $subscription): array => [
                'endpoint' => $subscription->endpoint,
                'label' => $this->deviceLabel($subscription->endpoint),
            ])
            ->values();
    }

    private function deviceLabel(string $endpoint): string
    {
        $host = (string) (parse_url($endpoint, PHP_URL_HOST) ?: 'Unknown device');
        $suffix = mb_substr($endpoint, -8);

        return $host.' · …'.$suffix;
    }

    public function subscribeDevice(string $endpoint, string $key, string $token): void
    {
        $validated = validator(['endpoint' => $endpoint, 'key' => $key, 'token' => $token], [
            'endpoint' => ['required', 'url', 'max:1024'],
            'key' => ['required', 'string', 'max:512'],
            'token' => ['required', 'string', 'max:512'],
        ])->validate();

        Auth::user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['key'],
            $validated['token'],
            ContentEncoding::aes128gcm,
        );

        if (! $this->browser) {
            Auth::user()->setNotificationPreferences(['browser' => true]);
            $this->browser = true;
        }

        unset($this->pushDevices);

        Flux::toast(variant: 'success', text: __('Browser notifications enabled on this device.'));
    }

    public function unsubscribeDevice(string $endpoint): void
    {
        abort_if(! str_starts_with($endpoint, 'https://'), 422);

        $user = Auth::user();
        $user->deletePushSubscription($endpoint);

        unset($this->pushDevices);

        Flux::toast(variant: 'success', text: __('This device was unsubscribed from browser notifications.'));
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
            'notificationReminders' => ['present', 'array'],
            'notificationReminders.*' => ['required', 'integer', 'min:1', 'max:365'],
            'notificationOverdue' => ['boolean'],
            'notificationOverdueFrequency' => ['required', 'integer', 'min:1', 'max:365'],
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
            'browser' => $this->browser,
        ]);
        Auth::user()->setNotificationDaysBefore($this->notificationDaysBefore);
        Auth::user()->setNotificationReminders(array_values(array_map('intval', $this->notificationReminders)));
        Auth::user()->setNotificationOverdue((bool) $this->notificationOverdue);
        Auth::user()->setNotificationOverdueFrequency((int) $this->notificationOverdueFrequency);
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

            {{-- 1. Reminders & overdue --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-3 border-b border-zinc-100 pb-4 dark:border-zinc-800">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                        <flux:icon icon="clock" class="size-4 text-zinc-600 dark:text-zinc-400" />
                    </div>
                    <div>
                        <flux:heading size="sm">{{ __('Reminder Schedule') }}</flux:heading>
                        <flux:text size="xs" class="text-zinc-500">{{ __('Add as many reminders as you like. Each one fires a number of days before the due date.') }}</flux:text>
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    @forelse ($this->notificationReminders as $index => $days)
                        <div class="flex items-end gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <flux:input
                                wire:model="notificationReminders.{{ $index }}"
                                :label="__('Notify days before due')"
                                type="number"
                                min="1"
                                max="365"
                                class="max-w-xs"
                            />
                            <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Remove reminder') }}" wire:click="removeReminder({{ $index }})" />
                        </div>
                    @empty
                        <flux:text size="sm" class="text-zinc-500">{{ __('No reminders set.') }}</flux:text>
                    @endforelse

                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="addReminder">{{ __('Add reminder') }}</flux:button>
                </div>

                <div class="mt-5 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <flux:heading size="sm">{{ __('Notify about overdue payments') }}</flux:heading>
                            <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Keep nudging for a subscription that is past due and not yet confirmed.') }}</flux:text>
                        </div>
                        <flux:switch wire:model="notificationOverdue" />
                    </div>

                    @if ($this->notificationOverdue)
                        <div class="mt-4 max-w-xs">
                            <flux:input wire:model="notificationOverdueFrequency" :label="__('Remind again every (:unit)', ['unit' => __('days')])" type="number" min="1" max="365" />
                            <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('While the payment stays overdue, a reminder goes out every N days.') }}</flux:text>
                        </div>
                    @endif
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

            {{-- 3. Browser push --}}
            <div
                class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"
                x-data="pushBrowserDevices({
                    vapidKey: @js(config('webpush.vapid.public_key')),
                    endpoints: @js($this->pushDevices->pluck('endpoint')->values()),
                })"
            >
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon icon="bell" class="size-4 text-zinc-600 dark:text-zinc-400" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ __('Browser Push') }}</flux:heading>
                                @if ($browser)
                                    <flux:badge size="sm" color="green">{{ __('Enabled') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Real-time notifications on your phone or computer, even when the site is closed. Requires HTTPS.') }}</flux:text>
                        </div>
                    </div>
                    <flux:switch wire:model="browser" />
                </div>

                @if ($browser)
                    <div class="mt-5 border-t border-zinc-100 pt-5 dark:border-zinc-800">
                        <flux:text size="xs" class="mb-3 font-medium uppercase tracking-wide text-zinc-500">{{ __('Devices') }}</flux:text>

                        <template x-if="!supported">
                            <flux:callout variant="warning" :heading="__('Unsupported browser')">
                                {{ __('This browser does not support the Push API. Try a recent version of Chrome, Edge, Firefox, or Safari.') }}
                            </flux:callout>
                        </template>

                        <template x-if="supported && permission === 'denied'">
                            <flux:callout variant="danger" :heading="__('Permission blocked')">
                                {{ __('Push permission is blocked in this browser. Allow it from the lock icon next to the address bar, then reload this page.') }}
                            </flux:callout>
                        </template>

                        <div x-cloak x-show="supported && permission !== 'denied'">
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                                <div class="flex items-center gap-3">
                                    <flux:icon icon="device-phone-mobile" class="size-4 text-zinc-600 dark:text-zinc-400" />
                                    <div>
                                        <flux:heading size="sm">{{ __('This device') }}</flux:heading>
                                        <flux:text size="xs" class="text-zinc-500">
                                            <template x-if="isSubscribed">{{ __('Receives browser notifications.') }}</template>
                                            <template x-if="!isSubscribed && permission === 'granted'">{{ __('Permission granted but not registered yet. Click enable below.') }}</template>
                                            <template x-if="!isSubscribed && permission === 'default'">{{ __('Allow push notifications to get alerts here.') }}</template>
                                        </flux:text>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <flux:button x-cloak x-show="!isSubscribed" size="sm" variant="primary" x-on:click.prevent="enable()">
                                        {{ __('Enable on this device') }}
                                    </flux:button>
                                    <flux:button x-cloak x-show="isSubscribed" size="sm" variant="danger" x-on:click.prevent="disable()">
                                        {{ __('Disable on this device') }}
                                    </flux:button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-2">
                            @forelse ($this->pushDevices as $device)
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                                    <div class="flex items-center gap-3">
                                        <flux:icon icon="device-phone-mobile" class="size-4 text-zinc-600 dark:text-zinc-400" />
                                        <flux:text size="sm">{{ $device['label'] }}</flux:text>
                                    </div>
                                    <flux:button size="sm" variant="ghost" x-on:click.prevent="$wire.unsubscribeDevice(@js($device['endpoint']))">{{ __('Remove') }}</flux:button>
                                </div>
                            @empty
                                <flux:text size="sm" class="text-zinc-500">{{ __('No devices registered yet.') }}</flux:text>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>

            {{-- 4. Telegram --}}
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
