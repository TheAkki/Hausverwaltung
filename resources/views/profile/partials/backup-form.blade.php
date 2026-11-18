<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Backup') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Exportiere deine Daten (Profileinstellungen, alle Gruppen, in denen du Mitglied bist, samt Verwaltungsstruktur und alle angehängten Dateien) als ZIP-Datei oder stelle sie aus einer solchen Datei wieder her.') }}
        </p>
    </header>

    <div class="mt-6 space-y-6">
        <div>
            <a href="{{ route('backup.export') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                {{ __('Daten exportieren') }}
            </a>

            @if (auth()->user()->isAdministrator())
                <a href="{{ route('backup.export-full') }}"
                   class="ml-2 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    {{ __('Alle Einstellungen exportieren') }}
                </a>
            @endif
        </div>

        <form method="post" action="{{ route('backup.import') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf

            <div>
                <x-input-label for="backup" :value="__('Backup importieren (ZIP-Datei)')" />
                <input id="backup" name="backup" type="file" accept=".zip"
                       class="mt-1 block w-full text-sm text-gray-600 border border-gray-300 rounded-md shadow-sm" required>
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('Der Import legt die Gruppen aus dem Backup neu an, mit dir als Besitzer. Mitglieder samt Rollen werden nur für Gruppen wiederhergestellt, in denen du Admin warst, und nur für Benutzer, die es hier schon gibt.') }}
                </p>
                <x-input-error class="mt-2" :messages="$errors->get('backup')" />
            </div>

            <x-primary-button>{{ __('Importieren') }}</x-primary-button>

            @if (session('status') && str_contains(session('status'), __('Backup importiert')))
                <p class="text-sm text-gray-600">{{ session('status') }}</p>
            @endif
        </form>
    </div>
</section>
