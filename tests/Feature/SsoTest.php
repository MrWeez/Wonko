<?php

use App\Models\User;
use App\Sso;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

function configureGithubProvider(bool $enabled = true, bool $withCredentials = true): void
{
    config([
        'sso.enabled' => $enabled,
        'sso.providers' => [
            'github' => ['driver' => 'github', 'name' => 'GitHub'],
            'google' => ['driver' => 'google', 'name' => 'Google'],
        ],
    ]);

    config([
        'services.github' => $withCredentials
            ? ['client_id' => 'github-id', 'client_secret' => 'github-secret', 'redirect' => '/sso/github/callback']
            : ['client_id' => '', 'client_secret' => '', 'redirect' => '/sso/github/callback'],
        'services.google' => ['client_id' => '', 'client_secret' => '', 'redirect' => '/sso/google/callback'],
    ]);
}

function stubSsoUser(?string $email, string $name = 'Jane Doe'): SocialiteUser
{
    $user = new SocialiteUser;

    $user->id = 12345;
    $user->name = $name;
    $user->email = $email;

    return $user;
}

function mockSsoDriver(string $driver, SocialiteUser $ssoUser): void
{
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($ssoUser);

    Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);
}

test('sso routes return 404 and no buttons render while disabled', function () {
    configureGithubProvider(enabled: false);

    $this->get('/sso/github')->assertNotFound();
    $this->get('/sso/github/callback')->assertNotFound();
    $this->get(route('login'))->assertOk()->assertDontSee('Continue with');
});

test('login page renders only providers with configured credentials', function () {
    configureGithubProvider();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with GitHub')
        ->assertDontSee('Continue with Google');
});

test('a provider without credentials is not active even when present in config', function () {
    configureGithubProvider(withCredentials: false);

    $this->get('/sso/github')->assertNotFound();
    $this->get(route('login'))->assertOk()->assertDontSee('Continue with GitHub');
});

test('sso redirect sends the user to the configured provider', function () {
    configureGithubProvider();

    $this->get('/sso/github')
        ->assertRedirect()
        ->assertRedirectContains('github.com/login/oauth/authorize');
});

test('sso callback creates a new verified user and logs them in', function () {
    configureGithubProvider();
    mockSsoDriver('github', stubSsoUser('jane@example.com'));

    $this->get('/sso/github/callback')
        ->assertRedirect()
        ->assertRedirectContains(config('fortify.home'));

    $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($this->assertAuthenticatedAs($user))->toBeTruthy();
});

test('sso callback normalizes the email case when matching users', function () {
    configureGithubProvider();
    $existing = User::factory()->create(['email' => 'JANE@example.com']);
    mockSsoDriver('github', stubSsoUser('jane@example.com'));

    $this->get('/sso/github/callback')->assertRedirect();

    expect(User::query()->count())->toBe(1)
        ->and($this->assertAuthenticatedAs($existing))->toBeTruthy();
});

test('sso callback marks an existing unverified account as verified', function () {
    configureGithubProvider();
    $existing = User::factory()->create(['email' => 'bob@example.com', 'email_verified_at' => null]);
    mockSsoDriver('github', stubSsoUser('bob@example.com'));

    $this->get('/sso/github/callback')->assertRedirect();

    expect($existing->fresh()->email_verified_at)->not->toBeNull();
});

test('sso callback fails gracefully when the provider returns no email', function () {
    configureGithubProvider();
    mockSsoDriver('github', stubSsoUser(null));

    $this->get('/sso/github/callback')
        ->assertRedirect(route('login'))
        ->assertSessionHas('errors');

    expect(User::query()->count())->toBe(0);
});

test('sso callback fails gracefully when the provider request errors', function () {
    configureGithubProvider();

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->once()->andThrow(new RuntimeException('Provider down'));
    Socialite::shouldReceive('driver')->with('github')->andReturn($provider);

    $this->get('/sso/github/callback')
        ->assertRedirect(route('login'))
        ->assertSessionHas('errors');

    expect(User::query()->count())->toBe(0);
});

test('an unknown provider is rejected', function () {
    configureGithubProvider();

    $this->get('/sso/unknown')->assertNotFound();
    $this->get('/sso/unknown/callback')->assertNotFound();
});

test('an already authenticated user is sent home from the callback', function () {
    configureGithubProvider();

    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/sso/github/callback')
        ->assertRedirect()
        ->assertRedirectContains(config('fortify.home'));
});

test('sso callback route exists for wired-in providers', function () {
    configureGithubProvider();

    expect(Route::has('sso.redirect'))->toBeTrue()
        ->and(Route::has('sso.callback'))->toBeTrue();
});

test('the generic provider uses its custom display name on the login page', function () {
    config([
        'sso.enabled' => true,
        'sso.providers' => [
            'generic' => ['driver' => 'oidc_generic', 'name' => 'My IdP'],
        ],
        'services.oidc_generic' => [
            'client_id' => 'id',
            'client_secret' => 'secret',
            'redirect' => '/sso/generic/callback',
        ],
    ]);

    expect(Sso::nameFor('generic'))->toBe('My IdP');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with My IdP')
        ->assertDontSee('Continue with Generic OIDC');
});

test('sso callback errors use the custom provider display name', function () {
    config([
        'sso.enabled' => true,
        'sso.providers' => [
            'generic' => ['driver' => 'oidc_generic', 'name' => 'My IdP'],
        ],
        'services.oidc_generic' => [
            'client_id' => 'id',
            'client_secret' => 'secret',
            'redirect' => '/sso/generic/callback',
        ],
    ]);

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->once()->andThrow(new RuntimeException('Provider down'));
    Socialite::shouldReceive('driver')->with('oidc_generic')->andReturn($provider);

    $this->get('/sso/generic/callback')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors();

    expect(session('errors')->get('email'))->toContain('Unable to sign you in with My IdP. Please try again.');
});
