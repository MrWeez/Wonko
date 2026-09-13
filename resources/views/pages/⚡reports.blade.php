<?php

use App\Models\Account;
use App\Models\Subscription;
use App\ReportData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reports')] class extends Component {
    public string $period = '30d';

    public string $customFrom = '';

    public string $customTo = '';

    public string $currency = '';

    public function mount(): void
    {
        if ($this->currency === '') {
            $this->currency = Account::query()->where('is_primary', true)->first()?->currency
                ?? Account::query()->first()?->currency
                ?? '';
        }

        $today = now()->toDateString();
        $this->customTo = $today;
        $this->customFrom = now()->subDays(29)->toDateString();
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function currencies(): Collection
    {
        return Account::query()
            ->defaultOrder()
            ->pluck('currency')
            ->concat(Subscription::query()->pluck('currency'))
            ->unique()
            ->values();
    }

    #[Computed]
    public function report(): ReportData
    {
        [$from, $to] = $this->resolveRange();

        return new ReportData(Auth::user(), $from, $to, $this->currency);
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    #[Computed]
    public function range(): array
    {
        [$from, $to] = $this->resolveRange();

        return ['from' => $from, 'to' => $to];
    }

    #[Computed]
    public function periodLabel(): string
    {
        return __(':from — :to', [
            'from' => $this->range['from']->format('M j, Y'),
            'to' => $this->range['to']->format('M j, Y'),
        ]);
    }

    #[Computed]
    public function chartMax(): float
    {
        $max = 1.0;

        foreach ($this->report->timeSeries() as $bucket) {
            $max = max($max, (float) $bucket['income'], (float) $bucket['expense']);
        }

        return $max;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(): array
    {
        $today = CarbonImmutable::today();

        return match ($this->period) {
            '7d' => [$today->subDays(6), $today],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'this_quarter' => [$today->firstOfQuarter(), $today->endOfQuarter()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            default => [$this->parseDate($this->customFrom, $today->subDays(29)), $this->parseDate($this->customTo, $today)],
        };
    }

    private function parseDate(string $date, CarbonImmutable $fallback): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            ? $fallback
            : CarbonImmutable::parse($date);
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10">
    <header class="flex flex-col gap-1 border-b border-zinc-200 pb-6 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Reports') }}</flux:heading>
        <flux:subheading>{{ __('Where your money goes, per currency. Amounts are never mixed across currencies.') }}</flux:subheading>
    </header>

    @if ($this->currencies->isEmpty())
        <x-placeholder-pattern />
        <flux:heading level="2">{{ __('No data yet') }}</flux:heading>
        <flux:text>{{ __('Create an account and log a transaction to see reports.') }}</flux:text>
    @else
        {{-- Controls --}}
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-wrap items-end gap-2">
                    @foreach (['7d' => __('7 days'), '30d' => __('30 days'), 'this_month' => __('This month'), 'this_quarter' => __('This quarter'), 'this_year' => __('This year'), 'custom' => __('Custom')] as $value => $label)
                        <flux:button :variant="$this->period === $value ? 'primary' : 'ghost'" size="sm" wire:click="$set('period', '{{ $value }}')">
                            {{ $label }}
                        </flux:button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-end gap-4">
                    @if ($this->period === 'custom')
                        <flux:input wire:model.live="customFrom" type="date" :label="__('From')" />
                        <flux:input wire:model.live="customTo" type="date" :label="__('To')" />
                    @endif

                    <flux:select wire:model.live="currency" :label="__('Currency')" class="w-40">
                        @foreach ($this->currencies as $currency)
                            <flux:select.option :value="$currency">{{ $currency }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <flux:text size="xs" class="text-zinc-500">{{ $this->periodLabel }}</flux:text>
        </div>

        @php($totals = $this->report->totals())

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text size="xs" class="text-zinc-500">{{ __('Income') }} <span class="text-zinc-400">· {{ __('incl. transfers') }}</span></flux:text>
                <flux:heading size="lg" class="mt-1">+{{ number_format((float) $totals['income'], 2) }} {{ $this->currency }}</flux:heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text size="xs" class="text-zinc-500">{{ __('Expense') }} <span class="text-zinc-400">· {{ __('incl. transfers') }}</span></flux:text>
                <flux:heading size="lg" class="mt-1">−{{ number_format((float) $totals['expense'], 2) }} {{ $this->currency }}</flux:heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text size="xs" class="text-zinc-500">{{ __('Net') }}</flux:text>
                <flux:heading size="lg" class="mt-1">
                    {{ (float) $totals['net'] >= 0 ? '+' : '−' }}{{ number_format(abs((float) $totals['net']), 2) }} {{ $this->currency }}
                </flux:heading>
            </div>
        </div>

        {{-- Time series --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="sm">{{ __('Trend') }}</flux:heading>
            <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Daily/weekly/monthly income vs expense.') }}</flux:text>

            @if (empty($this->report->timeSeries()))
                <flux:text size="sm" class="mt-4 text-zinc-500">{{ __('No transactions in this period.') }}</flux:text>
            @else
                @php($trend = $this->report->timeSeries())
                @php($bands = $this->report->monthBandInfo())
                <div
                    x-data="{
                        tip: null,
                        anchor: null,
                        box: null,
                        setTip: function (column) {
                            this.tip = column.querySelector('.trend-tip');
                            this.anchor = column;
                            this.box = column.closest('.trend-scroll');
                            requestAnimationFrame(() => this.align());
                        },
                        align: function () {
                            const el = this.tip;
                            if (! el || ! this.anchor || ! this.box) {
                                return;
                            }
                            const col = this.anchor.getBoundingClientRect();
                            const box = this.box.getBoundingClientRect();
                            const width = el.offsetWidth;

                            if (width === 0) {
                                return;
                            }

                            const colCenter = col.left + col.width / 2;
                            const desiredCenter = Math.max(
                                box.left + width / 2 + 6,
                                Math.min(colCenter, box.right - width / 2 - 6),
                            );

                            el.style.marginLeft = (desiredCenter - colCenter) + 'px';
                        },
                        clear: function () {
                            this.tip = null;
                            this.anchor = null;
                            this.box = null;
                        },
                    }"
                    class="relative mt-6"
                >
                    <div class="trend-scroll overflow-x-auto pb-0.5 [scrollbar-width:thin] [&::-webkit-scrollbar]:h-1 [&::-webkit-scrollbar-track]:bg-transparent [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-zinc-300 dark:[&::-webkit-scrollbar-thumb]:bg-zinc-600" @scroll.passive="align()">
                        <div class="flex h-44 w-max min-w-full items-end">
                            @foreach ($trend as $bucket)
                                @php($incomeTooltip = '+'.number_format((float) $bucket['income'], 2).' '.$this->currency)
                                @php($expenseTooltip = '−'.number_format((float) $bucket['expense'], 2).' '.$this->currency)
                                <div class="group relative flex min-w-[40px] flex-1 flex-col items-center gap-1" @mouseenter="setTip($event.currentTarget)" @mouseleave="clear()">
                                    <div class="trend-tip pointer-events-none absolute bottom-9 left-1/2 z-10 hidden -translate-x-1/2 whitespace-nowrap rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-[11px] leading-tight text-zinc-700 shadow-md group-hover:block dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                        <div class="font-medium">{{ $bucket['label'] }}</div>
                                        <div class="text-zinc-500 dark:text-zinc-400">{{ __('Income') }}: {{ $incomeTooltip }}</div>
                                        <div class="text-zinc-500 dark:text-zinc-400">{{ __('Expense') }}: {{ $expenseTooltip }}</div>
                                    </div>
                                    <div class="flex h-36 w-full items-end justify-center gap-0.5">
                                        <div
                                            class="w-3 rounded-t bg-zinc-900 dark:bg-zinc-100"
                                            title="{{ $incomeTooltip }}"
                                            style="height: {{ round(((float) $bucket['income'] / $this->chartMax) * 100, 1) }}%"
                                        ></div>
                                        <div
                                            class="w-3 rounded-t bg-zinc-300 dark:bg-zinc-600"
                                            title="{{ $expenseTooltip }}"
                                            style="height: {{ round(((float) $bucket['expense'] / $this->chartMax) * 100, 1) }}%"
                                        ></div>
                                    </div>
                                    @if ($bucket['day'] === '')
                                        <div class="max-w-full whitespace-nowrap px-0.5 text-center text-[10px] leading-3 text-zinc-400">{{ $bucket['label'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($bands['show'])
                            <div
                                class="mt-2 grid w-max min-w-full overflow-hidden rounded"
                                style="grid-template-columns: repeat({{ count($trend) }}, minmax(40px, 1fr));"
                            >
                                @foreach ($trend as $i => $bucket)
                                    <div class="px-0.5 py-1 text-center text-[10px] {{ $bands['cells'][$i]['class'] }}">{{ $bucket['day'] }}</div>
                                @endforeach
                                @foreach ($trend as $i => $bucket)
                                    <div class="px-0.5 py-1 text-center text-[10px] font-medium {{ $bands['cells'][$i]['class'] }} {{ $bands['cells'][$i]['center'] ? '' : 'invisible' }}">{{ $bands['cells'][$i]['name'] }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="mt-3 flex items-center gap-4 text-xs text-zinc-500">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-zinc-900 dark:bg-zinc-100"></span>{{ __('Income') }}</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-zinc-300 dark:bg-zinc-600"></span>{{ __('Expense') }}</span>
                    </div>
                </div>
            @endif
        </div>

        <div class="grid gap-8 lg:grid-cols-2">
            {{-- Category breakdown --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="sm">{{ __('Spending by category') }}</flux:heading>
                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Share of spending in :currency (internal transfers excluded).', ['currency' => $this->currency]) }}</flux:text>

                @if (empty($this->report->byCategory()))
                    <flux:text size="sm" class="mt-4 text-zinc-500">{{ __('No expenses in this period.') }}</flux:text>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($this->report->byCategory() as $category)
                            <div>
                                <div class="flex items-center justify-between gap-2 text-sm">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-600"
                                            @if ($category['color']) style="background-color: {{ $category['color'] }}" @endif
                                        ></span>
                                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $category['name'] }}</span>
                                    </div>
                                    <span class="text-zinc-600 dark:text-zinc-400">
                                        {{ number_format((float) $category['amount'], 2) }} {{ $this->currency }}
                                        <span class="text-zinc-400">· {{ $category['share'] }}%</span>
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 w-full rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    @if ($category['color'])
                                        <div class="h-1.5 rounded-full" style="width: {{ (float) $category['share'] }}%; background-color: {{ $category['color'] }}"></div>
                                    @else
                                        <div class="h-1.5 rounded-full bg-zinc-900 dark:bg-zinc-100" style="width: {{ (float) $category['share'] }}%"></div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Subscriptions --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="sm">{{ __('Recurring vs discretionary') }}</flux:heading>
                <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('Recurring spend from logged occurrences, and what subscriptions are due next period.') }}</flux:text>

                @php($subscriptions = $this->report->subscriptions())

                @if ((float) $subscriptions['recurring'] === 0.0 && (float) $subscriptions['discretionary'] === 0.0)
                    <flux:text size="sm" class="mt-4 text-zinc-500">{{ __('No subscriptions or expenses in this period.') }}</flux:text>
                @else
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:text size="xs" class="text-zinc-500">{{ __('Recurring') }}</flux:text>
                            <flux:heading size="lg" class="mt-1">{{ number_format((float) $subscriptions['recurring'], 2) }} {{ $this->currency }}</flux:heading>
                            <flux:text size="xs" class="text-zinc-500">{{ __(':count payment(s)', ['count' => $subscriptions['recurring_count']]) }}</flux:text>
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:text size="xs" class="text-zinc-500">{{ __('Discretionary') }}</flux:text>
                            <flux:heading size="lg" class="mt-1">{{ number_format((float) $subscriptions['discretionary'], 2) }} {{ $this->currency }}</flux:heading>
                        </div>
                    </div>

                    <div class="mt-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex items-center justify-between">
                            <flux:text size="sm" class="font-medium">{{ __('Forecast: next period') }}</flux:text>
                            <flux:text size="sm" class="font-semibold">{{ number_format((float) $subscriptions['forecast'], 2) }} {{ $this->currency }}</flux:text>
                        </div>
                        <flux:text size="xs" class="mt-1 text-zinc-500">{{ __('From active subscriptions due in the period after this report.') }}</flux:text>
                    </div>

                    @if (! empty($subscriptions['by_subscription']))
                        <div class="mt-4 space-y-1.5">
                            @foreach ($subscriptions['by_subscription'] as $subscription)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $subscription['name'] }}</span>
                                    <span class="text-zinc-600 dark:text-zinc-400">{{ number_format((float) $subscription['amount'], 2) }} {{ $this->currency }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        </div>

        <div class="grid gap-8 lg:grid-cols-2">
            {{-- By account --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="sm">{{ __('By account') }}</flux:heading>

                @if (empty($this->report->byAccount()))
                    <flux:text size="sm" class="mt-4 text-zinc-500">{{ __('No transactions in this period.') }}</flux:text>
                @else
                    <table class="mt-4 w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-left text-xs text-zinc-500 dark:border-zinc-700">
                                <th class="pb-2 pe-4 font-medium">{{ __('Account') }}</th>
                                <th class="pb-2 pe-4 text-end font-medium">{{ __('Income') }}</th>
                                <th class="pb-2 pe-4 text-end font-medium">{{ __('Expense') }}</th>
                                <th class="pb-2 text-end font-medium">{{ __('Net') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->report->byAccount() as $account)
                                <tr>
                                    <td class="py-2.5 pe-4 text-zinc-800 dark:text-zinc-200">
                                        {{ $account['name'] }}
                                        <span class="block text-xs text-zinc-500">{{ $account['bank'] ?? __('No bank') }}</span>
                                    </td>
                                    <td class="py-2.5 pe-4 text-end text-zinc-600 dark:text-zinc-400">+{{ number_format((float) $account['income'], 2) }}</td>
                                    <td class="py-2.5 pe-4 text-end text-zinc-600 dark:text-zinc-400">−{{ number_format((float) $account['expense'], 2) }}</td>
                                    <td class="py-2.5 text-end font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ (float) $account['net'] >= 0 ? '+' : '−' }}{{ number_format(abs((float) $account['net']), 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- By bank --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="sm">{{ __('By bank') }}</flux:heading>

                @if (empty($this->report->byBank()))
                    <flux:text size="sm" class="mt-4 text-zinc-500">{{ __('No transactions in this period.') }}</flux:text>
                @else
                    <table class="mt-4 w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-left text-xs text-zinc-500 dark:border-zinc-700">
                                <th class="pb-2 pe-4 font-medium">{{ __('Bank') }}</th>
                                <th class="pb-2 pe-4 text-end font-medium">{{ __('Income') }}</th>
                                <th class="pb-2 pe-4 text-end font-medium">{{ __('Expense') }}</th>
                                <th class="pb-2 text-end font-medium">{{ __('Net') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->report->byBank() as $bank)
                                <tr>
                                    <td class="py-2.5 pe-4 text-zinc-800 dark:text-zinc-200">{{ $bank['bank'] }}</td>
                                    <td class="py-2.5 pe-4 text-end text-zinc-600 dark:text-zinc-400">+{{ number_format((float) $bank['income'], 2) }}</td>
                                    <td class="py-2.5 pe-4 text-end text-zinc-600 dark:text-zinc-400">−{{ number_format((float) $bank['expense'], 2) }}</td>
                                    <td class="py-2.5 text-end font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ (float) $bank['net'] >= 0 ? '+' : '−' }}{{ number_format(abs((float) $bank['net']), 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endif
</section>
