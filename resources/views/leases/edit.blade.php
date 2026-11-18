<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mietvertrag bearbeiten') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[691px] mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('leases.update', $lease) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="tenant_ids" :value="__('Mieter')" />
                        <select id="tenant_ids" name="tenant_ids[]" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" multiple required>
                            @foreach ($tenants as $tenant)
                                <option value="{{ $tenant->id }}" @selected(in_array($tenant->id, old('tenant_ids', $lease->tenants->pluck('id')->all())))>{{ $tenant->fullName() }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">{{ __('Strg/Cmd gedrückt halten, um mehrere Mieter auszuwählen.') }}</p>
                        <x-input-error :messages="$errors->get('tenant_ids')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="start_date" :value="__('Startdatum')" />
                            <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="old('start_date', $lease->start_date->toDateString())" required />
                            <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="end_date" :value="__('Enddatum (optional)')" />
                            <x-text-input id="end_date" name="end_date" type="date" class="mt-1 block w-full" :value="old('end_date', optional($lease->end_date)->toDateString())" />
                            <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <label for="is_pre_contract" class="inline-flex items-center">
                            <input type="hidden" name="is_pre_contract" value="0">
                            <input type="checkbox" id="is_pre_contract" name="is_pre_contract" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm" @checked(old('is_pre_contract', $lease->is_pre_contract)) />
                            <span class="ms-2 text-sm text-gray-700">{{ __('Vorvertrag (wird nicht separat abgerechnet, geht im nachfolgenden Mietvertrag auf)') }}</span>
                        </label>
                        <x-input-error :messages="$errors->get('is_pre_contract')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="rent_adjustment_type" :value="__('Art der Mietanpassung')" />
                        <select id="rent_adjustment_type" name="rent_adjustment_type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                            <option value="fixed" @selected(old('rent_adjustment_type', $lease->rent_adjustment_type) === 'fixed')>{{ __('Fix') }}</option>
                            <option value="stepped" @selected(old('rent_adjustment_type', $lease->rent_adjustment_type) === 'stepped')>{{ __('Staffelmiete') }}</option>
                            <option value="indexed" @selected(old('rent_adjustment_type', $lease->rent_adjustment_type) === 'indexed')>{{ __('Indexmiete') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('rent_adjustment_type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="cold_rent" :value="__('Kaltmiete')" />
                        <x-text-input id="cold_rent" name="cold_rent" type="number" step="0.01" class="mt-1 block w-full" :value="old('cold_rent', $lease->cold_rent)" required />
                        <x-input-error :messages="$errors->get('cold_rent')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="deposit_amount" :value="__('Kaution (optional)')" />
                            <x-text-input id="deposit_amount" name="deposit_amount" type="number" step="0.01" class="mt-1 block w-full" :value="old('deposit_amount', $lease->deposit_amount)" />
                            <x-input-error :messages="$errors->get('deposit_amount')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="min_term_months" :value="__('Mindestlaufzeit (Monate)')" />
                            <x-text-input id="min_term_months" name="min_term_months" type="number" min="0" class="mt-1 block w-full" :value="old('min_term_months', $lease->min_term_months)" />
                            <x-input-error :messages="$errors->get('min_term_months')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="notice_period" :value="__('Kündigungsfrist (optional)')" />
                        <x-text-input id="notice_period" name="notice_period" type="text" class="mt-1 block w-full" :value="old('notice_period', $lease->notice_period)" />
                        <x-input-error :messages="$errors->get('notice_period')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="notes" :value="__('Notizen (optional)')" />
                        <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('notes', $lease->notes) }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('leases.show', $lease) }}" class="text-sm text-gray-600 hover:underline">{{ __('Abbrechen') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
