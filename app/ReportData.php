<?php

namespace App;

use App\Models\Account;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Aggregates per-currency report data for a user over a date range.
 *
 * Reports never mix currencies: every metric is computed strictly for a
 * single currency, and transaction amounts are always summed in the currency
 * they were recorded in.
 */
class ReportData
{
    /** @var Collection<int, Transaction>|null */
    private ?Collection $transactions = null;

    /** @var Collection<int, Account>|null */
    private ?Collection $accounts = null;

    /** @var array<string, string>|null */
    private ?array $categoriesByName = null;

    public function __construct(
        public User $user,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $currency,
    ) {}

    /**
     * @return array{income: numeric-string, expense: numeric-string, net: numeric-string}
     */
    public function totals(): array
    {
        $income = '0';
        $expense = '0';

        foreach ($this->transactions() as $transaction) {
            if ($this->countsAsIncome($transaction)) {
                $income = self::add($income, (string) $transaction->amount);
            } elseif ($this->countsAsExpense($transaction)) {
                $expense = self::add($expense, (string) $transaction->amount);
            }
        }

        return [
            'income' => self::padding($income),
            'expense' => self::padding($expense),
            'net' => self::sub($income, $expense),
        ];
    }

    /**
     * @return list<array{name: string, color: string|null, amount: string, share: string}>
     */
    public function byCategory(): array
    {
        $expenseTotal = $this->sumByType(TransactionType::Expense);

        if (bccomp(self::num($expenseTotal), '0', 4) === 0) {
            return [];
        }

        $totals = [];

        foreach ($this->transactions() as $transaction) {
            if ($transaction->type !== TransactionType::Expense) {
                continue;
            }

            $name = $transaction->category->name ?? 'Uncategorized';
            $totals[$name] = self::add($totals[$name] ?? '0', (string) $transaction->amount);
        }

        uksort($totals, fn (string $a, string $b): int => self::cmp($totals[$b], $totals[$a]));

        return array_map(fn (string $amount, string $name): array => [
            'name' => $name,
            'color' => $this->categoriesByName()[$name] ?? null,
            'amount' => (string) $amount,
            'share' => (string) self::mul(self::div(self::num($amount), $expenseTotal), '100', 1),
        ], $totals, array_keys($totals));
    }

    /**
     * @return list<array{name: string, bank: string|null, income: string, expense: string, net: string}>
     */
    public function byAccount(): array
    {
        $income = [];
        $expense = [];
        $rows = [];

        foreach ($this->transactions() as $transaction) {
            if ($this->countsAsIncome($transaction)) {
                $income[$transaction->account_id] = self::add($income[$transaction->account_id] ?? '0', (string) $transaction->amount);
            } elseif ($this->countsAsExpense($transaction)) {
                $expense[$transaction->account_id] = self::add($expense[$transaction->account_id] ?? '0', (string) $transaction->amount);
            }
        }

        foreach (array_unique([...array_keys($income), ...array_keys($expense)]) as $accountId) {
            $account = $this->accountsById()[$accountId] ?? null;
            $in = $income[$accountId] ?? '0';
            $out = $expense[$accountId] ?? '0';

            $rows[] = [
                'name' => $account->name ?? sprintf('Account #%d', $accountId),
                'bank' => $account->bank?->name,
                'income' => (string) $in,
                'expense' => (string) $out,
                'net' => (string) self::sub($in, $out),
            ];
        }

        usort($rows, fn (array $a, array $b): int => (float) $b['income'] + (float) $b['expense'] <=> (float) $a['income'] + (float) $a['expense']);

        return $rows;
    }

    /**
     * @return list<array{bank: string, income: string, expense: string, net: string}>
     */
    public function byBank(): array
    {
        $grouped = [];

        foreach ($this->byAccount() as $row) {
            $bank = $row['bank'] ?? 'No bank';
            $grouped[$bank]['income'] = self::add($grouped[$bank]['income'] ?? '0', $row['income']);
            $grouped[$bank]['expense'] = self::add($grouped[$bank]['expense'] ?? '0', $row['expense']);
        }

        uksort($grouped, fn (string $a, string $b): int => self::cmp($grouped[$b]['expense'], $grouped[$a]['expense']));

        return array_map(fn (array $row, string $bank): array => [
            'bank' => $bank,
            'income' => (string) $row['income'],
            'expense' => (string) $row['expense'],
            'net' => (string) self::sub($row['income'], $row['expense']),
        ], $grouped, array_keys($grouped));
    }

    /**
     * @return list<array{label: string, month: string, day: string, income: string, expense: string, net: string}>
     */
    public function timeSeries(): array
    {
        $unit = $this->bucketUnit();
        $keyFor = fn (CarbonInterface $date): string => match ($unit) {
            'month' => $date->format('Y-m'),
            'week' => (string) floor($date->diffInDays($this->from) / 7),
            default => $date->toDateString(),
        };
        $labelFor = fn (CarbonInterface $date): string => match ($unit) {
            'month' => $date->format('M Y'),
            'week' => $date->startOfWeek()->format('d M'),
            default => $date->format('d M'),
        };

        $buckets = [];

        for ($cursor = $this->from; $cursor->lte($this->to);) {
            $buckets[$keyFor($cursor)] = [
                'label' => $labelFor($cursor),
                'month' => $unit === 'month' ? '' : $cursor->format('F'),
                'day' => $unit === 'month' ? '' : $cursor->format('j'),
                'income' => '0',
                'expense' => '0',
            ];
            $cursor = match ($unit) {
                'month' => $cursor->addMonth(),
                'week' => $cursor->addDays(7),
                default => $cursor->addDay(),
            };
        }

        foreach ($this->transactions() as $transaction) {
            $key = $keyFor($transaction->transaction_date);

            if (! isset($buckets[$key])) {
                continue;
            }

            if ($this->countsAsIncome($transaction)) {
                $column = 'income';
            } elseif ($this->countsAsExpense($transaction)) {
                $column = 'expense';
            } else {
                continue;
            }

            $buckets[$key][$column] = self::add($buckets[$key][$column], (string) $transaction->amount);
        }

        return array_values(array_map(fn (array $bucket): array => [
            'label' => $bucket['label'],
            'month' => $bucket['month'],
            'day' => $bucket['day'],
            'income' => (string) $bucket['income'],
            'expense' => (string) $bucket['expense'],
            'net' => (string) self::sub($bucket['income'], $bucket['expense']),
        ], $buckets));
    }

    /**
     * Per-column month cells and per-month segments for the trend band.
     *
     * @return array{show: bool, cells: list<array{class: string, name: string, center: bool}>, months: list<array{start: int, end: int, name: string, class: string}>}
     */
    public function monthBandInfo(): array
    {
        $series = $this->timeSeries();
        $colorA = 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200';
        $colorB = 'bg-zinc-300 text-zinc-700 dark:bg-zinc-600 dark:text-zinc-300';
        $cells = [];
        $months = [];
        $toggle = 0;
        $month = null;
        $class = '';
        $start = 0;
        $end = 0;

        foreach ($series as $i => $bucket) {
            $current = $bucket['month'];

            if ($current === '') {
                $cells[] = ['class' => '', 'name' => '', 'center' => false];

                continue;
            }

            if ($current !== $month) {
                if ($month !== null) {
                    $months[] = ['start' => $start, 'end' => $end, 'name' => $month, 'class' => $class];
                    $center = intdiv($start + $end, 2);

                    for ($j = $start; $j <= $end; $j++) {
                        $cells[$j]['class'] = $class;
                        $cells[$j]['name'] = $month;
                        $cells[$j]['center'] = $j === $center;
                    }
                }

                $toggle++;
                $month = $current;
                $class = $toggle % 2 === 1 ? $colorA : $colorB;
                $start = $i;
                $end = $i;
                $cells[$i] = ['class' => $class, 'name' => $current, 'center' => false];
            } else {
                $end = $i;
                $cells[$i] = ['class' => $class, 'name' => $current, 'center' => false];
            }
        }

        if ($month !== null) {
            $months[] = ['start' => $start, 'end' => $end, 'name' => $month, 'class' => $class];
            $center = intdiv($start + $end, 2);

            for ($j = $start; $j <= $end; $j++) {
                $cells[$j]['class'] = $class;
                $cells[$j]['name'] = $month;
                $cells[$j]['center'] = $j === $center;
            }
        }

        return [
            'show' => array_any($cells, fn (array $cell): bool => $cell['class'] !== ''),
            'cells' => array_values($cells),
            'months' => array_values($months),
        ];
    }

    /**
     * @return array{recurring: string, recurring_count: int, discretionary: string, forecast: string, by_subscription: list<array{name: string, amount: string}>}
     */
    public function subscriptions(): array
    {
        $recurring = '0';
        $count = 0;
        $bySubscription = [];

        $occurrences = SubscriptionOccurrence::query()
            ->withoutGlobalScope('owned-by-user')
            ->where('user_id', $this->user->id)
            ->where('currency', $this->currency)
            ->whereRaw('date(COALESCE(paid_at,due_date)) between ? and ?', [$this->from->toDateString(), $this->to->toDateString()])
            ->with(['subscription' => fn ($query) => $query->withoutGlobalScope('owned-by-user')])
            ->get();

        foreach ($occurrences as $occurrence) {
            $recurring = self::add($recurring, (string) $occurrence->amount);
            $count++;

            $name = $occurrence->subscription->name ?? 'Unknown';
            $bySubscription[$name] = self::add($bySubscription[$name] ?? '0', (string) $occurrence->amount);
        }

        uksort($bySubscription, fn (string $a, string $b): int => self::cmp($bySubscription[$b], $bySubscription[$a]));

        $rawDiscretionary = self::sub($this->sumByType(TransactionType::Expense), $recurring);

        return [
            'recurring' => (string) $recurring,
            'recurring_count' => $count,
            'discretionary' => bccomp(self::num($rawDiscretionary), '0', 4) < 0 ? '0.0000' : (string) $rawDiscretionary,
            'forecast' => (string) $this->forecastNextPeriod(),
            'by_subscription' => array_map(fn (string $amount, string $name): array => [
                'name' => $name,
                'amount' => (string) $amount,
            ], $bySubscription, array_keys($bySubscription)),
        ];
    }

    private function forecastNextPeriod(): string
    {
        $nextStart = $this->to->addDay();
        $nextEnd = $nextStart->addDays($this->from->diffInDays($this->to));
        $forecast = '0';

        $subscriptions = Subscription::query()
            ->withoutGlobalScope('owned-by-user')
            ->where('user_id', $this->user->id)
            ->where('active', true)
            ->where('currency', $this->currency)
            ->get();

        foreach ($subscriptions as $subscription) {
            $cursor = CarbonImmutable::instance($subscription->next_due_date);

            while ($cursor->isBefore($nextStart) && $cursor->lte($this->to)) {
                $cursor = $subscription->nextDueDateAfter($cursor);
            }

            while ($cursor->lte($nextEnd)) {
                $forecast = self::add($forecast, (string) $subscription->amount);
                $cursor = $subscription->nextDueDateAfter($cursor);
            }
        }

        return $forecast;
    }

    private function bucketUnit(): string
    {
        $days = $this->from->diffInDays($this->to);

        if ($days <= 31) {
            return 'day';
        }

        return $days <= 183 ? 'week' : 'month';
    }

    /**
     * Transfers count as money flow: the destination leg increases the
     * balance like income, the source leg decreases it like an expense.
     */
    private function countsAsIncome(Transaction $transaction): bool
    {
        return $transaction->type === TransactionType::Income
            || ($transaction->type === TransactionType::Transfer && $transaction->transfer_direction === TransferDirection::Destination);
    }

    private function countsAsExpense(Transaction $transaction): bool
    {
        return $transaction->type === TransactionType::Expense
            || ($transaction->type === TransactionType::Transfer && $transaction->transfer_direction === TransferDirection::Source);
    }

    /**
     * @return numeric-string
     */
    private function sumByType(TransactionType $type): string
    {
        $total = '0';

        foreach ($this->transactions() as $transaction) {
            if ($transaction->type === $type) {
                $total = self::add($total, (string) $transaction->amount);
            }
        }

        return $total;
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function transactions(): Collection
    {
        return $this->transactions ??= Transaction::query()
            ->withoutGlobalScope('owned-by-user')
            ->where('user_id', $this->user->id)
            ->where('currency', $this->currency)
            ->whereDate('transaction_date', '>=', $this->from->toDateString())
            ->whereDate('transaction_date', '<=', $this->to->toDateString())
            ->with(['category' => fn ($query) => $query->withoutGlobalScope('owned-by-user')])
            ->get(['id', 'type', 'transfer_direction', 'amount', 'transaction_date', 'category_id', 'account_id']);
    }

    /**
     * @return Collection<int, Account>
     */
    private function accountsById(): Collection
    {
        return $this->accounts ??= Account::query()
            ->withoutGlobalScope('owned-by-user')
            ->where('user_id', $this->user->id)
            ->with(['bank' => fn ($query) => $query->withoutGlobalScope('owned-by-user')])
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array<string, string>
     */
    private function categoriesByName(): array
    {
        return $this->categoriesByName ??= Category::query()
            ->withoutGlobalScope('owned-by-user')
            ->where('user_id', $this->user->id)
            ->pluck('color', 'name')
            ->all();
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function padding(string $value): string
    {
        return bcadd($value, '0', 4);
    }

    /**
     * @param  string  $value  Any decimal string; assumed numeric.
     * @return numeric-string
     */
    private static function num(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('Expected a numeric amount, got: '.$value);
        }

        return $value;
    }

    /**
     * @return numeric-string
     */
    private static function add(string $a, string $b): string
    {
        return bcadd(self::num($a), self::num($b), 4);
    }

    /**
     * @return numeric-string
     */
    private static function sub(string $a, string $b): string
    {
        return bcsub(self::num($a), self::num($b), 4);
    }

    private static function cmp(string $a, string $b): int
    {
        return bccomp(self::num($a), self::num($b), 4);
    }

    /**
     * @return numeric-string
     */
    private static function mul(string $a, string $b, int $scale = 4): string
    {
        return bcmul(self::num($a), self::num($b), $scale);
    }

    /**
     * @return numeric-string
     */
    private static function div(string $a, string $b): string
    {
        return bcdiv(self::num($a), self::num($b), 4);
    }
}
