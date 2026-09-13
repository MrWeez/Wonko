<?php

use App\Models\Account;
use App\Models\PaymentMethod;
use App\PaymentMethodType;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payment Methods')] class extends Component {
    public string $name = '';

    public string $type = PaymentMethodType::Composite->value;

    public ?int $primaryAccountId = null;

    public ?int $secondaryAccountId = null;

    public bool $autoSplitBalance = true;

    public string $description = '';

    public ?int $editingPaymentMethodId = null;

    public function mount(): void
    {
        $this->primaryAccountId = $this->accounts->first()?->id;
        $this->secondaryAccountId = $this->accounts->skip(1)->first()?->id;
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()
            ->defaultOrder()
            ->get();
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

    public function savePaymentMethod(): void
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('payment_methods', 'name')
                    ->where(fn ($query) => $query->where('user_id', Auth::id()))
                    ->ignore($this->editingPaymentMethodId),
            ],
            'type' => ['required', Rule::in([PaymentMethodType::Composite->value, PaymentMethodType::Single->value])],
            'primaryAccountId' => [
                'required',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('user_id', Auth::id())),
            ],
            'autoSplitBalance' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->type === PaymentMethodType::Composite->value) {
            $rules['secondaryAccountId'] = [
                'required',
                'different:primaryAccountId',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('user_id', Auth::id())),
            ];
        } else {
            $rules['secondaryAccountId'] = ['nullable'];
        }

        $validated = $this->validate($rules);

        $paymentMethod = $this->editingPaymentMethodId
            ? PaymentMethod::query()->findOrFail($this->editingPaymentMethodId)
            : new PaymentMethod;

        $paymentMethod->fill([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'primary_account_id' => $validated['primaryAccountId'],
            'secondary_account_id' => $validated['type'] === PaymentMethodType::Composite->value ? $validated['secondaryAccountId'] : null,
            'auto_split_balance' => $validated['type'] === PaymentMethodType::Composite->value ? ($validated['autoSplitBalance'] ?? true) : false,
            'description' => $validated['description'] ?: null,
        ]);
        $paymentMethod->save();

        $this->resetForm();
        unset($this->paymentMethods);

        Flux::toast(variant: 'success', text: __('Payment method saved.'));
    }

    public function editPaymentMethod(int $paymentMethodId): void
    {
        $method = PaymentMethod::query()->findOrFail($paymentMethodId);

        $this->editingPaymentMethodId = $method->id;
        $this->name = $method->name;
        $this->type = $method->type->value;
        $this->primaryAccountId = $method->primary_account_id;
        $this->secondaryAccountId = $method->secondary_account_id;
        $this->autoSplitBalance = $method->auto_split_balance;
        $this->description = $method->description ?? '';
    }

    public function deletePaymentMethod(int $paymentMethodId): void
    {
        PaymentMethod::query()->findOrFail($paymentMethodId)->delete();

        unset($this->paymentMethods);

        Flux::toast(variant: 'success', text: __('Payment method deleted.'));
    }

    public function resetForm(): void
    {
        $this->reset('editingPaymentMethodId', 'name', 'description');
        $this->type = PaymentMethodType::Composite->value;
        $this->autoSplitBalance = true;
        $this->primaryAccountId = $this->accounts->first()?->id;
        $this->secondaryAccountId = $this->accounts->skip(1)->first()?->id;
        $this->resetValidation();
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10">
    <header class="flex flex-col gap-2 border-b border-zinc-200 pb-6 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Payment Methods') }}</flux:heading>
        <flux:subheading>{{ __('Composite payment methods connect multiple accounts so you can automatically split transactions.') }}</flux:subheading>
    </header>

    {{-- Explanatory card for clarity --}}
    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-start gap-3">
            <div class="mt-0.5 rounded-md bg-zinc-900 p-1.5 text-white dark:bg-white dark:text-zinc-900">
                <flux:icon icon="light-bulb" class="size-4" />
            </div>
            <div class="space-y-1">
                <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('How Payment Methods Work in Wonko') }}</div>
                <flux:text size="sm">
                    {{ __('Use Payment Methods when you pay via services like PayPal, Curve, or split wallets that combine multiple accounts. For example, a **PayPal** method draws from your *PayPal Balance* account first, and automatically debits your *Credit Card* account for the remainder. Both underlying account balances stay 100% accurate.') }}
                </flux:text>
            </div>
        </div>
    </div>

    @if ($this->accounts->count() < 2)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200">
            <div class="flex items-center gap-2 font-medium">
                <flux:icon icon="exclamation-circle" class="size-4" />
                {{ __('Multiple accounts recommended') }}
            </div>
            <div class="mt-1">
                {{ __('To create a composite split payment method (e.g. PayPal Balance + Linked Card), you need at least two accounts in Wonko.') }}
                <a href="{{ route('accounts.index') }}" class="font-semibold underline" wire:navigate>{{ __('Add another account here &rarr;') }}</a>
            </div>
        </div>
    @endif

    <section class="space-y-5">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ $editingPaymentMethodId ? __('Edit payment method') : __('New payment method') }}</flux:heading>
            @if ($editingPaymentMethodId)
                <flux:button variant="ghost" size="sm" wire:click="resetForm">{{ __('Cancel') }}</flux:button>
            @endif
        </div>

        <form wire:submit="savePaymentMethod" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <flux:input wire:model="name" :label="__('Method Name')" placeholder="{{ __('e.g. PayPal (Balance + Visa)') }}" />
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('A recognizable name shown when logging expenses.') }}</flux:text>
                </div>

                <div>
                    <flux:select wire:model.live="type" :label="__('Method Type')">
                        <flux:select.option value="composite">{{ __('Composite / Split (2 accounts)') }}</flux:select.option>
                        <flux:select.option value="single">{{ __('Single Account Alias') }}</flux:select.option>
                    </flux:select>
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Composite splits charges across primary & backup accounts.') }}</flux:text>
                </div>

                <div>
                    <flux:select wire:model.live="primaryAccountId" :label="__('Primary Account (Drawn first)')" placeholder="{{ __('Select primary account') }}">
                        @foreach ($this->accounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }} · {{ $account->balance() }})</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Account checked first (e.g. PayPal balance).') }}</flux:text>
                </div>

                @if ($type === \App\PaymentMethodType::Composite->value)
                    <div>
                        <flux:select wire:model.live="secondaryAccountId" :label="__('Backup / Secondary Account')" placeholder="{{ __('Select backup account') }}">
                            @foreach ($this->accounts as $account)
                                @if ($account->id !== $primaryAccountId)
                                    <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }} · {{ $account->balance() }})</flux:select.option>
                                @endif
                            @endforeach
                        </flux:select>
                        <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Covers whatever the primary account cannot.') }}</flux:text>
                    </div>

                    <div class="flex flex-col justify-center pt-2">
                        <flux:checkbox wire:model="autoSplitBalance" :label="__('Auto-split from primary balance first')" />
                        <flux:text size="xs" class="ms-6 text-zinc-500">{{ __('Automatically calculates split based on live balance.') }}</flux:text>
                    </div>
                @endif

                <div>
                    <flux:input wire:model="description" :label="__('Description / Notes')" placeholder="{{ __('Optional notes') }}" />
                    <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Any extra details for your reference.') }}</flux:text>
                </div>
            </div>

            {{-- Live visual split rule explanation --}}
            @if ($type === \App\PaymentMethodType::Composite->value && $primaryAccountId && $secondaryAccountId)
                @php
                    $pAccount = $this->accounts->firstWhere('id', $primaryAccountId);
                    $sAccount = $this->accounts->firstWhere('id', $secondaryAccountId);
                @endphp
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Active Split Rule Preview:') }}</span>
                    {{ __('When paying with this method, Wonko first uses up to :balance :currency from **:primary**. Any remaining amount is debited from **:secondary**.', [
                        'balance' => $pAccount?->balance() ?? '0.00',
                        'currency' => $pAccount?->currency ?? '',
                        'primary' => $pAccount?->name ?? 'Primary',
                        'secondary' => $sAccount?->name ?? 'Secondary',
                    ]) }}
                </div>
            @endif

            <div class="flex justify-end pt-2">
                <flux:button type="submit" variant="primary">{{ $editingPaymentMethodId ? __('Save changes') : __('Add payment method') }}</flux:button>
            </div>
        </form>
    </section>

    <section class="space-y-5">
        <flux:heading size="lg">{{ __('Configured payment methods') }}</flux:heading>

        <div class="overflow-x-auto border-y border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-start text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="py-3 pe-4 font-medium">{{ __('Name') }}</th>
                        <th class="py-3 pe-4 font-medium">{{ __('Type') }}</th>
                        <th class="py-3 pe-4 font-medium">{{ __('Primary Account') }}</th>
                        <th class="py-3 pe-4 font-medium">{{ __('Backup Account') }}</th>
                        <th class="py-3 pe-4 font-medium">{{ __('Split Logic') }}</th>
                        <th class="py-3 text-end font-medium">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->paymentMethods as $method)
                        <tr wire:key="payment-method-{{ $method->id }}">
                            <td class="py-3 pe-4 font-medium text-zinc-900 dark:text-zinc-100">
                                <div>{{ $method->name }}</div>
                                @if ($method->description)
                                    <div class="text-xs text-zinc-500">{{ $method->description }}</div>
                                @endif
                            </td>
                            <td class="py-3 pe-4">
                                <flux:badge size="sm" :variant="$method->type === \App\PaymentMethodType::Composite ? 'solid' : 'subtle'">
                                    {{ $method->type === \App\PaymentMethodType::Composite ? __('Composite Split') : __('Single Account') }}
                                </flux:badge>
                            </td>
                            <td class="py-3 pe-4">
                                <span class="font-medium">{{ $method->primaryAccount?->name ?? __('None') }}</span>
                                <span class="text-xs text-zinc-500">({{ $method->primaryAccount?->currency ?? '—' }})</span>
                            </td>
                            <td class="py-3 pe-4">
                                @if ($method->secondaryAccount)
                                    <span class="font-medium">{{ $method->secondaryAccount->name }}</span>
                                    <span class="text-xs text-zinc-500">({{ $method->secondaryAccount->currency }})</span>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 pe-4">
                                @if ($method->type === \App\PaymentMethodType::Composite)
                                    <span class="{{ $method->auto_split_balance ? 'font-medium text-green-600 dark:text-green-400' : 'text-zinc-500' }}">
                                        {{ $method->auto_split_balance ? __('Auto balance split') : __('Manual split') }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 text-end">
                                <div class="flex justify-end gap-1">
                                    <flux:button variant="ghost" size="sm" icon="pencil" aria-label="{{ __('Edit payment method') }}" wire:click="editPaymentMethod({{ $method->id }})" />
                                    <flux:button variant="ghost" size="sm" icon="trash" aria-label="{{ __('Delete payment method') }}" wire:click="deletePaymentMethod({{ $method->id }})" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500">
                                <div>{{ __('No payment methods created yet.') }}</div>
                                <div class="mt-1 text-xs text-zinc-400">{{ __('Create one above to connect composite accounts like PayPal.') }}</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
