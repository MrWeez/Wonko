<div wire:poll.60s>
    <flux:dropdown position="bottom" align="end">
        <flux:button
            variant="ghost"
            icon="bell"
            class="relative"
            :aria-label="__('Notifications')"
            data-test="notification-bell"
        >
            @if ($this->unreadCount > 0)
                <span
                    class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-zinc-900 px-1 text-[10px] font-semibold leading-none text-white dark:bg-white dark:text-zinc-900"
                    data-test="notification-badge"
                >
                    {{ min($this->unreadCount, 9) }}
                </span>
            @endif
        </flux:button>

        <flux:menu class="w-80 max-w-[calc(100vw-2rem)]">
            <div class="flex items-center justify-between gap-2 px-4 pb-1 pt-3">
                <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>

                @if ($this->unreadCount > 0)
                    <flux:button size="xs" variant="ghost" wire:click="markAllAsRead" class="cursor-pointer">
                        {{ __('Mark all as read') }}
                    </flux:button>
                @endif
            </div>

            <flux:menu.separator />

            @if ($this->notifications->isEmpty())
                <div class="px-4 py-8 text-center text-sm text-zinc-500" data-test="notification-empty">
                    {{ __('You are all caught up.') }}
                </div>
            @else
                <flux:menu.radio.group>
                    @foreach ($this->notifications as $notification)
                        <flux:menu.item
                            as="button"
                            wire:click="markAsRead('{{ $notification->id }}')"
                            class="cursor-pointer"
                        >
                            <div class="flex w-full items-start gap-3 py-1">
                                <flux:icon icon="arrow-path-rounded-square" class="mt-0.5 size-4 shrink-0 text-zinc-500" />
                                <div class="grid w-full text-start">
                                    <flux:text size="sm" class="font-medium">
                                        {{ $notification->data['subscription_name'] ?? __('Subscription due') }}
                                    </flux:text>
                                    <flux:text size="xs" class="text-zinc-500">
                                        {{ App\Amount::display((string) ($notification->data['amount'] ?? '0')) }} {{ $notification->data['currency'] ?? '' }} · {{ $notification->data['due_date'] ?? '' }}
                                    </flux:text>
                                </div>
                            </div>
                        </flux:menu.item>
                    @endforeach
                </flux:menu.radio.group>
            @endif
        </flux:menu>
    </flux:dropdown>
</div>