@php($landlord = $landlord ?? null)

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="{{ $prefix }}_salutation" :value="__('Anrede (optional)')" />
        <select id="{{ $prefix }}_salutation" name="salutation" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
            <option value="" @selected(($landlord->salutation ?? '') === '')>{{ __('–') }}</option>
            <option value="Herr" @selected(($landlord->salutation ?? '') === 'Herr')>{{ __('Herr') }}</option>
            <option value="Frau" @selected(($landlord->salutation ?? '') === 'Frau')>{{ __('Frau') }}</option>
        </select>
    </div>
    <div>
        <x-input-label for="{{ $prefix }}_first_name" :value="__('Vorname (optional)')" />
        <x-text-input id="{{ $prefix }}_first_name" name="first_name" type="text" class="mt-1 block w-full" :value="$landlord->first_name ?? ''" />
    </div>
</div>

<div>
    <x-input-label for="{{ $prefix }}_name" :value="__('Name')" />
    <x-text-input id="{{ $prefix }}_name" name="name" type="text" class="mt-1 block w-full" :value="$landlord->name ?? ''" required />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div>
    <x-input-label for="{{ $prefix }}_company_name" :value="__('Firma (optional)')" />
    <x-text-input id="{{ $prefix }}_company_name" name="company_name" type="text" class="mt-1 block w-full" :value="$landlord->company_name ?? ''" />
</div>

<div>
    <x-input-label for="{{ $prefix }}_street" :value="__('Straße (optional)')" />
    <x-text-input id="{{ $prefix }}_street" name="street" type="text" class="mt-1 block w-full" :value="$landlord->street ?? ''" />
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="{{ $prefix }}_postal_code" :value="__('Postleitzahl (optional)')" />
        <x-text-input id="{{ $prefix }}_postal_code" name="postal_code" type="text" class="mt-1 block w-full" :value="$landlord->postal_code ?? ''" />
    </div>
    <div>
        <x-input-label for="{{ $prefix }}_city" :value="__('Ort (optional)')" />
        <x-text-input id="{{ $prefix }}_city" name="city" type="text" class="mt-1 block w-full" :value="$landlord->city ?? ''" />
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="{{ $prefix }}_email" :value="__('Email (optional)')" />
        <x-text-input id="{{ $prefix }}_email" name="email" type="email" class="mt-1 block w-full" :value="$landlord->email ?? ''" />
    </div>
    <div>
        <x-input-label for="{{ $prefix }}_phone" :value="__('Telefon (optional)')" />
        <x-text-input id="{{ $prefix }}_phone" name="phone" type="text" class="mt-1 block w-full" :value="$landlord->phone ?? ''" />
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="{{ $prefix }}_mobile_phone" :value="__('Mobilfunk (optional)')" />
        <x-text-input id="{{ $prefix }}_mobile_phone" name="mobile_phone" type="text" class="mt-1 block w-full" :value="$landlord->mobile_phone ?? ''" />
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="{{ $prefix }}_iban" :value="__('IBAN (optional)')" />
        <x-text-input id="{{ $prefix }}_iban" name="iban" type="text" class="mt-1 block w-full" :value="$landlord->iban ?? ''" />
    </div>
    <div>
        <x-input-label for="{{ $prefix }}_bic" :value="__('BIC (optional)')" />
        <x-text-input id="{{ $prefix }}_bic" name="bic" type="text" class="mt-1 block w-full" :value="$landlord->bic ?? ''" />
    </div>
</div>

<div>
    <x-input-label for="{{ $prefix }}_notes" :value="__('Notizen (optional)')" />
    <textarea id="{{ $prefix }}_notes" name="notes" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ $landlord->notes ?? '' }}</textarea>
</div>
