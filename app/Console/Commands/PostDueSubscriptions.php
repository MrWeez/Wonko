<?php

namespace App\Console\Commands;

use App\Actions\ProcessDueSubscriptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscriptions:post-due')]
#[Description('Post due subscriptions configured for automatic posting')]
class PostDueSubscriptions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProcessDueSubscriptions $processDueSubscriptions): int
    {
        $processed = $processDueSubscriptions->handle();

        $this->components->info("Posted {$processed} subscription occurrence(s).");

        return self::SUCCESS;
    }
}
