<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureWebPushStatusReporting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Surface web push delivery problems on the user's notification settings.
     *
     * The WebPushChannel reports per-subscription delivery results as
     * NotificationFailed / NotificationSent events. Mirror those into the
     * user's channel status so a broken push endpoint shows the same "channel
     * is failing" indicator the other channels have.
     */
    protected function configureWebPushStatusReporting(): void
    {
        Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
            $user = $event->subscription->subscribable;

            if ($user instanceof User) {
                $user->recordNotificationChannelFailure('browser', $event->report->getReason() ?: 'Web push delivery failed.');
            }
        });

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            $user = $event->subscription->subscribable;

            if ($user instanceof User) {
                $user->clearNotificationChannelFailure('browser');
            }
        });
    }
}
