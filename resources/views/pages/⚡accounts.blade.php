<?php

use App\AccountType;
use App\Models\Account;
use App\Models\Bank;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Accounts')] class extends Component {
    public string $bankName = '';

    public ?int $editingBankId = null;

    public string $accountName = '';

    public string $accountCurrency = '';

    public string $accountType = '';

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
            ->orderBy('name')
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
            'currency' => $validated['accountCurrency'],
            'type' => $validated['accountType'],
            'bank_id' => $validated['accountBankId'],
        ]);
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

    public function resetBankForm(): void
    {
        $this->reset('bankName', 'editingBankId');
        $this->resetValidation();
    }

    public function resetAccountForm(): void
    {
        $this->reset('accountName', 'accountCurrency', 'accountType', 'accountBankId', 'editingAccountId');
        $this->resetValidation();
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10">
        <header class="flex flex-col gap-2 border-b border-zinc-200 pb-6 dark:border-zinc-700">
            <flux:heading size="xl">{{ __('Accounts') }}</flux:heading>
            <flux:subheading>{{ __('Manage your banks and the accounts they contain.') }}</flux:subheading>
        </header>

        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <section class="space-y-5">
                <div class="flex items-center justify-between gap-4">
                    <flux:heading size="lg">{{ __('Banks') }}</flux:heading>
                    @if ($editingBankId)
                        <flux:button variant="ghost" size="sm" wire:click="resetBankForm">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>

                <form wire:submit="saveBank" class="space-y-4">
                    <flux:input wire:model="bankName" :label="$editingBankId ? __('Edit bank') : __('New bank')" placeholder="{{ __('Bank name') }}" />
                    <flux:button type="submit" variant="primary">{{ $editingBankId ? __('Save changes') : __('Add bank') }}</flux:button>
                </form>

                <div class="divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($this->banks as $bank)
                        <div wire:key="bank-{{ $bank->id }}" class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <flux:text class="truncate font-medium">{{ $bank->name }}</flux:text>
                                <flux:text size="sm">{{ trans_choice(':count account|:count accounts', $bank->accounts_count, ['count' => $bank->accounts_count]) }}</flux:text>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit bank') }}" wire:click="editBank({{ $bank->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete bank') }}" wire:click="deleteBank({{ $bank->id }})" />
                            </div>
                        </div>
                    @empty
                        <flux:text class="py-4">{{ __('No banks yet.') }}</flux:text>
                    @endforelse
                </div>
            </section>

            <section class="space-y-5">
                <div class="flex items-center justify-between gap-4">
                    <flux:heading size="lg">{{ __('Accounts') }}</flux:heading>
                    @if ($editingAccountId)
                        <flux:button variant="ghost" size="sm" wire:click="resetAccountForm">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>

                <form wire:submit="saveAccount" class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="accountName" :label="$editingAccountId ? __('Edit account') : __('New account')" placeholder="{{ __('Account name') }}" />
                    <flux:input wire:model="accountCurrency" :label="__('Currency')" placeholder="EUR" maxlength="3" class="uppercase" />

                    <flux:select wire:model="accountType" :label="__('Type')" placeholder="{{ __('Choose a type') }}">
                        @foreach (AccountType::cases() as $type)
                            <flux:select.option value="{{ $type->value }}">{{ __(ucfirst($type->value)) }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="accountBankId" :label="__('Bank')" placeholder="{{ __('No bank') }}">
                        <flux:select.option value="">{{ __('No bank') }}</flux:select.option>
                        @foreach ($this->banks as $bank)
                            <flux:select.option value="{{ $bank->id }}">{{ $bank->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="sm:col-span-2">
                        <flux:button type="submit" variant="primary">{{ $editingAccountId ? __('Save changes') : __('Add account') }}</flux:button>
                    </div>
                </form>

                <div class="overflow-x-auto border-y border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-start text-sm">
                        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <tr>
                                <th class="py-3 pe-4 font-medium">{{ __('Account') }}</th>
                                <th class="py-3 pe-4 font-medium">{{ __('Bank') }}</th>
                                <th class="py-3 pe-4 font-medium">{{ __('Currency') }}</th>
                                <th class="py-3 text-end font-medium">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @forelse ($this->accounts as $account)
                                <tr wire:key="account-{{ $account->id }}">
                                    <td class="py-3 pe-4 font-medium">{{ $account->name }}</td>
                                    <td class="py-3 pe-4">{{ $account->bank?->name ?? __('Unassigned') }}</td>
                                    <td class="py-3 pe-4">{{ $account->currency }}</td>
                                    <td class="py-3 text-end">
                                        <div class="flex justify-end gap-1">
                                            <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit account') }}" wire:click="editAccount({{ $account->id }})" />
                                            <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete account') }}" wire:click="deleteAccount({{ $account->id }})" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-zinc-500">{{ __('No accounts yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
</section>