<?php

namespace App\Actions;

use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\Transaction;
use App\Notifications\SubscriptionDueNotification;
use App\PaymentMethodType;
use App\TransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessDueSubscriptions
{
    public function handle(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $processed = 0;

        Subscription::query()
            ->withoutGlobalScope('owned-by-user')
            ->where('active', true)
            ->where('auto_post', true)
            ->whereDate('next_due_date', '<=', $today)
            ->with([
                'account' => fn ($q) => $q->withoutGlobalScope('owned-by-user'),
                'category' => fn ($q) => $q->withoutGlobalScope('owned-by-user'),
                'paymentMethod' => fn ($q) => $q->withoutGlobalScope('owned-by-user')->with([
                    'primaryAccount' => fn ($q) => $q->withoutGlobalScope('owned-by-user'),
                    'secondaryAccount' => fn ($q) => $q->withoutGlobalScope('owned-by-user'),
                ]),
            ])
            ->orderBy('id')
            ->eachById(function (Subscription $subscription) use ($today, &$processed): void {
                DB::transaction(function () use ($subscription, $today, &$processed): void {
                    $dueDate = CarbonImmutable::parse((string) $subscription->next_due_date);

                    while ($dueDate->lessThanOrEqualTo($today)) {
                        $occurrenceExists = SubscriptionOccurrence::query()
                            ->withoutGlobalScope('owned-by-user')
                            ->where('subscription_id', $subscription->id)
                            ->whereDate('due_date', $dueDate)
                            ->exists();

                        if (! $occurrenceExists) {
                            $primaryTransaction = null;
                            $method = $subscription->paymentMethod;

                            if ($method && $method->type === PaymentMethodType::Composite && $method->secondary_account_id) {
                                $splits = $method->calculateSplit($subscription->amount);
                                $splitGroupId = (string) Str::uuid();

                                if ((float) $splits['primary_amount'] > 0) {
                                    $primaryTransaction = new Transaction;
                                    $primaryTransaction->forceFill([
                                        'user_id' => $subscription->user_id,
                                        'account_id' => $method->primary_account_id,
                                        'category_id' => $subscription->category_id,
                                        'payment_method_id' => $method->id,
                                        'split_group_id' => $splitGroupId,
                                        'type' => TransactionType::Expense,
                                        'description' => $subscription->name,
                                        'currency' => $subscription->currency,
                                        'amount' => $splits['primary_amount'],
                                        'account_amount' => null,
                                        'transaction_date' => $dueDate,
                                    ]);
                                    $primaryTransaction->save();
                                }

                                if ((float) $splits['secondary_amount'] > 0) {
                                    $secondaryTransaction = new Transaction;
                                    $secondaryTransaction->forceFill([
                                        'user_id' => $subscription->user_id,
                                        'account_id' => $method->secondary_account_id,
                                        'category_id' => $subscription->category_id,
                                        'payment_method_id' => $method->id,
                                        'split_group_id' => $splitGroupId,
                                        'type' => TransactionType::Expense,
                                        'description' => $subscription->name,
                                        'currency' => $subscription->currency,
                                        'amount' => $splits['secondary_amount'],
                                        'account_amount' => null,
                                        'transaction_date' => $dueDate,
                                    ]);
                                    $secondaryTransaction->save();

                                    $primaryTransaction ??= $secondaryTransaction;
                                }
                            } else {
                                $primaryTransaction = new Transaction;
                                $primaryTransaction->forceFill([
                                    'user_id' => $subscription->user_id,
                                    'account_id' => $method->primary_account_id ?? $subscription->account_id,
                                    'category_id' => $subscription->category_id,
                                    'payment_method_id' => $method?->id,
                                    'type' => TransactionType::Expense,
                                    'description' => $subscription->name,
                                    'currency' => $subscription->currency,
                                    'amount' => $subscription->amount,
                                    'account_amount' => null,
                                    'transaction_date' => $dueDate,
                                ]);
                                $primaryTransaction->save();
                            }

                            $occurrence = new SubscriptionOccurrence;
                            $occurrence->forceFill([
                                'user_id' => $subscription->user_id,
                                'subscription_id' => $subscription->id,
                                'transaction_id' => $primaryTransaction?->id,
                                'currency' => $subscription->currency,
                                'amount' => $subscription->amount,
                                'account_amount' => null,
                                'due_date' => $dueDate,
                                'paid_at' => now(),
                                'notes' => 'Automatically posted',
                            ]);
                            $occurrence->save();

                            $subscription->markNotificationFullySent($dueDate, $subscription->reminderDays());
                            $subscription->user->notify(new SubscriptionDueNotification($subscription, $occurrence));

                            $processed++;
                        }

                        $dueDate = $subscription->nextDueDateAfter($dueDate);
                    }

                    $subscription->forceFill(['next_due_date' => $dueDate])->save();
                });
            });

        return $processed;
    }
}
