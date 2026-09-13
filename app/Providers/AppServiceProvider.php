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

    /**
     * Register community Socialite drivers used by config/sso.php.
     *
     * Custom OIDC connections are registered by the openidconnect package's
     * own service provider; only OAuth drivers that are not built into
     * Socialite core need registering here.
     */
    protected function configureSsoDrivers(): void
    {
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            $event->extendSocialite('discord', Provider::class);
        });
    }
}
