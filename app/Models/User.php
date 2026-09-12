<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $locked_at
 * @property bool $must_change_password
 * @property int $notification_days_before
 * @property array<string, bool>|null $notification_preferences
 * @property array<string, mixed>|null $notification_channel_config
 * @property array<string, array<string, string>>|null $notification_channel_status
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'email_verified_at', 'notification_preferences'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'notification_channel_config'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_at' => 'datetime',
            'must_change_password' => 'boolean',
            'notification_days_before' => 'integer',
            'notification_preferences' => 'array',
            'notification_channel_config' => 'encrypted:array',
            'notification_channel_status' => 'encrypted:array',
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function notificationPreferences(): array
    {
        return array_replace([
            'database' => true,
            'email' => false,
            'webhook' => false,
            'discord' => false,
            'telegram' => false,
        ], $this->notification_preferences ?? []);
    }

    public function notificationChannelEnabled(string $channel): bool
    {
        return $this->notificationPreferences()[$channel] ?? false;
    }

    /**
     * @param  array<string, bool>  $preferences
     */
    public function setNotificationPreferences(array $preferences): void
    {
        $this->forceFill([
            'notification_preferences' => array_replace($this->notificationPreferences(), $preferences),
        ])->save();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function notificationChannelConfigurations(): array
    {
        return $this->notification_channel_config ?? [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $configurations
     */
    public function setNotificationChannelConfigurations(array $configurations): void
    {
        $this->forceFill([
            'notification_channel_config' => array_replace_recursive(
                $this->notificationChannelConfigurations(),
                $configurations,
            ),
        ])->save();
    }

    public function notificationDaysBefore(): int
    {
        return $this->notification_days_before ?? 0;
    }

    public function setNotificationDaysBefore(int $days): void
    {
        $this->forceFill(['notification_days_before' => $days])->save();
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function notificationChannelFailureStatus(): array
    {
        return $this->notification_channel_status ?? [];
    }

    public function recordNotificationChannelFailure(string $channel, string $message): void
    {
        $this->forceFill([
            'notification_channel_status' => array_replace_recursive(
                $this->notificationChannelFailureStatus(),
                [$channel => [
                    'failed_at' => now()->toISOString(),
                    'message' => $message,
                ]],
            ),
        ])->save();
    }

    public function clearNotificationChannelFailure(string $channel): void
    {
        $status = $this->notificationChannelFailureStatus();
        unset($status[$channel]);

        $this->forceFill(['notification_channel_status' => $status ?: null])->save();
    }

    /**
     * @return HasMany<Bank, $this>
     */
    public function banks(): HasMany
    {
        return $this->hasMany(Bank::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function lock(): void
    {
        $this->forceFill(['locked_at' => now()])->save();
    }

    public function unlock(): void
    {
        $this->forceFill(['locked_at' => null])->save();
    }

    /**
     * Strip personally identifying data while keeping financial records.
     */
    public function anonymizePersonalData(): void
    {
        $this->forceFill([
            'name' => 'Anonymized User',
            'email' => 'anonymized-'.$this->id.'@localhost',
            'password' => Str::random(60),
            'email_verified_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'locked_at' => null,
        ])->save();

        $this->passkeys()->delete();
    }
}
