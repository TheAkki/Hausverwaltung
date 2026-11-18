<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Neue Kostenkategorie') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('cost-categories.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <x-input-label for="user_group_id" :value="__('Benutzergruppe')" />
                        <select id="user_group_id" name="user_group_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                            @foreach ($userGroups as $userGroup)
                                <option value="{{ $userGroup->id }}" @selected(old('user_group_id') == $userGroup->id)>{{ $userGroup->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('user_group_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="parent_id" :value="__('Überkategorie')" />
                        <select id="parent_id" name="parent_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="" data-billing="1" data-tax="1" @selected(old('parent_id') === null)>{{ __('— Keine (neue Hauptkategorie) —') }}</option>
                            @foreach ($parentOptions as $parentOption)
                                <option
                                    value="{{ $parentOption->id }}"
                                    data-billing="{{ $parentOption->is_relevant_for_billing ? 1 : 0 }}"
                                    data-tax="{{ $parentOption->is_tax_relevant ? 1 : 0 }}"
                                    @selected((string) old('parent_id') === (string) $parentOption->id)
                                >{{ str_repeat('　', $parentOption->depth()) }}{{ $parentOption->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Wird eine Überkategorie gewählt, entsteht eine Unterkategorie ("Überkategorie::Name"); ob sie abrechnungs-/steuerrelevant ist, übernimmt sie dann automatisch von ihrer Hauptkategorie.') }}</p>
                        <x-input-error :messages="$errors->get('parent_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div id="relevance-fields">
                        <div>
                            <label for="is_relevant_for_billing" class="inline-flex items-center">
                                <input type="hidden" name="is_relevant_for_billing" value="0">
                                <input type="checkbox" id="is_relevant_for_billing" name="is_relevant_for_billing" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm" @checked(old('is_relevant_for_billing', true)) />
                                <span class="ms-2 text-sm text-gray-700">{{ __('Abrechnungsrelevant') }}</span>
                            </label>
                            <x-input-error :messages="$errors->get('is_relevant_for_billing')" class="mt-2" />
                        </div>

                        <div class="mt-4">
                            <label for="is_tax_relevant" class="inline-flex items-center">
                                <input type="hidden" name="is_tax_relevant" value="0">
                                <input type="checkbox" id="is_tax_relevant" name="is_tax_relevant" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm" @checked(old('is_tax_relevant', true)) />
                                <span class="ms-2 text-sm text-gray-700">{{ __('Steuerrelevant') }}</span>
                            </label>
                            <x-input-error :messages="$errors->get('is_tax_relevant')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('cost-categories.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Bei gewählter Überkategorie sind die beiden Checkboxen nur eine Vorschau (der
         Server übernimmt die Werte ohnehin von der Hauptkategorie, siehe
         CostCategoryObserver) – hier ausgegraut, damit das nicht wie eine eigene
         Einstellung wirkt. --}}
    <script>
        (function () {
            const parentSelect = document.getElementById('parent_id');
            const billingCheckbox = document.getElementById('is_relevant_for_billing');
            const taxCheckbox = document.getElementById('is_tax_relevant');

            function syncRelevanceFields() {
                const option = parentSelect.options[parentSelect.selectedIndex];
                const isSubcategory = option.value !== '';

                billingCheckbox.checked = option.dataset.billing === '1';
                taxCheckbox.checked = option.dataset.tax === '1';
                billingCheckbox.disabled = isSubcategory;
                taxCheckbox.disabled = isSubcategory;
            }

            parentSelect.addEventListener('change', syncRelevanceFields);
            syncRelevanceFields();
        })();
    </script>
</x-app-layout>
