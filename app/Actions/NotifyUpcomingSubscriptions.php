<?php

namespace App\Actions;

use App\Models\Subscription;
use App\Notifications\SubscriptionDueNotification;
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
                $lastNotificationDate = $today->addDays($subscription->user->notificationDaysBefore());

                if ($dueDate->greaterThan($lastNotificationDate) || $subscription->notificationSentFor($dueDate)) {
                    return;
                }

                $subscription->user->notify(new SubscriptionDueNotification($subscription));
                $subscription->markNotificationSent($dueDate);
                $notified++;
            });

        return $notified;
    }
}
