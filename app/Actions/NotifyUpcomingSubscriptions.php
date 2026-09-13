<?php

namespace App\Actions;

use App\Models\Subscription;
use App\Notifications\SubscriptionDueNotification;
use App\Notifications\SubscriptionOverdueNotification;
use Carbon\CarbonImmutable;

class NotifyUpcomingSubscriptions
{
    public function handle(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $notified = 0;

        Subscription::query()
            ->withoutGlobalScope('owned-by-user')
            ->where('active', true)
            ->with('user')
            ->orderBy('id')
            ->eachById(function (Subscription $subscription) use ($today, &$notified): void {
                $dueDate = CarbonImmutable::parse((string) $subscription->next_due_date);
                $state = $subscription->notificationStateFor($dueDate->toDateString());

                $newDays = [];
                $send = null;

                foreach ($subscription->reminderDays() as $days) {
                    if ($dueDate->subDays($days)->lte($today) && ! in_array($days, $state['days'], true)) {
                        $newDays[] = $days;
                    }
                }

                $lastOverdueAt = $state['overdue_at'] !== null ? CarbonImmutable::parse($state['overdue_at']) : null;

                $overdue = $subscription->overdueEnabled()
                    && $dueDate->lessThan($today)
                    && ($lastOverdueAt === null || $lastOverdueAt->addDays($subscription->overdueFrequency())->lte($today));

                if ($newDays !== []) {
                    $subscription->markRemindersSent($dueDate->toDateString(), $newDays);
                    $send = new SubscriptionDueNotification($subscription);
                }

                if ($overdue) {
                    $subscription->markOverdueSent($dueDate->toDateString(), $today);
                    $send = new SubscriptionOverdueNotification($subscription);
                }

                if ($send !== null) {
                    $subscription->user->notify($send);
                    $notified++;
                }
            });

        return $notified;
    }
}
