<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\PaymentMethodType;
use App\TransactionType;
use App\TransferDirection;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transactions')] class extends Component {
    public ?int $transactionAccountId = null;

    public string $transactionType = TransactionType::Expense->value;

    public string $paymentSourceMode = 'account';

    public ?int $paymentMethodId = null;

    public ?int $splitSecondaryAccountId = null;

    public string $splitPrimaryAmount = '';

    public string $splitSecondaryAmount = '';

    public string $splitPrimaryAccountAmount = '';

    public string $splitSecondaryAccountAmount = '';

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

    public ?string $editingSplitGroupId = null;

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
            $this->splitSecondaryAccountId = $destination->id;
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
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function paymentMethods(): Collection
    {
        return PaymentMethod::query()
            ->with(['primaryAccount', 'secondaryAccount'])
            ->orderBy('name')
            ->get();
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
            ->with(['account', 'category', 'paymentMethod', 'relatedTransaction.account'])
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
            $this->paymentSourceMode = 'account';
        }
    }

    public function updatedPaymentSourceMode(string $mode): void
    {
        if ($mode === 'payment_method') {
            $method = $this->paymentMethods->first();
            if ($method) {
                $this->paymentMethodId = $method->id;
                $this->applyPaymentMethod($method);
            }
        } elseif ($mode === 'split') {
            $this->paymentMethodId = null;
            if (! $this->splitSecondaryAccountId) {
                $this->splitSecondaryAccountId = $this->accounts->first(fn ($a) => $a->id !== $this->transactionAccountId)?->id;
            }
            $this->splitPrimaryAmount = $this->transactionAmount;
            $this->splitSecondaryAmount = '0';
        } else {
            $this->paymentMethodId = null;
            $this->splitSecondaryAccountId = null;
        }
    }

    public function updatedPaymentMethodId(?int $methodId): void
    {
        if (! $methodId) {
            return;
        }

        $method = PaymentMethod::query()->find($methodId);
        if ($method) {
            $this->applyPaymentMethod($method);
        }
    }

    public function applyPaymentMethod(PaymentMethod $method): void
    {
        $this->transactionAccountId = $method->primary_account_id;
        $this->splitSecondaryAccountId = $method->secondary_account_id;
        $this->transactionCurrency = $method->primaryAccount?->currency ?? $this->transactionCurrency;

        if ($this->transactionAmount !== '' && $method->auto_split_balance) {
            $this->recalculateSplit();
        } else {
            $this->splitPrimaryAmount = $this->transactionAmount;
            $this->splitSecondaryAmount = '0';
        }
    }

    public function updatedTransactionAmount(string $amount): void
    {
        if ($this->paymentSourceMode === 'payment_method' && $this->paymentMethodId) {
            $method = PaymentMethod::query()->find($this->paymentMethodId);
            if ($method?->auto_split_balance) {
                $this->recalculateSplit();
            } else {
                $this->splitPrimaryAmount = $amount;
                $this->splitSecondaryAmount = '0';
            }
        } elseif ($this->paymentSourceMode === 'split') {
            $this->splitPrimaryAmount = $amount;
            $this->splitSecondaryAmount = '0';
        }
    }

    public function recalculateSplit(): void
    {
        if (! $this->paymentMethodId) {
            return;
        }

        $method = PaymentMethod::query()->find($this->paymentMethodId);
        if (! $method) {
            return;
        }

        $splits = $method->calculateSplit($this->transactionAmount ?: '0');
        $this->splitPrimaryAmount = (string) (float) $splits['primary_amount'];
        $this->splitSecondaryAmount = (string) (float) $splits['secondary_amount'];
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

        if ($this->transactionType === TransactionType::Expense->value && in_array($this->paymentSourceMode, ['payment_method', 'split'], true)) {
            $this->saveSplitTransaction();

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

        DB::transaction(function () use ($validated, $account): void {
            if ($this->editingSplitGroupId) {
                Transaction::query()->where('split_group_id', $this->editingSplitGroupId)->delete();
            }

            $transaction = $this->editingTransactionId && ! $this->editingSplitGroupId
                ? Transaction::query()->findOrFail($this->editingTransactionId)
                : new Transaction;

            $transaction->fill([
                'account_id' => $validated['transactionAccountId'],
                'category_id' => $validated['transactionCategoryId'],
                'payment_method_id' => null,
                'split_group_id' => null,
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
        });

        $this->resetTransactionForm();
        unset($this->transactions, $this->accounts);

        Flux::toast(variant: 'success', text: __('Transaction saved.'));
    }

    public function saveSplitTransaction(): void
    {
        $primaryAccount = Account::query()->findOrFail($this->transactionAccountId);
        $secondaryAccount = $this->splitSecondaryAccountId ? Account::query()->findOrFail($this->splitSecondaryAccountId) : null;

        $rules = [
            'transactionAccountId' => ['required', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('user_id', auth()->id()))],
            'splitSecondaryAccountId' => [
                'nullable',
                'different:transactionAccountId',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],
            'paymentMethodId' => [
                'nullable',
                Rule::exists('payment_methods', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],
            'transactionDescription' => ['nullable', 'string', 'max:255'],
            'transactionCategoryId' => [
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],
            'transactionCurrency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'transactionAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'splitPrimaryAmount' => ['required', 'numeric', 'gte:0', 'decimal:0,4'],
            'splitSecondaryAmount' => ['required', 'numeric', 'gte:0', 'decimal:0,4'],
            'transactionDate' => ['required', 'date'],
        ];

        if ($this->transactionCurrency !== $primaryAccount->currency && (float) $this->splitPrimaryAmount > 0) {
            $rules['splitPrimaryAccountAmount'] = ['required', 'numeric', 'gt:0', 'decimal:0,4'];
        } else {
            $rules['splitPrimaryAccountAmount'] = ['nullable', 'numeric', 'gt:0', 'decimal:0,4'];
        }

        if ($secondaryAccount && $this->transactionCurrency !== $secondaryAccount->currency && (float) $this->splitSecondaryAmount > 0) {
            $rules['splitSecondaryAccountAmount'] = ['required', 'numeric', 'gt:0', 'decimal:0,4'];
        } else {
            $rules['splitSecondaryAccountAmount'] = ['nullable', 'numeric', 'gt:0', 'decimal:0,4'];
        }

        $validated = $this->validate($rules);

        $totalSplit = (float) $validated['splitPrimaryAmount'] + (float) $validated['splitSecondaryAmount'];
        $expectedTotal = (float) $validated['transactionAmount'];

        if (abs($totalSplit - $expectedTotal) > 0.0001) {
            throw ValidationException::withMessages([
                'splitPrimaryAmount' => __('The split amounts must sum to the total transaction amount (:total).', ['total' => $validated['transactionAmount']]),
            ]);
        }

        DB::transaction(function () use ($validated, $primaryAccount, $secondaryAccount): void {
            if ($this->editingSplitGroupId) {
                Transaction::query()->where('split_group_id', $this->editingSplitGroupId)->delete();
            } elseif ($this->editingTransactionId) {
                Transaction::query()->findOrFail($this->editingTransactionId)->delete();
            }

            $splitGroupId = (string) Str::uuid();

            if ((float) $validated['splitPrimaryAmount'] > 0) {
                $primaryTx = new Transaction;
                $primaryTx->fill([
                    'account_id' => $primaryAccount->id,
                    'category_id' => $validated['transactionCategoryId'],
                    'payment_method_id' => $this->paymentMethodId,
                    'split_group_id' => $splitGroupId,
                    'type' => TransactionType::Expense,
                    'description' => $validated['transactionDescription'] ?: null,
                    'currency' => $validated['transactionCurrency'],
                    'amount' => $validated['splitPrimaryAmount'],
                    'account_amount' => $this->transactionCurrency !== $primaryAccount->currency
                        ? $validated['splitPrimaryAccountAmount']
                        : null,
                    'transaction_date' => $validated['transactionDate'],
                ]);
                $primaryTx->save();
            }

            if ($secondaryAccount && (float) $validated['splitSecondaryAmount'] > 0) {
                $secondaryTx = new Transaction;
                $secondaryTx->fill([
                    'account_id' => $secondaryAccount->id,
                    'category_id' => $validated['transactionCategoryId'],
                    'payment_method_id' => $this->paymentMethodId,
                    'split_group_id' => $splitGroupId,
                    'type' => TransactionType::Expense,
                    'description' => $validated['transactionDescription'] ?: null,
                    'currency' => $validated['transactionCurrency'],
                    'amount' => $validated['splitSecondaryAmount'],
                    'account_amount' => $this->transactionCurrency !== $secondaryAccount->currency
                        ? $validated['splitSecondaryAccountAmount']
                        : null,
                    'transaction_date' => $validated['transactionDate'],
                ]);
                $secondaryTx->save();
            }
        });

        $this->resetTransactionForm();
        unset($this->transactions, $this->accounts);

        Flux::toast(variant: 'success', text: __('Split transaction saved.'));
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
            if ($this->editingSplitGroupId) {
                Transaction::query()->where('split_group_id', $this->editingSplitGroupId)->delete();
            } elseif ($this->editingTransactionId) {
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
        $this->transactionDate = $transaction->transaction_date->toDateString();

        if ($transaction->split_group_id) {
            $this->editingSplitGroupId = $transaction->split_group_id;
            $splits = Transaction::query()->where('split_group_id', $transaction->split_group_id)->get();

            $this->paymentMethodId = $transaction->payment_method_id;
            $this->paymentSourceMode = $transaction->payment_method_id ? 'payment_method' : 'split';

            $totalAmount = (string) $splits->sum('amount');
            $this->transactionAmount = $totalAmount;

            $primaryTx = $transaction->paymentMethod
                ? ($splits->firstWhere('account_id', $transaction->paymentMethod->primary_account_id) ?? $splits->first())
                : $splits->first();

            $secondaryTx = $splits->first(fn ($t) => $t->id !== $primaryTx?->id);

            $this->transactionAccountId = $primaryTx?->account_id;
            $this->splitPrimaryAmount = (string) ($primaryTx?->amount ?? '0');
            $this->splitPrimaryAccountAmount = (string) ($primaryTx?->account_amount ?? '');

            if ($secondaryTx) {
                $this->splitSecondaryAccountId = $secondaryTx->account_id;
                $this->splitSecondaryAmount = (string) $secondaryTx->amount;
                $this->splitSecondaryAccountAmount = (string) ($secondaryTx->account_amount ?? '');
            } else {
                $this->splitSecondaryAccountId = $transaction->paymentMethod?->secondary_account_id;
                $this->splitSecondaryAmount = '0';
                $this->splitSecondaryAccountAmount = '';
            }
        } else {
            $this->editingSplitGroupId = null;
            $this->paymentSourceMode = 'account';
            $this->paymentMethodId = null;
            $this->transactionAmount = (string) $transaction->amount;
            $this->transactionAccountAmount = (string) ($transaction->account_amount ?? '');

            if ($transaction->type === TransactionType::Transfer) {
                $destination = $transaction->relatedTransaction;

                $this->transferDestinationAccountId = $destination?->account_id;
                $this->transferDestinationCurrency = $destination?->currency ?? '';
                $this->transferDestinationAmount = (string) ($destination?->amount ?? '');
                $this->transferDestinationAccountAmount = (string) ($destination?->account_amount ?? '');
            }
        }
    }

    public function deleteTransaction(int $transactionId): void
    {
        $transaction = Transaction::query()->findOrFail($transactionId);

        DB::transaction(function () use ($transaction): void {
            if ($transaction->split_group_id) {
                Transaction::query()->where('split_group_id', $transaction->split_group_id)->delete();
            } elseif ($transaction->type === TransactionType::Transfer) {
                $transaction->relatedTransaction?->delete();
                $transaction->delete();
            } else {
                $transaction->delete();
            }
        });

        unset($this->transactions);

        Flux::toast(variant: 'success', text: __('Transaction deleted.'));
    }

    public function resetTransactionForm(): void
    {
        $account = $this->accounts->first();

        $this->reset(
            'editingTransactionId',
            'editingSplitGroupId',
            'paymentMethodId',
            'transactionDescription',
            'transactionCategoryId',
            'transactionAmount',
            'transactionAccountAmount',
            'splitPrimaryAmount',
            'splitSecondaryAmount',
            'splitPrimaryAccountAmount',
            'splitSecondaryAccountAmount',
            'transferDestinationAccountId',
            'transferDestinationCurrency',
            'transferDestinationAmount',
            'transferDestinationAccountAmount',
        );
        $this->transactionType = TransactionType::Expense->value;
        $this->paymentSourceMode = 'account';
        $this->transactionDate = now()->toDateString();
        $this->transactionAccountId = $account?->id;
        $this->transactionCurrency = $account?->currency ?? '';

        $destination = $this->accounts->first(fn (Account $candidate): bool => $candidate->id !== $account?->id);
        $this->transferDestinationAccountId = $destination?->id;
        $this->transferDestinationCurrency = $destination?->currency ?? '';
        $this->splitSecondaryAccountId = $destination?->id;

        $this->resetValidation();
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10">
    <header class="flex flex-col gap-2 border-b border-zinc-200 pb-6 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Transactions') }}</flux:heading>
        <flux:subheading>{{ __('Record income, expenses, transfers, and composite split payments.') }}</flux:subheading>
    </header>

    <section class="space-y-5">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ $editingTransactionId ? __('Edit transaction') : __('New transaction') }}</flux:heading>
            @if ($editingTransactionId)
                <flux:button variant="ghost" size="sm" wire:click="resetTransactionForm">{{ __('Cancel') }}</flux:button>
            @endif
        </div>

        <form wire:submit="saveTransaction" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:select wire:model.live="transactionType" :label="__('Type')">
                    <flux:select.option value="expense">{{ __('Expense') }}</flux:select.option>
                    <flux:select.option value="income">{{ __('Income') }}</flux:select.option>
                    <flux:select.option value="transfer">{{ __('Transfer') }}</flux:select.option>
                </flux:select>

                @if ($transactionType === 'expense')
                    <flux:select wire:model.live="paymentSourceMode" :label="__('Payment source')">
                        <flux:select.option value="account">{{ __('Single Account') }}</flux:select.option>
                        @if ($this->paymentMethods->isNotEmpty())
                            <flux:select.option value="payment_method">{{ __('Payment Method') }}</flux:select.option>
                        @endif
                        <flux:select.option value="split">{{ __('Custom Split') }}</flux:select.option>
                    </flux:select>
                @endif

                @if ($transactionType === 'expense' && $paymentSourceMode === 'payment_method')
                    <flux:select wire:model.live="paymentMethodId" :label="__('Payment Method')" placeholder="{{ __('Select payment method') }}">
                        @foreach ($this->paymentMethods as $method)
                            <flux:select.option value="{{ $method->id }}">{{ $method->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @elseif ($transactionType !== 'transfer')
                    <flux:select wire:model.live="transactionAccountId" :label="$paymentSourceMode === 'split' ? __('Primary Account') : __('Account')" placeholder="{{ __('Choose an account') }}">
                        @foreach ($this->accounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model.live.debounce.300ms="transactionAmount" :label="__('Total Amount')" type="number" step="0.0001" min="0" />
                <flux:input wire:model.live="transactionCurrency" :label="__('Currency')" maxlength="3" class="uppercase" />
            </div>

            {{-- Split breakdown for Payment Method or Custom Split --}}
            @if ($transactionType === 'expense' && in_array($paymentSourceMode, ['payment_method', 'split'], true))
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Split Allocation Across Accounts') }}</flux:heading>
                        @if ($paymentSourceMode === 'payment_method' && $paymentMethodId)
                            <flux:button size="xs" variant="ghost" wire:click="recalculateSplit">{{ __('Recalculate balance split') }}</flux:button>
                        @endif
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:select wire:model.live="transactionAccountId" :label="__('Primary account')" :disabled="$paymentSourceMode === 'payment_method'">
                                @foreach ($this->accounts as $account)
                                    <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="splitPrimaryAmount" :label="__('Amount from primary account')" type="number" step="0.0001" min="0" />

                            @if ($transactionAccountId && $transactionCurrency !== ($this->accounts->firstWhere('id', $transactionAccountId)?->currency ?? ''))
                                <flux:input wire:model="splitPrimaryAccountAmount" :label="__('Primary account amount')" type="number" step="0.0001" min="0" />
                            @endif
                        </div>

                        <div class="space-y-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:select wire:model.live="splitSecondaryAccountId" :label="__('Secondary / Backup account')" :disabled="$paymentSourceMode === 'payment_method'">
                                @foreach ($this->accounts as $account)
                                    @if ($account->id !== $transactionAccountId)
                                        <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                                    @endif
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="splitSecondaryAmount" :label="__('Amount from secondary account')" type="number" step="0.0001" min="0" />

                            @if ($splitSecondaryAccountId && $transactionCurrency !== ($this->accounts->firstWhere('id', $splitSecondaryAccountId)?->currency ?? ''))
                                <flux:input wire:model="splitSecondaryAccountAmount" :label="__('Secondary account amount')" type="number" step="0.0001" min="0" />
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @if ($transactionType !== TransactionType::Transfer->value)
                    <flux:select wire:model="transactionCategoryId" :label="__('Category')" placeholder="{{ __('No category') }}">
                        <flux:select.option value="">{{ __('No category') }}</flux:select.option>
                        @foreach ($this->categories as $category)
                            <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($transactionType === 'expense' && $paymentSourceMode === 'account' && $transactionAccountId && $transactionCurrency !== ($this->accounts->firstWhere('id', $transactionAccountId)?->currency ?? ''))
                    <flux:input wire:model="transactionAccountAmount" :label="__('Amount on account')" type="number" step="0.0001" min="0" />
                @elseif ($transactionType === 'income' && $transactionAccountId && $transactionCurrency !== ($this->accounts->firstWhere('id', $transactionAccountId)?->currency ?? ''))
                    <flux:input wire:model="transactionAccountAmount" :label="__('Amount on account')" type="number" step="0.0001" min="0" />
                @endif

                @if ($transactionType === TransactionType::Transfer->value)
                    <flux:select wire:model.live="transactionAccountId" :label="__('Source account')" placeholder="{{ __('Choose source account') }}">
                        @foreach ($this->accounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="transferDestinationAccountId" :label="__('Destination account')" placeholder="{{ __('Choose destination account') }}">
                        @foreach ($this->accounts as $account)
                            @if ($account->id !== $transactionAccountId)
                                <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                            @endif
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="transferDestinationAmount" :label="__('Destination amount')" type="number" step="0.0001" min="0" />
                    <flux:input wire:model="transferDestinationCurrency" :label="__('Destination currency')" maxlength="3" class="uppercase" />

                    @if ($transactionAccountId && $transactionCurrency !== ($this->accounts->firstWhere('id', $transactionAccountId)?->currency ?? ''))
                        <flux:input wire:model="transactionAccountAmount" :label="__('Source amount on account')" type="number" step="0.0001" min="0" />
                    @endif

                    @if ($transferDestinationAccountId && $transferDestinationCurrency !== ($this->accounts->firstWhere('id', $transferDestinationAccountId)?->currency ?? ''))
                        <flux:input wire:model="transferDestinationAccountAmount" :label="__('Destination amount on account')" type="number" step="0.0001" min="0" />
                    @endif
                @endif

                <flux:input wire:model="transactionDescription" :label="__('Description')" placeholder="{{ __('Optional') }}" />
                <flux:input wire:model="transactionDate" :label="__('Date')" type="date" />

                <div class="flex items-end">
                    <flux:button type="submit" variant="primary">{{ $editingTransactionId ? __('Save changes') : __('Add transaction') }}</flux:button>
                </div>
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
                        <th class="py-3 pe-4 font-medium">{{ __('Account / Method') }}</th>
                        <th class="py-3 pe-4 text-end font-medium">{{ __('Amount') }}</th>
                        <th class="py-3 text-end font-medium">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->transactions as $transaction)
                        <tr wire:key="transaction-{{ $transaction->id }}">
                            <td class="py-3 pe-4">{{ $transaction->transaction_date->format('Y-m-d') }}</td>
                            <td class="py-3 pe-4">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $transaction->description ?? __('Untitled') }}</div>
                                @if ($transaction->split_group_id)
                                    <div class="text-xs text-zinc-500">
                                        {{ __('Split transaction') }}
                                        @if ($transaction->paymentMethod)
                                            ({{ $transaction->paymentMethod->name }})
                                        @endif
                                    </div>
                                @endif
                            </td>
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
