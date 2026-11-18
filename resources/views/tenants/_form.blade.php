<div>
    <x-input-label for="user_group_id" :value="__('Benutzergruppe')" />
    <select id="user_group_id" name="user_group_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
        @foreach ($userGroups as $userGroup)
            <option value="{{ $userGroup->id }}" @selected(old('user_group_id', $tenant->user_group_id ?? '') == $userGroup->id)>{{ $userGroup->name }}</option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('user_group_id')" class="mt-2" />
</div>

<div>
    <x-input-label for="salutation" :value="__('Anrede (optional)')" />
    <select id="salutation" name="salutation" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
        <option value="" @selected(old('salutation', $tenant->salutation ?? '') === '')>{{ __('–') }}</option>
        <option value="Herr" @selected(old('salutation', $tenant->salutation ?? '') === 'Herr')>{{ __('Herr') }}</option>
        <option value="Frau" @selected(old('salutation', $tenant->salutation ?? '') === 'Frau')>{{ __('Frau') }}</option>
    </select>
    <x-input-error :messages="$errors->get('salutation')" class="mt-2" />
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="first_name" :value="__('Vorname (optional)')" />
        <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full" :value="old('first_name', $tenant->first_name ?? '')" autofocus />
        <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="last_name" :value="__('Name')" />
        <x-text-input id="last_name" name="last_name" type="text" class="mt-1 block w-full" :value="old('last_name', $tenant->last_name ?? '')" required />
        <x-input-error :messages="$errors->get('last_name')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="company_name" :value="__('Firma (optional)')" />
    <x-text-input id="company_name" name="company_name" type="text" class="mt-1 block w-full" :value="old('company_name', $tenant->company_name ?? '')" />
    <x-input-error :messages="$errors->get('company_name')" class="mt-2" />
</div>

<div>
    <x-input-label for="street" :value="__('Straße')" />
    <x-text-input id="street" name="street" type="text" class="mt-1 block w-full" :value="old('street', $tenant->street ?? '')" required />
    <x-input-error :messages="$errors->get('street')" class="mt-2" />
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="postal_code" :value="__('Postleitzahl')" />
        <x-text-input id="postal_code" name="postal_code" type="text" class="mt-1 block w-full" :value="old('postal_code', $tenant->postal_code ?? '')" required />
        <x-input-error :messages="$errors->get('postal_code')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="city" :value="__('Ort')" />
        <x-text-input id="city" name="city" type="text" class="mt-1 block w-full" :value="old('city', $tenant->city ?? '')" required />
        <x-input-error :messages="$errors->get('city')" class="mt-2" />
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="email" :value="__('E-Mail (optional)')" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $tenant->email ?? '')" />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="mobile_phone" :value="__('Handynummer (optional)')" />
        <x-text-input id="mobile_phone" name="mobile_phone" type="text" class="mt-1 block w-full" :value="old('mobile_phone', $tenant->mobile_phone ?? '')" />
        <x-input-error :messages="$errors->get('mobile_phone')" class="mt-2" />
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="iban" :value="__('IBAN (optional)')" />
        <x-text-input id="iban" name="iban" type="text" class="mt-1 block w-full" :value="old('iban', $tenant->iban ?? '')" />
        <x-input-error :messages="$errors->get('iban')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="bic" :value="__('BIC (optional)')" />
        <x-text-input id="bic" name="bic" type="text" class="mt-1 block w-full" :value="old('bic', $tenant->bic ?? '')" />
        <x-input-error :messages="$errors->get('bic')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="notes" :value="__('Notizen (optional)')" />
    <textarea id="notes" name="notes" rows="4" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('notes', $tenant->notes ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
</div>
