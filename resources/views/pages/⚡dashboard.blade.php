<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\Transaction;
use App\TransactionType;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()
            ->with('bank')
            ->defaultOrder()
            ->get();
    }

    /**
     * @return Collection<string, string>
     */
    #[Computed]
    public function balancesByCurrency(): Collection
    {
        $balances = [];

        foreach ($this->accounts as $account) {
            $currency = $account->currency;
            $balances[$currency] = bcadd($balances[$currency] ?? '0', $account->balance(), 4);
        }

        return collect($balances);
    }

    /**
     * @return Collection<int, Subscription>
     */
    #[Computed]
    public function upcomingSubscriptions(): Collection
    {
        return Subscription::query()
            ->with(['account', 'category', 'paymentMethod'])
            ->where('active', true)
            ->whereDate('next_due_date', '<=', now()->addDays(7))
            ->orderBy('next_due_date')
            ->limit(5)
            ->get();
    }

    /**
     * Recent transactions collapsed into visual groups (split legs merged).
     *
     * @return Collection<int, Collection<int, Transaction>>
     */
    #[Computed]
    public function recentTransactions(): Collection
    {
        $rows = Transaction::query()
            ->with(['account', 'category', 'paymentMethod', 'relatedTransaction.account'])
            ->where(function ($query): void {
                $query
                    ->where('type', '!=', TransactionType::Transfer->value)
                    ->orWhere('transfer_direction', \App\TransferDirection::Source->value);
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(7)
            ->get();

        $groups = [];
        $indexes = [];

        foreach ($rows as $transaction) {
            $key = $transaction->split_group_id ? 'split:'.$transaction->split_group_id : 'single:'.$transaction->id;

            if (! array_key_exists($key, $indexes)) {
                $indexes[$key] = count($groups);
                $groups[] = collect();
            }

            $groups[$indexes[$key]]->push($transaction);
        }

        return collect($groups);
    }

    public function quickConfirmSubscription(int $subscriptionId): void
    {
        $subscription = Subscription::query()->with('account')->findOrFail($subscriptionId);

        $transaction = $subscription->account->transactions()->create([
            'category_id' => $subscription->category_id,
            'payment_method_id' => $subscription->payment_method_id,
            'type' => TransactionType::Expense,
            'description' => $subscription->name,
            'currency' => $subscription->currency,
            'amount' => $subscription->amount,
            'transaction_date' => $subscription->next_due_date,
        ]);

        $subscription->occurrences()->create([
            'transaction_id' => $transaction->id,
            'currency' => $subscription->currency,
            'amount' => $subscription->amount,
            'due_date' => $subscription->next_due_date,
            'paid_at' => now(),
            'notes' => __('Quick confirmed from dashboard'),
        ]);

        $subscription->next_due_date = $subscription->nextDueDateAfter($subscription->next_due_date);
        $subscription->save();

        unset($this->upcomingSubscriptions, $this->accounts, $this->balancesByCurrency, $this->recentTransactions);

        Flux::toast(variant: 'success', text: __('Subscription payment recorded.'));
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10">
    <header class="flex flex-col gap-2 border-b border-zinc-200 pb-6 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Financial Overview') }}</flux:heading>
        <flux:subheading>{{ __('Real-time balances, upcoming payments, and recent activity.') }}</flux:subheading>
    </header>

    @if ($this->accounts->isEmpty())
        {{-- Onboarding state --}}
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="mb-2">{{ __('Welcome to Wonko! 👋') }}</flux:heading>
            <flux:text class="mb-6">{{ __('Wonko gives you full control of your finances with no automatic FX conversion and total privacy. Here is how to get started in 3 easy steps:') }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-2 inline-flex size-7 items-center justify-center rounded-full bg-zinc-900 text-xs font-bold text-white dark:bg-white dark:text-zinc-900">1</div>
                    <flux:heading size="sm">{{ __('Create an Account') }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">{{ __('Add your bank accounts, wallets, credit cards, or cash balances.') }}</flux:text>
                    <div class="mt-4">
                        <flux:button :href="route('accounts.index')" size="sm" variant="primary" wire:navigate>{{ __('Go to Accounts') }}</flux:button>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-2 inline-flex size-7 items-center justify-center rounded-full bg-zinc-900 text-xs font-bold text-white dark:bg-white dark:text-zinc-900">2</div>
                    <flux:heading size="sm">{{ __('(Optional) Payment Methods') }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">{{ __('Link accounts into composite methods like PayPal to auto-split balances.') }}</flux:text>
                    <div class="mt-4">
                        <flux:button :href="route('payment-methods.index')" size="sm" variant="ghost" wire:navigate>{{ __('Payment Methods') }}</flux:button>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-2 inline-flex size-7 items-center justify-center rounded-full bg-zinc-900 text-xs font-bold text-white dark:bg-white dark:text-zinc-900">3</div>
                    <flux:heading size="sm">{{ __('Log Transactions') }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">{{ __('Track income, everyday expenses, transfers, and recurring subscriptions.') }}</flux:text>
                    <div class="mt-4">
                        <flux:button :href="route('transactions.index')" size="sm" variant="ghost" wire:navigate>{{ __('Transactions') }}</flux:button>
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- Totals by Currency --}}
        <section class="space-y-3">
            <flux:heading size="lg">{{ __('Total Balances by Currency') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->balancesByCurrency as $currency => $balance)
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900">
                        <flux:text size="sm" class="text-zinc-500">{{ __('Net Total (:currency)', ['currency' => $currency]) }}</flux:text>
                        <div class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                            {{ number_format((float) $balance, 2) }} {{ $currency }}
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Main 2-column Grid --}}
        <div class="grid gap-8 lg:grid-cols-2">
            {{-- Accounts Glance --}}
            <section class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Your Accounts') }}</flux:heading>
                    <flux:button :href="route('accounts.index')" size="xs" variant="ghost" wire:navigate>{{ __('Manage accounts') }} &rarr;</flux:button>
                </div>

                <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach ($this->accounts as $account)
                        <div wire:key="dash-account-{{ $account->id }}" class="flex items-center justify-between p-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $account->name }}
                                    @if ($account->is_primary)
                                        <flux:badge size="sm" color="zinc">{{ __('Primary') }}</flux:badge>
                                    @endif
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ __(ucfirst($account->type->value)) }}
                                    @if ($account->bank)
                                        · {{ $account->bank->name }}
                                    @endif
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ number_format((float) $account->balance(), 2) }} {{ $account->currency }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Upcoming / Overdue Subscriptions --}}
            <section class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Upcoming & Due Subscriptions') }}</flux:heading>
                    <flux:button :href="route('subscriptions.index')" size="xs" variant="ghost" wire:navigate>{{ __('All subscriptions') }} &rarr;</flux:button>
                </div>

                <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
                    @forelse ($this->upcomingSubscriptions as $sub)
                        @php($isOverdue = $sub->next_due_date->isPast())
                        <div wire:key="dash-sub-{{ $sub->id }}" class="flex items-center justify-between p-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $sub->name }}</span>
                                    @if ($isOverdue)
                                        <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="amber">{{ __('Due soon') }}</flux:badge>
                                    @endif
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ $sub->amount }} {{ $sub->currency }} · {{ __('Due') }} {{ $sub->next_due_date->format('M j, Y') }}
                                </div>
                            </div>
                            <div>
                                <flux:button size="xs" variant="primary" wire:click="quickConfirmSubscription({{ $sub->id }})">
                                    {{ __('Mark Paid') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="p-6 text-center text-sm text-zinc-500">
                            {{ __('No payments due in the next 7 days.') }}
                        </div>
                    @endforelse
                </div>
            </section>
        </div>

        {{-- Recent Activity --}}
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Recent Transactions') }}</flux:heading>
                <flux:button :href="route('transactions.index')" size="xs" variant="ghost" wire:navigate>{{ __('View all transactions') }} &rarr;</flux:button>
            </div>

            <div class="overflow-x-auto border-y border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-start text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="py-3 pe-4 font-medium">{{ __('Date') }}</th>
                            <th class="py-3 pe-4 font-medium">{{ __('Description') }}</th>
                            <th class="py-3 pe-4 font-medium">{{ __('Category') }}</th>
                            <th class="py-3 pe-4 font-medium">{{ __('Account') }}</th>
                            <th class="py-3 text-end font-medium">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
<tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->recentTransactions as $group)
                            @php($first = $group->first())
                            @if ($first->split_group_id && $group->count() > 1)
                                @php($total = $group->reduce(fn (float $carry, $transaction): float => $carry + (float) $transaction->amount, 0.0))
                                @php($sign = $first->type === TransactionType::Income ? '+' : '−')
                                <tr wire:key="dash-split-{{ $first->split_group_id }}" x-data="{ splitOpen: false }">
                                    <td class="py-3 pe-3">
                                        <div class="flex items-center gap-1.5">
                                            <button
                                                type="button"
                                                class="-ms-1 inline-flex size-6 shrink-0 items-center justify-center rounded text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                                @click="splitOpen = !splitOpen"
                                                :aria-label="splitOpen ? '{{ __('Collapse split') }}' : '{{ __('Expand split') }}'"
                                            >
                                                <flux:icon icon="chevron-right" class="size-4 transition-transform" x-bind:class="splitOpen === true ? 'rotate-180' : ''" />
                                            </button>
                                            <span class="text-zinc-500">{{ $first->transaction_date->format('Y-m-d') }}</span>
                                        </div>
                                    </td>
                                    <td class="py-3 pe-4">
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $first->description ?? __('Untitled') }}</span>
                                        <span class="ms-1 text-xs text-zinc-500">({{ __('Split') }} · {{ $group->count() }})</span>
                                    </td>
                                    <td class="py-3 pe-4">
                                        @if ($first->category)
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="size-2 rounded-full" style="background-color: {{ $first->category->color }}" aria-hidden="true"></span>
                                                {{ $first->category->name }}
                                            </span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pe-4">
                                        @if ($first->paymentMethod)
                                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $first->paymentMethod->name }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 text-end font-medium text-zinc-900 dark:text-zinc-100">{{ $sign }}{{ number_format($total, 2) }} {{ $first->currency }}</td>
                                </tr>
                                <tr wire:key="dash-split-detail-{{ $first->split_group_id }}" x-show="splitOpen" x-cloak>
                                    <td colspan="5" class="px-5 pb-4 ps-12">
                                        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                                            <table class="w-full text-sm">
                                                <thead class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                                    <tr>
                                                        <th class="px-4 py-2 text-start font-medium">{{ __('Part') }}</th>
                                                        <th class="px-4 py-2 text-start font-medium">{{ __('Account') }}</th>
                                                        <th class="px-4 py-2 text-start font-medium">{{ __('Method') }}</th>
                                                        <th class="px-4 py-2 text-end font-medium">{{ __('Amount') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                                    @foreach ($group as $index => $leg)
                                                        <tr>
                                                            <td class="px-4 py-2 text-zinc-500">#{{ $index + 1 }}</td>
                                                            <td class="px-4 py-2 font-medium text-zinc-800 dark:text-zinc-200">{{ $leg->account->name }}</td>
                                                            <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $leg->paymentMethod?->name ?? '—' }}</td>
                                                            <td class="px-4 py-2 text-end text-zinc-700 dark:text-zinc-300">{{ $sign }}{{ number_format((float) $leg->amount, 2) }} {{ $leg->currency }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            @else
                                <tr wire:key="dash-tx-{{ $first->id }}">
                                    <td class="py-3 pe-4 text-zinc-500">{{ $first->transaction_date->format('Y-m-d') }}</td>
                                    <td class="py-3 pe-4 font-medium text-zinc-900 dark:text-zinc-100">{{ $first->description ?? __('Untitled') }}</td>
                                    <td class="py-3 pe-4">
                                        @if ($first->category)
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="size-2 rounded-full" style="background-color: {{ $first->category->color }}" aria-hidden="true"></span>
                                                {{ $first->category->name }}
                                            </span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pe-4">
                                        @if ($first->type === TransactionType::Transfer)
                                            <div class="space-y-1">
                                                <div class="flex flex-wrap items-center gap-1 text-sm">
                                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $first->account->name }}</span>
                                                    <flux:icon icon="arrow-right" class="size-3.5 shrink-0 text-zinc-400" />
                                                    <span class="text-zinc-600 dark:text-zinc-400">{{ $first->relatedTransaction?->account?->name ?? __('Unknown') }}</span>
                                                </div>
                                                <flux:badge size="sm" color="blue">{{ __('Internal Transfer') }}</flux:badge>
                                            </div>
                                        @else
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ $first->account->name }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 text-end">
                                        @if ($first->type === TransactionType::Transfer)
                                            @php($dest = $first->relatedTransaction)
                                            <div class="space-y-0.5">
                                                <div class="font-medium text-red-600 dark:text-red-400">
                                                    −{{ number_format((float) $first->amount, 2) }} {{ $first->currency }}
                                                </div>
                                                @if ($dest)
                                                    <div class="text-xs font-medium text-green-600 dark:text-green-400">
                                                        +{{ number_format((float) $dest->amount, 2) }} {{ $dest->currency }}
                                                    </div>
                                                @endif
                                            </div>
                                        @elseif ($first->type === TransactionType::Income)
                                            <div class="font-medium text-green-600 dark:text-green-400">
                                                +{{ number_format((float) $first->amount, 2) }} {{ $first->currency }}
                                            </div>
                                        @else
                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                −{{ number_format((float) $first->amount, 2) }} {{ $first->currency }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-zinc-500">{{ __('No recent transactions.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</section>
