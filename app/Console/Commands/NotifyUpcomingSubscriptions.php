<?php

namespace App\Console\Commands;

use App\Actions\NotifyUpcomingSubscriptions as NotifyUpcomingSubscriptionsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscriptions:notify-upcoming')]
#[Description('Notify users about upcoming subscriptions')]
class NotifyUpcomingSubscriptions extends Command
{
    public function handle(NotifyUpcomingSubscriptionsAction $notifyUpcomingSubscriptions): int
    {
        $notified = $notifyUpcomingSubscriptions->handle();

        $this->components->info("Sent {$notified} subscription notification(s).");

        return self::SUCCESS;
    }
}
