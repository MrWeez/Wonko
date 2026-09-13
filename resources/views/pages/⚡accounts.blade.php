<?php

use App\AccountType;
use App\Models\Account;
use App\Models\Bank;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Accounts')] class extends Component {
    public string $bankName = '';

    public ?int $editingBankId = null;

    public string $accountName = '';

    public string $accountCurrency = '';

    public string $accountType = AccountType::Checking->value;

    public ?int $accountBankId = null;

    public ?int $editingAccountId = null;

    /**
     * @return Collection<int, Bank>
     */
    #[Computed]
    public function banks(): Collection
    {
        return Bank::query()
            ->withCount('accounts')
            ->orderBy('name')
            ->get();
    }

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

    public function saveBank(): void
    {
        $validated = $this->validate([
            'bankName' => [
                'required',
                'string',
                'max:120',
                Rule::unique('banks', 'name')
                    ->where(fn ($query) => $query->where('user_id', Auth::id()))
                    ->ignore($this->editingBankId),
            ],
        ]);

        $bank = $this->editingBankId
            ? Bank::query()->findOrFail($this->editingBankId)
            : new Bank;

        $bank->name = $validated['bankName'];
        $bank->save();

        $this->resetBankForm();
        unset($this->banks);

        Flux::toast(variant: 'success', text: __('Bank saved.'));
    }

    public function editBank(int $bankId): void
    {
        $bank = Bank::query()->findOrFail($bankId);

        $this->editingBankId = $bank->id;
        $this->bankName = $bank->name;
    }

    public function deleteBank(int $bankId): void
    {
        $bank = Bank::query()->findOrFail($bankId);
        $bank->accounts()->update(['bank_id' => null]);
        $bank->delete();

        unset($this->banks, $this->accounts);

        Flux::toast(variant: 'success', text: __('Bank deleted. Accounts were kept.'));
    }

    public function saveAccount(): void
    {
        $validated = $this->validate([
            'accountName' => [
                'required',
                'string',
                'max:120',
                Rule::unique('accounts', 'name')
                    ->where(fn ($query) => $query->where('user_id', Auth::id()))
                    ->ignore($this->editingAccountId),
            ],
            'accountCurrency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'accountType' => ['required', Rule::enum(AccountType::class)],
            'accountBankId' => [
                'nullable',
                Rule::exists('banks', 'id')->where(fn ($query) => $query->where('user_id', Auth::id())),
            ],
        ]);

        $account = $this->editingAccountId
            ? Account::query()->findOrFail($this->editingAccountId)
            : new Account;

        $account->fill([
            'name' => $validated['accountName'],
            'currency' => strtoupper($validated['accountCurrency']),
            'type' => $validated['accountType'],
            'bank_id' => $validated['accountBankId'] ?: null,
        ]);

        if ($this->editingAccountId === null && ! Account::query()->where('is_primary', true)->exists()) {
            $account->is_primary = true;
        }

        $account->save();

        $this->resetAccountForm();
        unset($this->accounts);

        Flux::toast(variant: 'success', text: __('Account saved.'));
    }

    public function editAccount(int $accountId): void
    {
        $account = Account::query()->findOrFail($accountId);

        $this->editingAccountId = $account->id;
        $this->accountName = $account->name;
        $this->accountCurrency = $account->currency;
        $this->accountType = $account->type->value;
        $this->accountBankId = $account->bank_id;
    }

    public function deleteAccount(int $accountId): void
    {
        Account::query()->findOrFail($accountId)->delete();

        unset($this->accounts);

        Flux::toast(variant: 'success', text: __('Account deleted.'));
    }

    public function makePrimary(int $accountId): void
    {
        Account::query()->findOrFail($accountId)->markAsPrimary();

        unset($this->accounts);

        Flux::toast(variant: 'success', text: __('Primary account updated.'));
    }

    public function resetBankForm(): void
    {
        $this->reset('bankName', 'editingBankId');
        $this->resetValidation();
    }

    public function resetAccountForm(): void
    {
        $this->reset('accountName', 'accountCurrency', 'accountBankId', 'editingAccountId');
        $this->accountType = AccountType::Checking->value;
        $this->resetValidation();
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10">
    <header class="flex flex-col gap-2 border-b border-zinc-200 pb-6 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Accounts & Banks') }}</flux:heading>
        <flux:subheading>{{ __('Manage your accounts, cards, cash wallets, and bank groupings.') }}</flux:subheading>
    </header>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        {{-- Banks Column --}}
        <section class="space-y-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Banks') }}</flux:heading>
                    <flux:text size="xs" class="text-zinc-500">{{ __('Optional groupings (e.g. Chase, N26, Revolut).') }}</flux:text>
                </div>
                @if ($editingBankId)
                    <flux:button variant="ghost" size="sm" wire:click="resetBankForm">{{ __('Cancel') }}</flux:button>
                @endif
            </div>

            <form wire:submit="saveBank" class="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:input wire:model="bankName" :label="$editingBankId ? __('Edit bank') : __('New bank')" placeholder="{{ __('e.g. Deutsche Bank') }}" />
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" size="sm">{{ $editingBankId ? __('Save') : __('Add bank') }}</flux:button>
                </div>
            </form>

            <div class="divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->banks as $bank)
                    <div wire:key="bank-{{ $bank->id }}" class="flex items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <flux:text class="truncate font-medium">{{ $bank->name }}</flux:text>
                            <flux:text size="xs" class="text-zinc-500">{{ trans_choice(':count account|:count accounts', $bank->accounts_count, ['count' => $bank->accounts_count]) }}</flux:text>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit bank') }}" wire:click="editBank({{ $bank->id }})" />
                            <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete bank') }}" wire:click="deleteBank({{ $bank->id }})" />
                        </div>
                    </div>
                @empty
                    <div class="py-4 text-xs text-zinc-400">{{ __('No banks created. You can still create standalone accounts.') }}</div>
                @endforelse
            </div>
        </section>

        {{-- Accounts Column --}}
        <section class="space-y-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Accounts') }}</flux:heading>
                    <flux:text size="xs" class="text-zinc-500">{{ __('Your checking, savings, credit cards, and cash wallets.') }}</flux:text>
                </div>
                @if ($editingAccountId)
                    <flux:button variant="ghost" size="sm" wire:click="resetAccountForm">{{ __('Cancel') }}</flux:button>
                @endif
            </div>

            <form wire:submit="saveAccount" class="grid gap-4 rounded-xl border border-zinc-200 bg-white p-5 sm:grid-cols-2 dark:border-zinc-700 dark:bg-zinc-900">
                <div>
                    <flux:input wire:model="accountName" :label="$editingAccountId ? __('Edit account name') : __('Account name')" placeholder="{{ __('e.g. Daily Checking, Visa Card') }}" />
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Descriptive name for this account.') }}</flux:text>
                </div>

                <div>
                    <flux:input wire:model="accountCurrency" :label="__('Currency (ISO code)')" placeholder="EUR" maxlength="3" class="uppercase" />
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('3-letter code (EUR, USD, GBP).') }}</flux:text>
                </div>

                <div>
                    <flux:select wire:model="accountType" :label="__('Account Type')">
                        @foreach (AccountType::cases() as $type)
                            <flux:select.option value="{{ $type->value }}">{{ __(ucfirst($type->value)) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Checking, Savings, Card, or Cash.') }}</flux:text>
                </div>

                <div>
                    <flux:select wire:model="accountBankId" :label="__('Bank (Optional)')" placeholder="{{ __('Standalone / No bank') }}">
                        <flux:select.option value="">{{ __('Standalone / No bank') }}</flux:select.option>
                        @foreach ($this->banks as $bank)
                            <flux:select.option value="{{ $bank->id }}">{{ $bank->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Groups related accounts together.') }}</flux:text>
                </div>

                <div class="flex justify-end sm:col-span-2">
                    <flux:button type="submit" variant="primary">{{ $editingAccountId ? __('Save changes') : __('Add account') }}</flux:button>
                </div>
            </form>

            <div class="overflow-x-auto border-y border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-start text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="py-3 pe-4 font-medium">{{ __('Account') }}</th>
                            <th class="py-3 pe-4 font-medium">{{ __('Type & Bank') }}</th>
                            <th class="py-3 pe-4 text-end font-medium">{{ __('Live Balance') }}</th>
                            <th class="py-3 text-end font-medium">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->accounts as $account)
                            <tr wire:key="account-{{ $account->id }}">
                                <td class="py-3 pe-4">
                                    <div class="flex items-center gap-2 font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $account->name }}
                                        @if ($account->is_primary)
                                            <flux:badge size="sm" color="zinc">{{ __('Primary') }}</flux:badge>
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-500">{{ $account->currency }}</div>
                                </td>
                                <td class="py-3 pe-4">
                                    <div class="text-zinc-800 dark:text-zinc-200">{{ __(ucfirst($account->type->value)) }}</div>
                                    <div class="text-xs text-zinc-500">{{ $account->bank?->name ?? __('No bank') }}</div>
                                </td>
                                <td class="py-3 pe-4 text-end font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ App\Amount::display((string) $account->balance()) }} {{ $account->currency }}
                                </td>
                                <td class="py-3 text-end">
                                    <div class="flex justify-end gap-1">
                                        @unless ($account->is_primary)
                                            <flux:button variant="ghost" size="sm" icon="star" aria-label="{{ __('Set as primary') }}" wire:click="makePrimary({{ $account->id }})" />
                                        @endunless
                                        <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit account') }}" wire:click="editAccount({{ $account->id }})" />
                                        <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete account') }}" wire:click="deleteAccount({{ $account->id }})" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-8 text-center text-zinc-500">
                                    <div>{{ __('No accounts yet.') }}</div>
                                    <div class="mt-1 text-xs text-zinc-400">{{ __('Add your first account using the form above.') }}</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
