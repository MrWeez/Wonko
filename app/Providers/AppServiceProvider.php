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
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        $this->configureSsoDrivers();
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
     * Mirror WebPush delivery results into the user's browser channel status.
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

    /**
     * Register community Socialite drivers (OIDC comes via its own provider).
     */
    protected function configureSsoDrivers(): void
    {
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            $event->extendSocialite('discord', Provider::class);
        });
    }
}
