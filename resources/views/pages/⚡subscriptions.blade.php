<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\Transaction;
use App\PaymentMethodType;
use App\SubscriptionPeriodUnit;
use App\TransactionType;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Subscriptions')] class extends Component {
    public string $subscriptionName = '';

    public ?int $subscriptionAccountId = null;

    public ?int $subscriptionPaymentMethodId = null;

    public ?int $subscriptionCategoryId = null;

    public string $subscriptionCurrency = '';

    public string $subscriptionAmount = '';

    public string $subscriptionPeriodCount = '1';

    public string $subscriptionPeriodUnit = SubscriptionPeriodUnit::Month->value;

    public string $subscriptionNextDueDate = '';

    public bool $subscriptionAutoPost = false;

    public bool $subscriptionActive = true;

    /**
     * @var array<string, string>
     */
    public array $subscriptionNotificationChannels = [
        'database' => 'global',
        'email' => 'global',
        'webhook' => 'global',
        'discord' => 'global',
        'telegram' => 'global',
    ];

    public bool $subscriptionOverrideNotifications = false;

    /**
     * @var list<int>
     */
    public array $subscriptionReminders = [];

    public bool $subscriptionOverdue = false;

    public int $subscriptionOverdueFrequency = 3;

    public ?int $editingSubscriptionId = null;

    public ?int $occurrenceSubscriptionId = null;

    public string $occurrenceAmount = '';

    public string $occurrenceCurrency = '';

    public string $occurrenceAccountAmount = '';

    public string $occurrenceDueDate = '';

    public string $occurrenceNotes = '';

    public function mount(): void
    {
        $this->subscriptionNextDueDate = now()->toDateString();
        $this->occurrenceDueDate = now()->toDateString();

        $account = $this->accounts->first();

        if ($account) {
            $this->subscriptionAccountId = $account->id;
            $this->subscriptionCurrency = $account->currency;
        }
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->defaultOrder()->get();
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function paymentMethods(): Collection
    {
        return PaymentMethod::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Subscription>
     */
    #[Computed]
    public function subscriptions(): Collection
    {
        return Subscription::query()
            ->with(['account', 'category', 'paymentMethod', 'occurrences' => fn ($query) => $query->latest('due_date')])
            ->orderBy('active', 'desc')
            ->orderBy('next_due_date')
            ->get();
    }

    public function updatedSubscriptionAccountId(?int $accountId): void
    {
        $this->subscriptionCurrency = Account::query()->find($accountId)?->currency ?? '';
    }

    public function updatedSubscriptionCurrency(string $currency): void
    {
        $this->subscriptionCurrency = strtoupper($currency);
    }

    public function updatedOccurrenceCurrency(string $currency): void
    {
        $this->occurrenceCurrency = strtoupper($currency);
    }

    public function saveSubscription(): void
    {
        $validated = $this->validate([
            'subscriptionName' => [
                'required',
                'string',
                'max:120',
                Rule::unique('subscriptions', 'name')
                    ->where(fn ($query) => $query->where('user_id', auth()->id()))
                    ->ignore($this->editingSubscriptionId),
            ],
            'subscriptionAccountId' => ['required', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('user_id', auth()->id()))],
            'subscriptionPaymentMethodId' => ['nullable', Rule::exists('payment_methods', 'id')->where(fn ($query) => $query->where('user_id', auth()->id()))],
            'subscriptionCategoryId' => ['nullable', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('user_id', auth()->id()))],
            'subscriptionCurrency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'subscriptionAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'subscriptionPeriodCount' => ['required', 'integer', 'min:1', 'max:3650'],
            'subscriptionPeriodUnit' => [Rule::enum(SubscriptionPeriodUnit::class)],
            'subscriptionNextDueDate' => ['required', 'date'],
            'subscriptionAutoPost' => ['boolean'],
            'subscriptionActive' => ['boolean'],
            'subscriptionNotificationChannels.*' => ['required', Rule::in(['global', 'enabled', 'disabled'])],
            'subscriptionOverrideNotifications' => ['boolean'],
        ]);

        if ($this->subscriptionOverrideNotifications) {
            $this->validate([
                'subscriptionReminders' => ['present', 'array'],
                'subscriptionReminders.*' => ['required', 'integer', 'min:1', 'max:365'],
                'subscriptionOverdue' => ['boolean'],
                'subscriptionOverdueFrequency' => ['required', 'integer', 'min:1', 'max:365'],
            ]);
        }

        $notificationChannelOverrides = [];
        foreach ($this->subscriptionNotificationChannels as $channel => $value) {
            if ($value !== 'global') {
                $notificationChannelOverrides[$channel] = $value === 'enabled';
            }
        }

        $subscription = $this->editingSubscriptionId
            ? Subscription::query()->findOrFail($this->editingSubscriptionId)
            : new Subscription;

        $subscription->fill([
            'account_id' => $this->subscriptionAccountId,
            'payment_method_id' => $this->subscriptionPaymentMethodId ?: null,
            'category_id' => $this->subscriptionCategoryId,
            'name' => $this->subscriptionName,
            'currency' => $this->subscriptionCurrency,
            'amount' => $this->subscriptionAmount,
            'period_count' => $this->subscriptionPeriodCount,
            'period_unit' => $this->subscriptionPeriodUnit,
            'next_due_date' => $this->subscriptionNextDueDate,
            'auto_post' => $this->subscriptionAutoPost,
            'active' => $this->subscriptionActive,
            'notification_channel_overrides' => $notificationChannelOverrides ?: null,
            'notification_reminders' => $this->subscriptionOverrideNotifications
                ? array_values(array_map('intval', $this->subscriptionReminders))
                : null,
            'notification_overdue' => $this->subscriptionOverrideNotifications ? (bool) $this->subscriptionOverdue : null,
            'notification_overdue_frequency' => $this->subscriptionOverrideNotifications ? (int) $this->subscriptionOverdueFrequency : null,
        ]);
        $subscription->save();

        $this->resetSubscriptionForm();
        unset($this->subscriptions, $this->accounts, $this->categories);

        Flux::toast(variant: 'success', text: __('Subscription saved.'));
    }

    public function editSubscription(int $subscriptionId): void
    {
        $subscription = Subscription::query()->findOrFail($subscriptionId);

        $this->editingSubscriptionId = $subscription->id;
        $this->subscriptionName = $subscription->name;
        $this->subscriptionAccountId = $subscription->account_id;
        $this->subscriptionPaymentMethodId = $subscription->payment_method_id;
        $this->subscriptionCategoryId = $subscription->category_id;
        $this->subscriptionCurrency = $subscription->currency;
        $this->subscriptionAmount = (string) $subscription->amount;
        $this->subscriptionPeriodCount = (string) $subscription->period_count;
        $this->subscriptionPeriodUnit = $subscription->period_unit->value;
        $this->subscriptionNextDueDate = $subscription->next_due_date->toDateString();
        $this->subscriptionAutoPost = $subscription->auto_post;
        $this->subscriptionActive = $subscription->active;
        $this->subscriptionNotificationChannels = array_replace(
            $this->subscriptionNotificationChannels,
            array_map(
                fn (bool $enabled): string => $enabled ? 'enabled' : 'disabled',
                $subscription->notification_channel_overrides ?? [],
            ),
        );

        $this->subscriptionOverrideNotifications = $subscription->notification_reminders !== null
            || $subscription->notification_overdue !== null
            || $subscription->notification_overdue_frequency !== null;
        $this->subscriptionReminders = $subscription->notification_reminders ?? Auth::user()->notificationReminders();
        $this->subscriptionOverdue = $subscription->notification_overdue ?? Auth::user()->notificationOverdue();
        $this->subscriptionOverdueFrequency = $subscription->notification_overdue_frequency ?? Auth::user()->notificationOverdueFrequency();
    }

    public function addSubscriptionReminder(): void
    {
        $this->subscriptionReminders[] = 1;
    }

    public function removeSubscriptionReminder(int $index): void
    {
        unset($this->subscriptionReminders[$index]);
        $this->subscriptionReminders = array_values($this->subscriptionReminders);
    }

    public function deleteSubscription(int $subscriptionId): void
    {
        Subscription::query()->findOrFail($subscriptionId)->delete();

        unset($this->subscriptions);

        Flux::toast(variant: 'success', text: __('Subscription deleted.'));
    }

    public function startOccurrence(int $subscriptionId): void
    {
        $subscription = Subscription::query()->findOrFail($subscriptionId);

        $this->occurrenceSubscriptionId = $subscription->id;
        $this->occurrenceAmount = (string) $subscription->amount;
        $this->occurrenceCurrency = $subscription->currency;
        $this->occurrenceAccountAmount = '';
        $this->occurrenceDueDate = $subscription->next_due_date->toDateString();
        $this->occurrenceNotes = '';
    }

    public function saveOccurrence(): void
    {
        $subscription = Subscription::query()->with(['account', 'paymentMethod.primaryAccount', 'paymentMethod.secondaryAccount'])->findOrFail($this->occurrenceSubscriptionId);
        $accountCurrency = $subscription->account->currency;

        $rules = [
            'occurrenceAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'occurrenceCurrency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'occurrenceDueDate' => ['required', 'date'],
            'occurrenceNotes' => ['nullable', 'string', 'max:1000'],
        ];
        $rules['occurrenceAccountAmount'] = $this->occurrenceCurrency !== $accountCurrency
            ? ['required', 'numeric', 'gt:0', 'decimal:0,4']
            : ['nullable', 'numeric', 'gt:0', 'decimal:0,4'];

        $validated = $this->validate($rules);

        DB::transaction(function () use ($subscription, $validated, $accountCurrency): void {
            $method = $subscription->paymentMethod;
            $primaryTransaction = null;

            if ($method && $method->type === PaymentMethodType::Composite && $method->secondary_account_id) {
                $splits = $method->calculateSplit($validated['occurrenceAmount']);
                $splitGroupId = (string) Str::uuid();

                if ((float) $splits['primary_amount'] > 0) {
                    $primaryTransaction = new Transaction;
                    $primaryTransaction->forceFill([
                        'user_id' => $subscription->user_id,
                        'account_id' => $method->primary_account_id,
                        'category_id' => $subscription->category_id,
                        'payment_method_id' => $method->id,
                        'split_group_id' => $splitGroupId,
                        'type' => TransactionType::Expense,
                        'description' => $subscription->name,
                        'currency' => $validated['occurrenceCurrency'],
                        'amount' => $splits['primary_amount'],
                        'account_amount' => $this->occurrenceCurrency !== ($method->primaryAccount?->currency ?? $accountCurrency) ? $validated['occurrenceAccountAmount'] : null,
                        'transaction_date' => $validated['occurrenceDueDate'],
                    ]);
                    $primaryTransaction->save();
                }

                if ((float) $splits['secondary_amount'] > 0) {
                    $secondaryTransaction = new Transaction;
                    $secondaryTransaction->forceFill([
                        'user_id' => $subscription->user_id,
                        'account_id' => $method->secondary_account_id,
                        'category_id' => $subscription->category_id,
                        'payment_method_id' => $method->id,
                        'split_group_id' => $splitGroupId,
                        'type' => TransactionType::Expense,
                        'description' => $subscription->name,
                        'currency' => $validated['occurrenceCurrency'],
                        'amount' => $splits['secondary_amount'],
                        'account_amount' => $this->occurrenceCurrency !== ($method->secondaryAccount?->currency ?? $accountCurrency) ? $validated['occurrenceAccountAmount'] : null,
                        'transaction_date' => $validated['occurrenceDueDate'],
                    ]);
                    $secondaryTransaction->save();

                    $primaryTransaction ??= $secondaryTransaction;
                }
            } else {
                $primaryTransaction = $subscription->account->transactions()->create([
                    'category_id' => $subscription->category_id,
                    'payment_method_id' => $method?->id,
                    'type' => TransactionType::Expense,
                    'description' => $subscription->name,
                    'currency' => $validated['occurrenceCurrency'],
                    'amount' => $validated['occurrenceAmount'],
                    'account_amount' => $this->occurrenceCurrency !== $accountCurrency
                        ? $validated['occurrenceAccountAmount']
                        : null,
                    'transaction_date' => $validated['occurrenceDueDate'],
                ]);
            }

            $subscription->occurrences()->create([
                'transaction_id' => $primaryTransaction?->id,
                'currency' => $validated['occurrenceCurrency'],
                'amount' => $validated['occurrenceAmount'],
                'account_amount' => $this->occurrenceCurrency !== $accountCurrency
                    ? $validated['occurrenceAccountAmount']
                    : null,
                'due_date' => $validated['occurrenceDueDate'],
                'paid_at' => now(),
                'notes' => $validated['occurrenceNotes'] ?: null,
            ]);

            $subscription->next_due_date = $subscription->nextDueDateAfter($subscription->next_due_date);
            $subscription->save();
        });

        $this->resetOccurrenceForm();
        unset($this->subscriptions, $this->accounts);

        Flux::toast(variant: 'success', text: __('Occurrence recorded.'));
    }

    public function resetSubscriptionForm(): void
    {
        $account = $this->accounts->first();

        $this->reset('subscriptionName', 'subscriptionPaymentMethodId', 'subscriptionCategoryId', 'subscriptionAmount', 'editingSubscriptionId');
        $this->subscriptionAccountId = $account?->id;
        $this->subscriptionCurrency = $account?->currency ?? '';
        $this->subscriptionPeriodCount = '1';
        $this->subscriptionPeriodUnit = SubscriptionPeriodUnit::Month->value;
        $this->subscriptionNextDueDate = now()->toDateString();
        $this->subscriptionAutoPost = false;
        $this->subscriptionActive = true;
        $this->subscriptionNotificationChannels = [
            'database' => 'global',
            'email' => 'global',
            'webhook' => 'global',
            'discord' => 'global',
            'telegram' => 'global',
        ];
        $this->subscriptionOverrideNotifications = false;
        $this->subscriptionReminders = Auth::user()->notificationReminders();
        $this->subscriptionOverdue = Auth::user()->notificationOverdue();
        $this->subscriptionOverdueFrequency = Auth::user()->notificationOverdueFrequency();
        $this->resetValidation();
    }

    public function resetOccurrenceForm(): void
    {
        $this->reset('occurrenceSubscriptionId', 'occurrenceAmount', 'occurrenceCurrency', 'occurrenceAccountAmount', 'occurrenceNotes');
        $this->occurrenceDueDate = now()->toDateString();
        $this->resetValidation();
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10">
    <header class="flex flex-col gap-2 border-b border-zinc-200 pb-6 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Subscriptions & Recurring Expenses') }}</flux:heading>
        <flux:subheading>{{ __('Track recurring bills, streaming services, and rent with automatic or manual posting.') }}</flux:subheading>
    </header>

    <section class="space-y-5">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ $editingSubscriptionId ? __('Edit subscription') : __('New recurring subscription') }}</flux:heading>
            @if ($editingSubscriptionId)
                <flux:button variant="ghost" size="sm" wire:click="resetSubscriptionForm">{{ __('Cancel') }}</flux:button>
            @endif
        </div>

        <form wire:submit="saveSubscription" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <flux:input wire:model="subscriptionName" :label="__('Subscription Name')" placeholder="{{ __('e.g. Netflix, Spotify, Gym, Rent') }}" />
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Service or recurring bill name.') }}</flux:text>
                </div>

                <div>
                    <flux:select wire:model.live="subscriptionAccountId" :label="__('Drawn from Account')" placeholder="{{ __('Choose an account') }}">
                        @foreach ($this->accounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }} · {{ $account->balance() }})</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Primary account for this bill.') }}</flux:text>
                </div>

                @if ($this->paymentMethods->isNotEmpty())
                    <div>
                        <flux:select wire:model="subscriptionPaymentMethodId" :label="__('Payment Method (Optional)')" placeholder="{{ __('Direct / None') }}">
                            <flux:select.option value="">{{ __('Direct / None') }}</flux:select.option>
                            @foreach ($this->paymentMethods as $method)
                                <flux:select.option value="{{ $method->id }}">{{ $method->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Auto-split across linked accounts.') }}</flux:text>
                    </div>
                @endif

                <div>
                    <flux:select wire:model="subscriptionCategoryId" :label="__('Category')" placeholder="{{ __('No category') }}">
                        <flux:select.option value="">{{ __('No category') }}</flux:select.option>
                        @foreach ($this->categories as $category)
                            <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('For reporting and breakdown.') }}</flux:text>
                </div>

                <div>
                    <flux:input wire:model="subscriptionAmount" :label="__('Expected Amount')" type="number" step="0.0001" min="0" placeholder="0.00" />
                </div>

                <div>
                    <flux:input wire:model="subscriptionCurrency" :label="__('Currency')" maxlength="3" class="uppercase" placeholder="EUR" />
                </div>

                <div>
                    <flux:input wire:model="subscriptionPeriodCount" :label="__('Repeat Every')" type="number" min="1" />
                </div>

                <div>
                    <flux:select wire:model="subscriptionPeriodUnit" :label="__('Interval Unit')">
                        @foreach (SubscriptionPeriodUnit::cases() as $unit)
                            <flux:select.option value="{{ $unit->value }}">{{ __(ucfirst($unit->value)) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div>
                    <flux:input wire:model="subscriptionNextDueDate" :label="__('Next Due Date')" type="date" />
                </div>

                <div class="flex flex-col justify-center pt-2">
                    <flux:checkbox wire:model="subscriptionAutoPost" :label="__('Auto-post when due')" />
                    <flux:text size="xs" class="ms-6 text-zinc-500">{{ __('Automatically records expense without asking.') }}</flux:text>
                </div>

                <div class="flex flex-col justify-center pt-2">
                    <flux:checkbox wire:model="subscriptionActive" :label="__('Active subscription')" />
                    <flux:text size="xs" class="ms-6 text-zinc-500">{{ __('Uncheck to pause tracking.') }}</flux:text>
                </div>
            </div>

            <div class="mt-6 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="sm">{{ __('Custom notification schedule') }}</flux:heading>
                        <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Override the reminder schedule from your settings for this subscription.') }}</flux:text>
                    </div>
                    <flux:switch wire:model.live="subscriptionOverrideNotifications" />
                </div>

                @if ($this->subscriptionOverrideNotifications)
                    <div class="mt-4 space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <flux:text size="xs" class="mb-2 font-medium uppercase tracking-wide text-zinc-500">{{ __('Reminders') }}</flux:text>
                        @forelse ($this->subscriptionReminders as $index => $days)
                            <div class="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                <flux:input
                                    wire:model="subscriptionReminders.{{ $index }}"
                                    :label="__('Notify days before due')"
                                    type="number"
                                    min="1"
                                    max="365"
                                />
                                <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Remove reminder') }}" wire:click="removeSubscriptionReminder({{ $index }})" />
                            </div>
                        @empty
                            <flux:text size="sm" class="text-zinc-500">{{ __('No reminders.') }}</flux:text>
                        @endforelse
                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSubscriptionReminder">{{ __('Add reminder') }}</flux:button>

                        <div class="mt-4 flex items-start justify-between gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <div>
                                <flux:heading size="sm">{{ __('Notify about overdue payments') }}</flux:heading>
                                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Also applies to manual confirmation.') }}</flux:text>
                            </div>
                            <flux:switch wire:model.live="subscriptionOverdue" />
                        </div>

                        @if ($this->subscriptionOverdue)
                            <div class="max-w-md">
                                <flux:input wire:model="subscriptionOverdueFrequency" :label="__('Remind again every (:unit)', ['unit' => __('days')])" type="number" min="1" max="365" />
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex justify-end pt-2">
                <flux:button type="submit" variant="primary">{{ $editingSubscriptionId ? __('Save changes') : __('Add subscription') }}</flux:button>
            </div>
        </form>
    </section>

    @if ($occurrenceSubscriptionId)
        <section class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Record Specific Occurrence') }}</flux:heading>
                    <flux:text size="xs" class="text-zinc-500">{{ __('Confirm the actual price paid for this occurrence (e.g. price change or exchange rate).') }}</flux:text>
                </div>
                <flux:button variant="ghost" size="sm" wire:click="resetOccurrenceForm">{{ __('Cancel') }}</flux:button>
            </div>
            <form wire:submit="saveOccurrence" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:input wire:model="occurrenceAmount" :label="__('Actual amount paid')" type="number" step="0.0001" min="0" />
                <flux:input wire:model="occurrenceCurrency" :label="__('Currency')" maxlength="3" class="uppercase" />
                <flux:input wire:model="occurrenceDueDate" :label="__('Payment date')" type="date" />
                <flux:input wire:model="occurrenceNotes" :label="__('Notes (e.g. annual rate change)')" placeholder="{{ __('Optional') }}" />
                @php($occurrenceSubscription = $this->subscriptions->firstWhere('id', $occurrenceSubscriptionId))
                @if ($occurrenceSubscription && $occurrenceCurrency !== $occurrenceSubscription->account->currency)
                    <flux:input wire:model="occurrenceAccountAmount" :label="__('Amount on account (:curr)', ['curr' => $occurrenceSubscription->account->currency])" type="number" step="0.0001" min="0" />
                @endif
                <div class="flex items-end sm:col-span-2 lg:col-span-4">
                    <flux:button type="submit" variant="primary">{{ __('Confirm & Record Payment') }}</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="space-y-4">
        <flux:heading size="lg">{{ __('Your active & paused subscriptions') }}</flux:heading>
        <div class="divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->subscriptions as $subscription)
                <div wire:key="subscription-{{ $subscription->id }}" class="space-y-3 py-4">
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $subscription->name }}</span>
                                @if ($subscription->category)
                                    <span class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                        <span class="size-2 rounded-full" style="background-color: {{ $subscription->category->color }}" aria-hidden="true"></span>
                                        {{ $subscription->category->name }}
                                    </span>
                                @endif
                                @if ($subscription->next_due_date->isPast())
                                    <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                                @endif
                            </div>
                            <div class="mt-1 text-xs text-zinc-500">
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ App\Amount::display((string) $subscription->amount) }} {{ $subscription->currency }}</span>
                                · {{ __('Every :count :unit', ['count' => $subscription->period_count, 'unit' => ucfirst($subscription->period_unit->value)]) }}
                                · {{ $subscription->account->name }}
                                @if ($subscription->paymentMethod)
                                    ({{ $subscription->paymentMethod->name }})
                                @endif
                                · {{ __('Next Due:') }} <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $subscription->next_due_date->format('Y-m-d') }}</span>
                                @if ($subscription->auto_post)
                                    · <span class="text-green-600 dark:text-green-400">{{ __('Auto-posts') }}</span>
                                @else
                                    · <span>{{ __('Manual confirmation') }}</span>
                                @endif
                            </div>
                        </div>
                        <flux:badge :color="$subscription->active ? 'green' : 'zinc'">{{ $subscription->active ? __('Active') : __('Paused') }}</flux:badge>
                        <div class="flex shrink-0 gap-1">
                            <flux:button variant="ghost" size="sm" icon="check" aria-label="{{ __('Record occurrence') }}" wire:click="startOccurrence({{ $subscription->id }})" />
                            <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit subscription') }}" wire:click="editSubscription({{ $subscription->id }})" />
                            <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete subscription') }}" wire:click="deleteSubscription({{ $subscription->id }})" />
                        </div>
                    </div>
                    @if ($subscription->occurrences->isNotEmpty())
                        <div class="ps-4 text-xs text-zinc-500">
                            {{ __('Recent occurrences (:count total):', ['count' => $subscription->occurrences->count()]) }}
                            @foreach ($subscription->occurrences->take(3) as $occurrence)
                                <span class="ms-2 rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">{{ $occurrence->due_date->format('Y-m-d') }} ({{ App\Amount::display((string) $occurrence->amount) }} {{ $occurrence->currency }})</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="py-8 text-center text-sm text-zinc-500">
                    <div>{{ __('No subscriptions recorded yet.') }}</div>
                    <div class="mt-1 text-xs text-zinc-400">{{ __('Add Netflix, gym, rent, or recurring bills using the form above.') }}</div>
                </div>
            @endforelse
        </div>
    </section>
</section>
