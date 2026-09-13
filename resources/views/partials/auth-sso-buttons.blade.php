@php($ssoProviders = App\Sso::activeProviders())

@if ($ssoProviders->isNotEmpty())
    <div class="flex flex-col gap-3">
        @foreach ($ssoProviders as $ssoProvider)
            <a
                href="{{ route('sso.redirect', $ssoProvider['key']) }}"
                class="flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                data-test="sso-provider-{{ $ssoProvider['key'] }}"
            >
                {{ __('Continue with :provider', ['provider' => $ssoProvider['name']]) }}
            </a>
        @endforeach

        <div class="flex items-center gap-3 py-1 text-xs uppercase tracking-widest text-zinc-400">
            <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
            <span>{{ __('or') }}</span>
            <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        </div>
    </div>
@endif