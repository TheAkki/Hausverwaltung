<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Themes') }}</h2>
            <a href="{{ route('themes.create') }}" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                {{ __('Neues Theme') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[922px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <ul class="divide-y">
                    @foreach ($themes as $theme)
                        <li class="px-6 py-4 flex justify-between items-center">
                            <span>
                                {{ $theme->name }}
                                @if ($theme->is_builtin)
                                    <span class="text-xs text-gray-500 ml-2">{{ __('eingebaut') }}</span>
                                @endif
                            </span>
                            @unless ($theme->is_builtin)
                                <span class="space-x-3 text-sm">
                                    <a href="{{ route('themes.edit', $theme) }}" class="text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
                                    <form method="POST" action="{{ route('themes.destroy', $theme) }}" class="inline" onsubmit="return confirm('{{ __('Theme wirklich löschen?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:underline">{{ __('Löschen') }}</button>
                                    </form>
                                </span>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
