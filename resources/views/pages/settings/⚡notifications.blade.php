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

    public function mount(): void
    {
        $preferences = Auth::user()->notificationPreferences();

        $this->database = $preferences['database'];
        $this->email = $preferences['email'];
        $this->webhook = $preferences['webhook'];
        $this->discord = $preferences['discord'];
        $this->telegram = $preferences['telegram'];

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

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Choose where Wonko should deliver alerts.')">
        <form wire:submit="save" class="my-6 w-full space-y-5">
            <flux:checkbox wire:model="database" :label="__('In-app notifications')" />
            <flux:checkbox wire:model="email" :label="__('Email')" />
            <flux:checkbox wire:model="webhook" :label="__('Generic webhook')" />
            <flux:checkbox wire:model="discord" :label="__('Discord webhook')" />
            <flux:checkbox wire:model="telegram" :label="__('Telegram')" />

            <flux:input wire:model="webhookUrl" :label="__('Generic webhook URL')" type="url" placeholder="https://example.test/hooks/wonko" />
            <flux:input wire:model="webhookSecret" :label="__('Generic webhook secret')" type="password" placeholder="{{ __('Leave blank to keep current secret') }}" />
            <flux:input wire:model="discordUrl" :label="__('Discord webhook URL')" type="url" placeholder="{{ __('Leave blank to keep current URL') }}" />
            <flux:input wire:model="telegramBotToken" :label="__('Telegram bot token')" type="password" placeholder="{{ __('Leave blank to keep current token') }}" />
            <flux:input wire:model="telegramChatId" :label="__('Telegram chat ID')" />
            <flux:input wire:model="telegramMessageThreadId" :label="__('Telegram message thread ID')" type="number" min="1" placeholder="{{ __('Optional topic ID') }}" />

            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>