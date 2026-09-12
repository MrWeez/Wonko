<?php

namespace App\Actions;

use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\Transaction;
use App\Notifications\SubscriptionDueNotification;
use App\TransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
            ->with(['account', 'category'])
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
                            $transaction = new Transaction;
                            $transaction->forceFill([
                                'user_id' => $subscription->user_id,
                                'account_id' => $subscription->account_id,
                                'category_id' => $subscription->category_id,
                                'type' => TransactionType::Expense,
                                'description' => $subscription->name,
                                'currency' => $subscription->currency,
                                'amount' => $subscription->amount,
                                'account_amount' => null,
                                'transaction_date' => $dueDate,
                            ])->save();

                            $occurrence = new SubscriptionOccurrence;
                            $occurrence->forceFill([
                                'user_id' => $subscription->user_id,
                                'subscription_id' => $subscription->id,
                                'transaction_id' => $transaction->id,
                                'currency' => $subscription->currency,
                                'amount' => $subscription->amount,
                                'account_amount' => null,
                                'due_date' => $dueDate,
                                'paid_at' => now(),
                                'notes' => 'Automatically posted',
                            ])->save();

                            if (! $subscription->notificationSentFor($dueDate)) {
                                $subscription->user->notify(new SubscriptionDueNotification($subscription, $occurrence));
                                $subscription->markNotificationSent($dueDate);
                            }

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
