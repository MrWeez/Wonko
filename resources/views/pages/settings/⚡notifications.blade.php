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

    public function mount(): void
    {
        $preferences = Auth::user()->notificationPreferences();

        $this->database = $preferences['database'];
        $this->email = $preferences['email'];
        $this->webhook = $preferences['webhook'];
        $this->discord = $preferences['discord'];
        $this->telegram = $preferences['telegram'];
    }

    public function save(): void
    {
        Auth::user()->setNotificationPreferences([
            'database' => $this->database,
            'email' => $this->email,
            'webhook' => $this->webhook,
            'discord' => $this->discord,
            'telegram' => $this->telegram,
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

            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>