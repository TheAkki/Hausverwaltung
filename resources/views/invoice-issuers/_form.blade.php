<div>
    <x-input-label for="user_group_id" :value="__('Benutzergruppe')" />
    <select id="user_group_id" name="user_group_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
        @foreach ($userGroups as $userGroup)
            <option value="{{ $userGroup->id }}" @selected(old('user_group_id', $invoiceIssuer->user_group_id ?? '') == $userGroup->id)>{{ $userGroup->name }}</option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('user_group_id')" class="mt-2" />
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="salutation" :value="__('Anrede (optional)')" />
        <select id="salutation" name="salutation" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
            <option value="" @selected(old('salutation', $invoiceIssuer->salutation ?? '') === '')>{{ __('–') }}</option>
            <option value="Herr" @selected(old('salutation', $invoiceIssuer->salutation ?? '') === 'Herr')>{{ __('Herr') }}</option>
            <option value="Frau" @selected(old('salutation', $invoiceIssuer->salutation ?? '') === 'Frau')>{{ __('Frau') }}</option>
        </select>
    </div>
    <div>
        <x-input-label for="first_name" :value="__('Vorname (optional)')" />
        <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full" :value="old('first_name', $invoiceIssuer->first_name ?? '')" autofocus />
        <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="name" :value="__('Name')" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $invoiceIssuer->name ?? '')" required />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div>
    <x-input-label for="company_name" :value="__('Firma (optional)')" />
    <x-text-input id="company_name" name="company_name" type="text" class="mt-1 block w-full" :value="old('company_name', $invoiceIssuer->company_name ?? '')" />
    <x-input-error :messages="$errors->get('company_name')" class="mt-2" />
</div>

<div>
    <x-input-label for="street" :value="__('Straße')" />
    <x-text-input id="street" name="street" type="text" class="mt-1 block w-full" :value="old('street', $invoiceIssuer->street ?? '')" required />
    <x-input-error :messages="$errors->get('street')" class="mt-2" />
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="postal_code" :value="__('Postleitzahl')" />
        <x-text-input id="postal_code" name="postal_code" type="text" class="mt-1 block w-full" :value="old('postal_code', $invoiceIssuer->postal_code ?? '')" required />
        <x-input-error :messages="$errors->get('postal_code')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="city" :value="__('Ort')" />
        <x-text-input id="city" name="city" type="text" class="mt-1 block w-full" :value="old('city', $invoiceIssuer->city ?? '')" required />
        <x-input-error :messages="$errors->get('city')" class="mt-2" />
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="email" :value="__('Email')" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $invoiceIssuer->email ?? '')" />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="phone" :value="__('Telefon (optional)')" />
        <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $invoiceIssuer->phone ?? '')" />
        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="mobile_phone" :value="__('Mobilfunk (optional)')" />
        <x-text-input id="mobile_phone" name="mobile_phone" type="text" class="mt-1 block w-full" :value="old('mobile_phone', $invoiceIssuer->mobile_phone ?? '')" />
        <x-input-error :messages="$errors->get('mobile_phone')" class="mt-2" />
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <x-input-label for="iban" :value="__('IBAN (optional)')" />
        <x-text-input id="iban" name="iban" type="text" class="mt-1 block w-full" :value="old('iban', $invoiceIssuer->iban ?? '')" />
        <x-input-error :messages="$errors->get('iban')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="bic" :value="__('BIC (optional)')" />
        <x-text-input id="bic" name="bic" type="text" class="mt-1 block w-full" :value="old('bic', $invoiceIssuer->bic ?? '')" />
        <x-input-error :messages="$errors->get('bic')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="comment" :value="__('Kommentar')" />
    <textarea id="comment" name="comment" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('comment', $invoiceIssuer->comment ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('comment')" class="mt-2" />
</div>
