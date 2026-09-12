<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;

class SubscriptionDueNotification extends Notification
{
    public function __construct(
        public Subscription $subscription,
        public SubscriptionOccurrence $occurrence,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->notificationChannelEnabled('database')
            ? ['database']
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'subscription_due',
            'subscription_id' => $this->subscription->id,
            'subscription_name' => $this->subscription->name,
            'occurrence_id' => $this->occurrence->id,
            'amount' => $this->occurrence->amount,
            'currency' => $this->occurrence->currency,
            'due_date' => CarbonImmutable::parse((string) $this->occurrence->due_date)->toDateString(),
        ];
    }
}
