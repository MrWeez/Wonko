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
            ->orderBy('name')
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
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function recentTransactions(): Collection
    {
        return Transaction::query()
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
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $account->name }}</div>
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
                        @forelse ($this->recentTransactions as $tx)
                            <tr wire:key="dash-tx-{{ $tx->id }}">
                                <td class="py-3 pe-4 text-zinc-500">{{ $tx->transaction_date->format('Y-m-d') }}</td>
                                <td class="py-3 pe-4 font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $tx->description ?? __('Untitled') }}
                                    @if ($tx->split_group_id)
                                        <span class="ms-1 text-xs text-zinc-500">({{ __('Split') }})</span>
                                    @endif
                                </td>
                                <td class="py-3 pe-4">
                                    @if ($tx->category)
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="size-2 rounded-full" style="background-color: {{ $tx->category->color }}" aria-hidden="true"></span>
                                            {{ $tx->category->name }}
                                        </span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="py-3 pe-4">
                                    @if ($tx->type === TransactionType::Transfer)
                                        <div class="space-y-1">
                                            <div class="flex flex-wrap items-center gap-1 text-sm">
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $tx->account->name }}</span>
                                                <flux:icon icon="arrow-right" class="size-3.5 shrink-0 text-zinc-400" />
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ $tx->relatedTransaction?->account?->name ?? __('Unknown') }}</span>
                                            </div>
                                            <flux:badge size="sm" color="blue">{{ __('Internal Transfer') }}</flux:badge>
                                        </div>
                                    @else
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ $tx->account->name }}</span>
                                    @endif
                                </td>
                                <td class="py-3 text-end">
                                    @if ($tx->type === TransactionType::Transfer)
                                        @php($dest = $tx->relatedTransaction)
                                        <div class="space-y-0.5">
                                            <div class="font-medium text-red-600 dark:text-red-400">
                                                −{{ number_format((float) $tx->amount, 2) }} {{ $tx->currency }}
                                            </div>
                                            @if ($dest)
                                                <div class="text-xs font-medium text-green-600 dark:text-green-400">
                                                    +{{ number_format((float) $dest->amount, 2) }} {{ $dest->currency }}
                                                </div>
                                            @endif
                                        </div>
                                    @elseif ($tx->type === TransactionType::Income)
                                        <div class="space-y-0.5">
                                            <div class="font-medium text-green-600 dark:text-green-400">
                                                +{{ number_format((float) $tx->amount, 2) }} {{ $tx->currency }}
                                            </div>
                                            @if ($tx->account_amount !== null)
                                                <div class="text-xs text-zinc-500">
                                                    +{{ number_format((float) $tx->account_amount, 2) }} {{ $tx->account->currency }}
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <div class="space-y-0.5">
                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                −{{ number_format((float) $tx->amount, 2) }} {{ $tx->currency }}
                                            </div>
                                            @if ($tx->account_amount !== null)
                                                <div class="text-xs text-zinc-500">
                                                    {{ number_format((float) $tx->account_amount, 2) }} {{ $tx->account->currency }}
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
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
