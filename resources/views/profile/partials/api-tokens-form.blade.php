<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('API-Zugang') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Mit einem persönlichen Token kann ein Script deine Daten über die REST-API lesen und schreiben, ohne dein Passwort zu kennen. Widerrufe Tokens, die du nicht mehr brauchst.') }}
            <a href="{{ route('api-docs') }}" class="underline hover:text-gray-900">{{ __('API-Dokumentation') }}</a>
        </p>
    </header>

    @if (session('status') === \App\Http\Controllers\ApiTokenController::STATUS_CREATED)
        <div class="mt-4 p-4 bg-yellow-50 border border-yellow-300 rounded-md text-sm">
            <p class="font-medium text-gray-900">{{ __('Neuer Token – jetzt kopieren, er wird nur einmal angezeigt:') }}</p>
            <code class="mt-2 block break-all select-all text-gray-800" id="api-token-plain">{{ session(\App\Http\Controllers\ApiTokenController::SESSION_PLAIN_TOKEN) }}</code>
        </div>
    @elseif (session('status') === \App\Http\Controllers\ApiTokenController::STATUS_REVOKED)
        <p class="mt-4 text-sm text-gray-600">{{ __('Token widerrufen.') }}</p>
    @endif

    <form method="post" action="{{ route('profile.api-tokens.store') }}" class="mt-6 flex items-end gap-4">
        @csrf
        <div class="flex-1">
            <x-input-label for="token_name" :value="__('Name des Tokens')" />
            <x-text-input id="token_name" name="token_name" type="text" class="mt-1 block w-full" :placeholder="__('z. B. Import-Script Laptop')" required />
            <x-input-error class="mt-2" :messages="$errors->apiToken->get('token_name')" />
        </div>
        <x-primary-button>{{ __('Token erzeugen') }}</x-primary-button>
    </form>

    @if ($user->tokens->isNotEmpty())
        <table class="mt-6 w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">{{ __('Name') }}</th>
                    <th class="py-1">{{ __('Erstellt') }}</th>
                    <th class="py-1">{{ __('Zuletzt benutzt') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @foreach ($user->tokens->sortByDesc('created_at') as $token)
                    <tr>
                        <td class="py-2">{{ $token->name }}</td>
                        <td class="py-2">{{ $token->created_at?->format('d.m.Y H:i') }}</td>
                        <td class="py-2">{{ $token->last_used_at?->format('d.m.Y H:i') ?? __('nie') }}</td>
                        <td class="py-2 text-right">
                            <form method="post" action="{{ route('profile.api-tokens.destroy', $token->id) }}">
                                @csrf
                                @method('delete')
                                <button type="submit" class="text-red-600 hover:underline">{{ __('Widerrufen') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
