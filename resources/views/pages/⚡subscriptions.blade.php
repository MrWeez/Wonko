<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Subscription;
use App\SubscriptionPeriodUnit;
use App\TransactionType;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Subscriptions')] class extends Component {
    public string $subscriptionName = '';

    public ?int $subscriptionAccountId = null;

    public ?int $subscriptionCategoryId = null;

    public string $subscriptionCurrency = '';

    public string $subscriptionAmount = '';

    public string $subscriptionPeriodCount = '1';

    public string $subscriptionPeriodUnit = SubscriptionPeriodUnit::Month->value;

    public string $subscriptionNextDueDate = '';

    public bool $subscriptionAutoPost = false;

    public bool $subscriptionActive = true;

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
        return Account::query()->orderBy('name')->get();
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
            ->with(['account', 'category', 'occurrences' => fn ($query) => $query->latest('due_date')])
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
            'subscriptionCategoryId' => ['nullable', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('user_id', auth()->id()))],
            'subscriptionCurrency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'subscriptionAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'subscriptionPeriodCount' => ['required', 'integer', 'min:1', 'max:3650'],
            'subscriptionPeriodUnit' => [Rule::enum(SubscriptionPeriodUnit::class)],
            'subscriptionNextDueDate' => ['required', 'date'],
            'subscriptionAutoPost' => ['boolean'],
            'subscriptionActive' => ['boolean'],
        ]);

        $subscription = $this->editingSubscriptionId
            ? Subscription::query()->findOrFail($this->editingSubscriptionId)
            : new Subscription;

        $subscription->fill([
            'account_id' => $validated['subscriptionAccountId'],
            'category_id' => $validated['subscriptionCategoryId'],
            'name' => $validated['subscriptionName'],
            'currency' => $validated['subscriptionCurrency'],
            'amount' => $validated['subscriptionAmount'],
            'period_count' => $validated['subscriptionPeriodCount'],
            'period_unit' => $validated['subscriptionPeriodUnit'],
            'next_due_date' => $validated['subscriptionNextDueDate'],
            'auto_post' => $validated['subscriptionAutoPost'],
            'active' => $validated['subscriptionActive'],
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
        $this->subscriptionCategoryId = $subscription->category_id;
        $this->subscriptionCurrency = $subscription->currency;
        $this->subscriptionAmount = (string) $subscription->amount;
        $this->subscriptionPeriodCount = (string) $subscription->period_count;
        $this->subscriptionPeriodUnit = $subscription->period_unit->value;
        $this->subscriptionNextDueDate = $subscription->next_due_date->toDateString();
        $this->subscriptionAutoPost = $subscription->auto_post;
        $this->subscriptionActive = $subscription->active;
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
        $subscription = Subscription::query()->with('account')->findOrFail($this->occurrenceSubscriptionId);
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

        $transaction = $subscription->account->transactions()->create([
            'category_id' => $subscription->category_id,
            'type' => TransactionType::Expense,
            'description' => $subscription->name,
            'currency' => $validated['occurrenceCurrency'],
            'amount' => $validated['occurrenceAmount'],
            'account_amount' => $this->occurrenceCurrency !== $accountCurrency
                ? $validated['occurrenceAccountAmount']
                : null,
            'transaction_date' => $validated['occurrenceDueDate'],
        ]);

        $subscription->occurrences()->create([
            'transaction_id' => $transaction->id,
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

        $this->resetOccurrenceForm();
        unset($this->subscriptions, $this->accounts);

        Flux::toast(variant: 'success', text: __('Occurrence recorded.'));
    }

    public function resetSubscriptionForm(): void
    {
        $account = $this->accounts->first();

        $this->reset('subscriptionName', 'subscriptionCategoryId', 'subscriptionAmount', 'editingSubscriptionId');
        $this->subscriptionAccountId = $account?->id;
        $this->subscriptionCurrency = $account?->currency ?? '';
        $this->subscriptionPeriodCount = '1';
        $this->subscriptionPeriodUnit = SubscriptionPeriodUnit::Month->value;
        $this->subscriptionNextDueDate = now()->toDateString();
        $this->subscriptionAutoPost = false;
        $this->subscriptionActive = true;
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
        <flux:heading size="xl">{{ __('Subscriptions') }}</flux:heading>
        <flux:subheading>{{ __('Track recurring payments and their actual occurrences.') }}</flux:subheading>
    </header>

    <section class="space-y-5">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ $editingSubscriptionId ? __('Edit subscription') : __('New subscription') }}</flux:heading>
            @if ($editingSubscriptionId)
                <flux:button variant="ghost" size="sm" wire:click="resetSubscriptionForm">{{ __('Cancel') }}</flux:button>
            @endif
        </div>

        <form wire:submit="saveSubscription" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:input wire:model="subscriptionName" :label="__('Name')" placeholder="{{ __('Subscription name') }}" />
            <flux:select wire:model.live="subscriptionAccountId" :label="__('Account')" placeholder="{{ __('Choose an account') }}">
                @foreach ($this->accounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="subscriptionCategoryId" :label="__('Category')" placeholder="{{ __('No category') }}">
                <flux:select.option value="">{{ __('No category') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="subscriptionAmount" :label="__('Amount')" type="number" step="0.0001" min="0" />
            <flux:input wire:model="subscriptionCurrency" :label="__('Currency')" maxlength="3" class="uppercase" />
            <flux:input wire:model="subscriptionPeriodCount" :label="__('Every')" type="number" min="1" />
            <flux:select wire:model="subscriptionPeriodUnit" :label="__('Period')">
                @foreach (SubscriptionPeriodUnit::cases() as $unit)
                    <flux:select.option value="{{ $unit->value }}">{{ __(ucfirst($unit->value)) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="subscriptionNextDueDate" :label="__('Next due date')" type="date" />
            <flux:checkbox wire:model="subscriptionAutoPost" :label="__('Post automatically when due')" />
            <flux:checkbox wire:model="subscriptionActive" :label="__('Active')" />
            <div class="flex items-end">
                <flux:button type="submit" variant="primary">{{ $editingSubscriptionId ? __('Save changes') : __('Add subscription') }}</flux:button>
            </div>
        </form>
    </section>

    @if ($occurrenceSubscriptionId)
        <section class="space-y-5 border-y border-zinc-200 py-6 dark:border-zinc-700">
            <div class="flex items-center justify-between gap-4">
                <flux:heading size="lg">{{ __('Record occurrence') }}</flux:heading>
                <flux:button variant="ghost" size="sm" wire:click="resetOccurrenceForm">{{ __('Cancel') }}</flux:button>
            </div>
            <form wire:submit="saveOccurrence" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:input wire:model="occurrenceAmount" :label="__('Actual amount')" type="number" step="0.0001" min="0" />
                <flux:input wire:model="occurrenceCurrency" :label="__('Currency')" maxlength="3" class="uppercase" />
                <flux:input wire:model="occurrenceDueDate" :label="__('Due date')" type="date" />
                <flux:input wire:model="occurrenceNotes" :label="__('Notes')" placeholder="{{ __('Optional') }}" />
                @php($occurrenceSubscription = $this->subscriptions->firstWhere('id', $occurrenceSubscriptionId))
                @if ($occurrenceSubscription && $occurrenceCurrency !== $occurrenceSubscription->account->currency)
                    <flux:input wire:model="occurrenceAccountAmount" :label="__('Amount on account')" type="number" step="0.0001" min="0" />
                @endif
                <flux:button type="submit" variant="primary">{{ __('Record occurrence') }}</flux:button>
            </form>
        </section>
    @endif

    <section class="space-y-4">
        <flux:heading size="lg">{{ __('Your subscriptions') }}</flux:heading>
        <div class="divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->subscriptions as $subscription)
                <div wire:key="subscription-{{ $subscription->id }}" class="space-y-3 py-4">
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <flux:text class="font-medium">{{ $subscription->name }}</flux:text>
                            <flux:text size="sm">
                                {{ $subscription->amount }} {{ $subscription->currency }} · {{ $subscription->account->name }} · {{ __('Due') }} {{ $subscription->next_due_date->format('Y-m-d') }}
                            </flux:text>
                        </div>
                        <flux:badge :color="$subscription->active ? 'green' : 'zinc'">{{ $subscription->active ? __('Active') : __('Paused') }}</flux:badge>
                        <div class="flex shrink-0 gap-1">
                            <flux:button variant="ghost" size="sm" icon="check" aria-label="{{ __('Record occurrence') }}" wire:click="startOccurrence({{ $subscription->id }})" />
                            <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit subscription') }}" wire:click="editSubscription({{ $subscription->id }})" />
                            <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete subscription') }}" wire:click="deleteSubscription({{ $subscription->id }})" />
                        </div>
                    </div>
                    @if ($subscription->occurrences->isNotEmpty())
                        <div class="ps-4 text-sm text-zinc-500">
                            {{ __('Occurrences') }}: {{ $subscription->occurrences->count() }}
                            @foreach ($subscription->occurrences->take(3) as $occurrence)
                                <span class="ms-3">{{ $occurrence->due_date->format('Y-m-d') }} · {{ $occurrence->amount }} {{ $occurrence->currency }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <flux:text class="py-4">{{ __('No subscriptions yet.') }}</flux:text>
            @endforelse
        </div>
    </section>
</section>
