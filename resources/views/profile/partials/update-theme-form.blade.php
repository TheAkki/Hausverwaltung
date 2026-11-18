<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Theme') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Wähle das Erscheinungsbild der Anwendung.') }}
            <a href="{{ route('themes.index') }}" class="underline hover:text-gray-900">{{ __('Themes verwalten') }}</a>
        </p>
    </header>

    <form method="post" action="{{ route('profile.theme.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="theme_id" :value="__('Theme')" />
            <select id="theme_id" name="theme_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" onchange="this.form.submit()">
                <option value="">{{ __('Standard') }}</option>
                @foreach ($themes as $theme)
                    <option value="{{ $theme->id }}" @selected($user->theme_id === $theme->id)>{{ $theme->name }}</option>
                @endforeach
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('theme_id')" />
        </div>

        @if (session('status') === 'theme-updated')
            <p class="text-sm text-gray-600">{{ __('Saved.') }}</p>
        @endif
    </form>
</section>
