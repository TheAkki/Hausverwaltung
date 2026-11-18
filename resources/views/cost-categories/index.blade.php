<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Kostenkategorien') }}</h2>
            <a href="{{ route('cost-categories.create') }}" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium hover:bg-gray-700">
                {{ __('Neue Kostenkategorie') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[922px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-6 py-3">{{ __('Name') }}</th>
                            <th class="px-6 py-3">{{ __('Herkunft') }}</th>
                            <th class="px-6 py-3">{{ __('Abrechnungsrelevant') }}</th>
                            <th class="px-6 py-3">{{ __('Steuerrelevant') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($costCategories as $costCategory)
                            <tr>
                                <td class="px-6 py-3" style="padding-left: {{ 1.5 + $costCategory->depth() * 1.25 }}rem">
                                    @if ($costCategory->isSubcategory())
                                        <span class="text-gray-400">└</span>
                                    @endif
                                    {{ $costCategory->displayName() }}
                                </td>
                                <td class="px-6 py-3 text-gray-500">
                                    {{ $costCategory->user_group_id === null ? __('Systemweit') : $costCategory->userGroup->name }}
                                </td>
                                <td class="px-6 py-3">
                                    @if ($costCategory->isSubcategory())
                                        {{-- Abrechnungs-/Steuerrelevanz einer Unterkategorie werden immer von
                                             der Hauptkategorie übernommen (CostCategoryObserver) – hier also
                                             nur les- statt bearbeitbar, unabhängig davon, wem sie gehört. --}}
                                        <input type="checkbox" disabled title="{{ __('Von der Hauptkategorie übernommen') }}" @checked($costCategory->is_relevant_for_billing)>
                                    @elseif (Auth::user()->can('update', $costCategory))
                                        {{-- Each toggle submits both flags (the other one pinned to its current
                                             value as a hidden field), so flipping one never resets the other. --}}
                                        <form method="POST" action="{{ route('cost-categories.update', $costCategory) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_relevant_for_billing" value="0">
                                            <input type="checkbox" name="is_relevant_for_billing" value="1" onchange="this.form.submit()" @checked($costCategory->is_relevant_for_billing)>
                                            <input type="hidden" name="is_tax_relevant" value="{{ $costCategory->is_tax_relevant ? 1 : 0 }}">
                                        </form>
                                    @else
                                        <input type="checkbox" disabled @checked($costCategory->is_relevant_for_billing)>
                                    @endif
                                </td>
                                <td class="px-6 py-3">
                                    @if ($costCategory->isSubcategory())
                                        <input type="checkbox" disabled title="{{ __('Von der Hauptkategorie übernommen') }}" @checked($costCategory->is_tax_relevant)>
                                    @elseif (Auth::user()->can('update', $costCategory))
                                        <form method="POST" action="{{ route('cost-categories.update', $costCategory) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_relevant_for_billing" value="{{ $costCategory->is_relevant_for_billing ? 1 : 0 }}">
                                            <input type="hidden" name="is_tax_relevant" value="0">
                                            <input type="checkbox" name="is_tax_relevant" value="1" onchange="this.form.submit()" @checked($costCategory->is_tax_relevant)>
                                        </form>
                                    @else
                                        <input type="checkbox" disabled @checked($costCategory->is_tax_relevant)>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
