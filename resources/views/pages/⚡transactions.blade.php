<?php

use App\TransactionType;
use App\TransferDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transactions')] class extends Component {
    public ?int $transactionAccountId = null;

    public string $transactionType = TransactionType::Expense->value;

    public string $transactionDescription = '';

    public string $transactionCurrency = '';

    public string $transactionAmount = '';

    public string $transactionAccountAmount = '';

    public string $transactionDate = '';

    public ?int $transactionCategoryId = null;

    public ?int $transferDestinationAccountId = null;

    public string $transferDestinationCurrency = '';

    public string $transferDestinationAmount = '';

    public string $transferDestinationAccountAmount = '';

    public ?int $editingTransactionId = null;

    public ?int $filterAccountId = null;

    public string $search = '';

    public function mount(): void
    {
        $this->transactionDate = now()->toDateString();

        $account = $this->accounts->first();

        if ($account) {
            $this->transactionAccountId = $account->id;
            $this->transactionCurrency = $account->currency;
        }

        $destination = $this->accounts->first(fn (Account $candidate): bool => $candidate->id !== $account?->id);

        if ($destination) {
            $this->transferDestinationAccountId = $destination->id;
            $this->transferDestinationCurrency = $destination->currency;
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
        return Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function transactions(): Collection
    {
        return Transaction::query()
            ->with(['account', 'category', 'relatedTransaction.account'])
            ->where(function ($query): void {
                $query
                    ->where('type', '!=', TransactionType::Transfer->value)
                    ->orWhere('transfer_direction', TransferDirection::Source->value);
            })
            ->when($this->filterAccountId, fn ($query) => $query->where('account_id', $this->filterAccountId))
            ->when($this->search !== '', fn ($query) => $query->where('description', 'like', '%'.$this->search.'%'))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function updatedTransactionAccountId(?int $accountId): void
    {
        $this->transactionCurrency = Account::query()->find($accountId)?->currency ?? '';
        $this->transactionAccountAmount = '';
    }

    public function updatedTransactionCurrency(string $currency): void
    {
        $this->transactionCurrency = strtoupper($currency);
    }

    public function updatedTransactionType(string $type): void
    {
        if ($type === TransactionType::Transfer->value) {
            $this->transactionCategoryId = null;
        }
    }

    public function updatedTransferDestinationAccountId(?int $accountId): void
    {
        $this->transferDestinationCurrency = Account::query()->find($accountId)?->currency ?? '';
        $this->transferDestinationAccountAmount = '';
    }

    public function updatedTransferDestinationCurrency(string $currency): void
    {
        $this->transferDestinationCurrency = strtoupper($currency);
    }

    public function saveTransaction(): void
    {
        if ($this->transactionType === TransactionType::Transfer->value) {
            $this->saveTransfer();

            return;
        }

        $account = Account::query()->findOrFail($this->transactionAccountId);

        $rules = [
            'transactionAccountId' => ['required', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('user_id', auth()->id()))],
            'transactionType' => ['required', Rule::in([TransactionType::Expense->value, TransactionType::Income->value])],
            'transactionDescription' => ['nullable', 'string', 'max:255'],
            'transactionCategoryId' => [
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],
            'transactionCurrency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'transactionAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'transactionDate' => ['required', 'date'],
        ];

        if ($this->transactionCurrency !== $account->currency) {
            $rules['transactionAccountAmount'] = ['required', 'numeric', 'gt:0', 'decimal:0,4'];
        } else {
            $rules['transactionAccountAmount'] = ['nullable', 'numeric', 'gt:0', 'decimal:0,4'];
        }

        $validated = $this->validate($rules);

        $transaction = $this->editingTransactionId
            ? Transaction::query()->findOrFail($this->editingTransactionId)
            : new Transaction;

        $transaction->fill([
            'account_id' => $validated['transactionAccountId'],
            'category_id' => $validated['transactionCategoryId'],
            'type' => $validated['transactionType'],
            'description' => $validated['transactionDescription'] ?: null,
            'currency' => $validated['transactionCurrency'],
            'amount' => $validated['transactionAmount'],
            'account_amount' => $this->transactionCurrency !== $account->currency
                ? $validated['transactionAccountAmount']
                : null,
            'transaction_date' => $validated['transactionDate'],
        ]);
        $transaction->save();

        $this->resetTransactionForm();
        unset($this->transactions, $this->accounts);

        Flux::toast(variant: 'success', text: __('Transaction saved.'));
    }

    public function saveTransfer(): void
    {
        $sourceAccount = Account::query()->findOrFail($this->transactionAccountId);
        $destinationAccount = Account::query()->findOrFail($this->transferDestinationAccountId);

        $rules = [
            'transactionAccountId' => ['required', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('user_id', auth()->id()))],
            'transferDestinationAccountId' => [
                'required',
                'different:transactionAccountId',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],
            'transactionDescription' => ['nullable', 'string', 'max:255'],
            'transactionCurrency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'transactionAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'transferDestinationCurrency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'transferDestinationAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'transactionDate' => ['required', 'date'],
        ];

        $rules['transactionAccountAmount'] = $this->transactionCurrency !== $sourceAccount->currency
            ? ['required', 'numeric', 'gt:0', 'decimal:0,4']
            : ['nullable', 'numeric', 'gt:0', 'decimal:0,4'];
        $rules['transferDestinationAccountAmount'] = $this->transferDestinationCurrency !== $destinationAccount->currency
            ? ['required', 'numeric', 'gt:0', 'decimal:0,4']
            : ['nullable', 'numeric', 'gt:0', 'decimal:0,4'];

        $validated = $this->validate($rules);

        DB::transaction(function () use ($validated, $sourceAccount, $destinationAccount): void {
            if ($this->editingTransactionId) {
                $existingSource = Transaction::query()->findOrFail($this->editingTransactionId);
                $existingDestination = $existingSource->relatedTransaction;
                $existingDestination?->delete();
                $existingSource->delete();
            }

            $source = new Transaction;
            $source->fill([
                'account_id' => $sourceAccount->id,
                'type' => TransactionType::Transfer,
                'transfer_direction' => TransferDirection::Source,
                'description' => $validated['transactionDescription'] ?: null,
                'currency' => $validated['transactionCurrency'],
                'amount' => $validated['transactionAmount'],
                'account_amount' => $this->transactionCurrency !== $sourceAccount->currency
                    ? $validated['transactionAccountAmount']
                    : null,
                'transaction_date' => $validated['transactionDate'],
            ]);
            $source->save();

            $destination = new Transaction;
            $destination->fill([
                'account_id' => $destinationAccount->id,
                'related_transaction_id' => $source->id,
                'type' => TransactionType::Transfer,
                'transfer_direction' => TransferDirection::Destination,
                'description' => $validated['transactionDescription'] ?: null,
                'currency' => $validated['transferDestinationCurrency'],
                'amount' => $validated['transferDestinationAmount'],
                'account_amount' => $this->transferDestinationCurrency !== $destinationAccount->currency
                    ? $validated['transferDestinationAccountAmount']
                    : null,
                'transaction_date' => $validated['transactionDate'],
            ]);
            $destination->save();

            $source->update(['related_transaction_id' => $destination->id]);
        });

        $this->resetTransactionForm();
        unset($this->transactions, $this->accounts);

        Flux::toast(variant: 'success', text: __('Transfer saved.'));
    }

    public function editTransaction(int $transactionId): void
    {
        $transaction = Transaction::query()->findOrFail($transactionId);

        $this->editingTransactionId = $transaction->id;
        $this->transactionAccountId = $transaction->account_id;
        $this->transactionType = $transaction->type->value;
        $this->transactionDescription = $transaction->description ?? '';
        $this->transactionCategoryId = $transaction->category_id;
        $this->transactionCurrency = $transaction->currency;
        $this->transactionAmount = (string) $transaction->amount;
        $this->transactionAccountAmount = (string) ($transaction->account_amount ?? '');
        $this->transactionDate = $transaction->transaction_date->toDateString();

        if ($transaction->type === TransactionType::Transfer) {
            $destination = $transaction->relatedTransaction;

            $this->transferDestinationAccountId = $destination?->account_id;
            $this->transferDestinationCurrency = $destination?->currency ?? '';
            $this->transferDestinationAmount = (string) ($destination?->amount ?? '');
            $this->transferDestinationAccountAmount = (string) ($destination?->account_amount ?? '');
        }
    }

    public function deleteTransaction(int $transactionId): void
    {
        $transaction = Transaction::query()->findOrFail($transactionId);

        if ($transaction->type === TransactionType::Transfer) {
            $transaction->relatedTransaction?->delete();
        }

        $transaction->delete();

        unset($this->transactions);

        Flux::toast(variant: 'success', text: __('Transaction deleted.'));
    }

    public function resetTransactionForm(): void
    {
        $account = $this->accounts->first();

        $this->reset(
            'editingTransactionId',
            'transactionDescription',
            'transactionCategoryId',
            'transactionAmount',
            'transactionAccountAmount',
            'transferDestinationAccountId',
            'transferDestinationCurrency',
            'transferDestinationAmount',
            'transferDestinationAccountAmount',
        );
        $this->transactionType = TransactionType::Expense->value;
        $this->transactionDate = now()->toDateString();
        $this->transactionAccountId = $account?->id;
        $this->transactionCurrency = $account?->currency ?? '';
        $destination = $this->accounts->first(fn (Account $candidate): bool => $candidate->id !== $account?->id);
        $this->transferDestinationAccountId = $destination?->id;
        $this->transferDestinationCurrency = $destination?->currency ?? '';
        $this->resetValidation();
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10">
    <header class="flex flex-col gap-2 border-b border-zinc-200 pb-6 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Transactions') }}</flux:heading>
        <flux:subheading>{{ __('Record income and expenses across your accounts.') }}</flux:subheading>
    </header>

    <section class="space-y-5">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ $editingTransactionId ? __('Edit transaction') : __('New transaction') }}</flux:heading>
            @if ($editingTransactionId)
                <flux:button variant="ghost" size="sm" wire:click="resetTransactionForm">{{ __('Cancel') }}</flux:button>
            @endif
        </div>

        <form wire:submit="saveTransaction" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select wire:model.live="transactionAccountId" :label="__('Account')" placeholder="{{ __('Choose an account') }}">
                @foreach ($this->accounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="transactionType" :label="__('Type')">
                <flux:select.option value="expense">{{ __('Expense') }}</flux:select.option>
                <flux:select.option value="income">{{ __('Income') }}</flux:select.option>
                <flux:select.option value="transfer">{{ __('Transfer') }}</flux:select.option>
            </flux:select>

            <flux:input wire:model="transactionAmount" :label="__('Amount')" type="number" step="0.0001" min="0" />
            <flux:input wire:model="transactionCurrency" :label="__('Currency')" maxlength="3" class="uppercase" />

            @if ($transactionType !== TransactionType::Transfer->value)
                <flux:select wire:model="transactionCategoryId" :label="__('Category')" placeholder="{{ __('No category') }}">
                    <flux:select.option value="">{{ __('No category') }}</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($transactionAccountId && $transactionCurrency !== ($this->accounts->firstWhere('id', $transactionAccountId)?->currency ?? ''))
                <flux:input wire:model="transactionAccountAmount" :label="__('Amount on account')" type="number" step="0.0001" min="0" />
            @endif

            @if ($transactionType === TransactionType::Transfer->value)
                <flux:select wire:model.live="transferDestinationAccountId" :label="__('Destination account')" placeholder="{{ __('Choose an account') }}">
                    @foreach ($this->accounts as $account)
                        @if ($account->id !== $transactionAccountId)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                        @endif
                    @endforeach
                </flux:select>
                <flux:input wire:model="transferDestinationAmount" :label="__('Destination amount')" type="number" step="0.0001" min="0" />
                <flux:input wire:model="transferDestinationCurrency" :label="__('Destination currency')" maxlength="3" class="uppercase" />

                @if ($transferDestinationAccountId && $transferDestinationCurrency !== ($this->accounts->firstWhere('id', $transferDestinationAccountId)?->currency ?? ''))
                    <flux:input wire:model="transferDestinationAccountAmount" :label="__('Destination amount on account')" type="number" step="0.0001" min="0" />
                @endif
            @endif

            <flux:input wire:model="transactionDescription" :label="__('Description')" placeholder="{{ __('Optional') }}" />
            <flux:input wire:model="transactionDate" :label="__('Date')" type="date" />

            <div class="flex items-end">
                <flux:button type="submit" variant="primary">{{ $editingTransactionId ? __('Save changes') : __('Add transaction') }}</flux:button>
            </div>
        </form>
    </section>

    <section class="space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <flux:heading size="lg">{{ __('History') }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:select wire:model.live="filterAccountId" :label="__('Account')" placeholder="{{ __('All accounts') }}">
                    <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
                    @foreach ($this->accounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Description') }}" />
            </div>
        </div>

        <div class="overflow-x-auto border-y border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-start text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="py-3 pe-4 font-medium">{{ __('Date') }}</th>
                        <th class="py-3 pe-4 font-medium">{{ __('Description') }}</th>
                        <th class="py-3 pe-4 font-medium">{{ __('Category') }}</th>
                        <th class="py-3 pe-4 font-medium">{{ __('Account') }}</th>
                        <th class="py-3 pe-4 text-end font-medium">{{ __('Amount') }}</th>
                        <th class="py-3 text-end font-medium">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->transactions as $transaction)
                        <tr wire:key="transaction-{{ $transaction->id }}">
                            <td class="py-3 pe-4">{{ $transaction->transaction_date->format('Y-m-d') }}</td>
                            <td class="py-3 pe-4">{{ $transaction->description ?? __('Untitled') }}</td>
                            <td class="py-3 pe-4">
                                @if ($transaction->category)
                                    <span class="inline-flex items-center gap-2">
                                        <span class="size-2.5 rounded-full" style="background-color: {{ $transaction->category->color }}" aria-hidden="true"></span>
                                        {{ $transaction->category->name }}
                                    </span>
                                @else
                                    <span class="text-zinc-500">{{ __('Uncategorized') }}</span>
                                @endif
                            </td>
                            <td class="py-3 pe-4">
                                @if ($transaction->type === TransactionType::Transfer)
                                    {{ $transaction->account->name }} &rarr; {{ $transaction->relatedTransaction?->account?->name ?? __('Unknown account') }}
                                @else
                                    {{ $transaction->account->name }}
                                @endif
                            </td>
                            <td class="py-3 pe-4 text-end {{ $transaction->type === TransactionType::Income ? 'text-green-600 dark:text-green-400' : '' }}">
                                {{ $transaction->type === TransactionType::Income ? '+' : '-' }}{{ $transaction->amountOnAccount() }} {{ $transaction->account->currency }}
                            </td>
                            <td class="py-3 text-end">
                                <div class="flex justify-end gap-1">
                                    <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit transaction') }}" wire:click="editTransaction({{ $transaction->id }})" />
                                    <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete transaction') }}" wire:click="deleteTransaction({{ $transaction->id }})" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-zinc-500">{{ __('No transactions yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
