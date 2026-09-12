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
            ->orderBy('name')
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
        <flux:subheading>{{ __('Configure composite payment methods like PayPal that draw from an internal balance and a backup account.') }}</flux:subheading>
    </header>

    <section class="space-y-5">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ $editingPaymentMethodId ? __('Edit payment method') : __('New payment method') }}</flux:heading>
            @if ($editingPaymentMethodId)
                <flux:button variant="ghost" size="sm" wire:click="resetForm">{{ __('Cancel') }}</flux:button>
            @endif
        </div>

        <form wire:submit="savePaymentMethod" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('e.g. PayPal (Balance + Visa)') }}" />

            <flux:select wire:model.live="type" :label="__('Type')">
                <flux:select.option value="composite">{{ __('Composite / Split') }}</flux:select.option>
                <flux:select.option value="single">{{ __('Single account') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="primaryAccountId" :label="__('Primary account')" placeholder="{{ __('Select primary account') }}">
                @foreach ($this->accounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                @endforeach
            </flux:select>

            @if ($type === \App\PaymentMethodType::Composite->value)
                <flux:select wire:model="secondaryAccountId" :label="__('Backup / Secondary account')" placeholder="{{ __('Select backup account') }}">
                    @foreach ($this->accounts as $account)
                        @if ($account->id !== $primaryAccountId)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</flux:select.option>
                        @endif
                    @endforeach
                </flux:select>

                <div class="flex items-center pt-6">
                    <flux:checkbox wire:model="autoSplitBalance" :label="__('Auto-split from primary balance first')" />
                </div>
            @endif

            <flux:input wire:model="description" :label="__('Description')" placeholder="{{ __('Optional notes') }}" />

            <div class="flex items-end">
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
                        <th class="py-3 pe-4 font-medium">{{ __('Auto-split') }}</th>
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
                                    {{ $method->type === \App\PaymentMethodType::Composite ? __('Composite') : __('Single') }}
                                </flux:badge>
                            </td>
                            <td class="py-3 pe-4">
                                {{ $method->primaryAccount?->name ?? __('None') }} ({{ $method->primaryAccount?->currency ?? '—' }})
                            </td>
                            <td class="py-3 pe-4">
                                @if ($method->secondaryAccount)
                                    {{ $method->secondaryAccount->name }} ({{ $method->secondaryAccount->currency }})
                                @else
                                    <span class="text-zinc-500">{{ __('None') }}</span>
                                @endif
                            </td>
                            <td class="py-3 pe-4">
                                @if ($method->type === \App\PaymentMethodType::Composite)
                                    <span class="{{ $method->auto_split_balance ? 'text-green-600 dark:text-green-400' : 'text-zinc-500' }}">
                                        {{ $method->auto_split_balance ? __('Enabled') : __('Manual') }}
                                    </span>
                                @else
                                    <span class="text-zinc-500">—</span>
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
                            <td colspan="6" class="py-6 text-center text-zinc-500">{{ __('No payment methods created yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
