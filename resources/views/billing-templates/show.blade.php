<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $billingTemplate->name }}</h2>
            <div class="flex items-center gap-4">
                @can('update', $billingTemplate)
                    <a href="{{ route('billing-templates.edit', $billingTemplate) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
                @endcan
                <form method="POST" action="{{ route('billing-templates.duplicate', $billingTemplate) }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-600 hover:underline">{{ __('Duplizieren') }}</button>
                </form>
                @can('delete', $billingTemplate)
                    <form method="POST" action="{{ route('billing-templates.destroy', $billingTemplate) }}" onsubmit="return confirm('{{ __('Template wirklich löschen?') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Löschen') }}</button>
                    </form>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[922px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <p class="text-xs text-gray-500">{{ $billingTemplate->userGroup?->name ?? __('Intern') }}</p>

                @if ($billingTemplate->is_default)
                    <p class="text-xs text-gray-500">{{ __('Globales Standard-Template') }}</p>
                @endif

                @if ($billingTemplate->headerImageUrl())
                    <div>
                        <p class="text-xs text-gray-500 mb-1">{{ __('Kopf-Bild') }}</p>
                        <img src="{{ $billingTemplate->headerImageUrl() }}" alt="" class="h-16">
                    </div>
                @endif

                @if ($billingTemplate->header)
                    <div>
                        <p class="text-xs text-gray-500 mb-1">{{ __('Kopfzeile') }}</p>
                        <pre class="text-xs bg-gray-50 p-4 rounded overflow-x-auto">{{ $billingTemplate->header }}</pre>
                    </div>
                @endif

                <div>
                    <p class="text-xs text-gray-500 mb-1">{{ __('Inhalt') }}</p>
                    <pre class="text-xs bg-gray-50 p-4 rounded overflow-x-auto">{{ $billingTemplate->content }}</pre>
                </div>

                @if ($billingTemplate->css_content)
                    <div>
                        <p class="text-xs text-gray-500 mb-1">{{ __('CSS-Content') }}</p>
                        <pre class="text-xs bg-gray-50 p-4 rounded overflow-x-auto">{{ $billingTemplate->css_content }}</pre>
                    </div>
                @endif

                @if ($billingTemplate->footer)
                    <div>
                        <p class="text-xs text-gray-500 mb-1">{{ __('Fußzeile') }}</p>
                        <pre class="text-xs bg-gray-50 p-4 rounded overflow-x-auto">{{ $billingTemplate->footer }}</pre>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
