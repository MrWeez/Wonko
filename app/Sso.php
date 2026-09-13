<?php

namespace App;

use Illuminate\Support\Collection;

/**
 * Resolves which SSO providers are active: SSO enabled + provider listed in
 * config/sso.php + credentials configured, so the login page only shows the
 * providers an installation actually set up.
 */
class Sso
{
    /**
     * Whether SSO is switched on at all.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('sso.enabled');
    }

    /**
     * @return Collection<int, array{key: string, driver: string, name: string}>
     */
    public static function activeProviders(): Collection
    {
        if (! static::isEnabled()) {
            return collect();
        }

        return collect((array) config('sso.providers', []))
            ->map(fn (mixed $entry, string $key): array => [
                'key' => $key,
                'driver' => static::driverFor($key) ?? $key,
                'name' => self::providerName($entry) ?? ucfirst($key),
            ])
            ->filter(fn (array $provider): bool => static::isConfigured($provider['driver']))
            ->values();
    }

    /**
     * Whether the given config key is currently active for SSO login.
     */
    public static function isActive(string $key): bool
    {
        return static::activeProviders()->contains(fn (array $provider): bool => $provider['key'] === $key);
    }

    /**
     * The Socialite driver name for a provider config key, or null.
     */
    public static function driverFor(string $key): ?string
    {
        $entry = config("sso.providers.{$key}");

        if (is_array($entry)) {
            $driver = $entry['driver'] ?? null;

            return is_string($driver) ? $driver : $key;
        }

        return is_string($entry) ? $entry : null;
    }

    /**
     * The display name for a provider config key.
     */
    public static function nameFor(string $key): string
    {
        return self::providerName(config("sso.providers.{$key}")) ?? ucfirst($key);
    }

    /**
     * Whether a driver has its OAuth credentials configured.
     */
    public static function isConfigured(string $driver): bool
    {
        /** @var array<string, mixed> $configuration */
        $configuration = (array) config("services.{$driver}", []);

        return filled($configuration['client_id'] ?? null)
            && filled($configuration['client_secret'] ?? null);
    }

    private static function providerName(mixed $entry): ?string
    {
        if (! is_array($entry)) {
            return null;
        }

        $name = $entry['name'] ?? null;

        return is_string($name) ? $name : null;
    }
}
