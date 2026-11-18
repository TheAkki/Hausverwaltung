<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Mietvertrag') }}: {{ $lease->tenantNames() }}
            </h2>
            <a href="{{ route('leases.edit', $lease) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1536px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('Mieteinheit') }}</dt>
                        <dd><a href="{{ route('rental-units.show', $lease->rentalUnit) }}" class="hover:underline">{{ $lease->rentalUnit->managementUnit->street }} &mdash; {{ $lease->rentalUnit->label() }}</a></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Kaltmiete') }}</dt>
                        <dd>{{ euro($lease->cold_rent) }} €</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Start') }}</dt>
                        <dd>{{ $lease->start_date->format('d.m.Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Ende') }}</dt>
                        <dd>{{ optional($lease->end_date)->format('d.m.Y') }}</dd>
                    </div>
                    @if ($lease->deposit_amount !== null)
                        <div>
                            <dt class="text-gray-500">{{ __('Kaution') }}</dt>
                            <dd>{{ euro($lease->deposit_amount) }} €</dd>
                        </div>
                    @endif
                    @if ($lease->min_term_months > 0)
                        <div>
                            <dt class="text-gray-500">{{ __('Mindestlaufzeit') }}</dt>
                            <dd>{{ $lease->min_term_months }} {{ __('Monate') }}</dd>
                        </div>
                    @endif
                    @if ($lease->notice_period)
                        <div>
                            <dt class="text-gray-500">{{ __('Kündigungsfrist') }}</dt>
                            <dd>{{ $lease->notice_period }}</dd>
                        </div>
                    @endif
                    @if ($lease->is_pre_contract)
                        <div>
                            <dt class="text-gray-500">{{ __('Vorvertrag') }}</dt>
                            <dd><span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ __('Geht im nachfolgenden Mietvertrag auf') }}</span></dd>
                        </div>
                    @endif
                </dl>
                @if ($lease->notes)
                    <div class="mt-4 pt-4 border-t">
                        <dt class="text-gray-500 text-sm">{{ __('Notizen') }}</dt>
                        <dd class="text-sm whitespace-pre-line">{{ $lease->notes }}</dd>
                    </div>
                @endif
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">{{ __('Mietintervalle') }}</h3>
                    <a href="{{ route('lease-intervals.create', ['lease_id' => $lease->id]) }}" class="text-sm text-gray-600 hover:underline">
                        {{ __('Neues Mietintervall') }}
                    </a>
                </div>
                @php
                    $sortedIntervals = $lease->leaseIntervals->sortBy('start_date')->values();
                @endphp
                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Start') }}</th>
                            <th class="py-2">{{ __('Ende') }}</th>
                            <th class="py-2">{{ __('Miete') }}</th>
                            <th class="py-2">{{ __('Personen') }}</th>
                            <th class="py-2">{{ __('Betriebskostenvorauszahlung') }}</th>
                            <th class="py-2">{{ __('Notiz') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($sortedIntervals as $interval)
                            <tr id="interval-{{ $interval->id }}">
                                <td class="py-2">
                                    <a href="{{ route('lease-intervals.edit', $interval) }}" class="hover:underline">{{ $interval->start_date->format('d.m.Y') }}</a>
                                </td>
                                <td class="py-2">{{ optional($interval->end_date)->format('d.m.Y') }}</td>
                                <td class="py-2 whitespace-nowrap">{{ euro($interval->rent_amount) }} €</td>
                                <td class="py-2">{{ $interval->occupant_count }}</td>
                                <td class="py-2 whitespace-nowrap">{{ euro($interval->utility_advance_payment) }} €</td>
                                <td class="py-2 text-gray-500">{{ $interval->notes }}</td>
                                <td class="py-2">
                                    @if ($sortedIntervals->count() > 1 && $interval->is($sortedIntervals->last()))
                                        <form method="POST" action="{{ route('lease-intervals.destroy', $interval) }}" onsubmit="return confirm('{{ __('Letztes Mietintervall wirklich löschen?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:underline">{{ __('Entfernen') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6" id="mietzahlungen">
                <h3 class="font-semibold mb-4">{{ __('Mietzahlungen') }}</h3>
                @php
                    $rentPayments = $lease->leaseIntervals->flatMap->rentPayments->sortByDesc('period_date');
                    // For a gekoppelter Mietvertrag: which of the referenced Mietvertrag's
                    // Mietzahlungen actually picked up this Monat's Beitrag (see
                    // RentPaymentGenerator/contributingLeases()), keyed by Monat, so die
                    // Buchungsdatum can be shown/verlinkt precisely instead of just generically.
                    // rentPaymentContributions() also contains this Mietvertrag's OWN (never
                    // gebuchte) Mietzahlungen – it contributes to those too – which share the
                    // same Monat, so those must be filtered out first, or keyBy() would
                    // non-deterministically keep whichever of the two same-Monat rows happens
                    // to come last instead of the referenced Mietvertrag's one.
                    $contributionsByPeriod = $lease->coupledToLease
                        ? $lease->rentPaymentContributions
                            ->filter(fn ($p) => $p->leaseInterval->lease_id === $lease->coupled_to_lease_id)
                            ->keyBy(fn ($p) => $p->period_date->toDateString())
                        : collect();
                @endphp
                @if ($lease->coupledToLease)
                    {{-- Konzept.md "### Mietvertrag": ein referenzierender Mietvertrag (z. B. ein
                         Stellplatz, gekoppelt an die Wohnung desselben Mieters) wird nicht separat
                         gebucht – der Mieter überweist nur einmal Miete, verbucht beim
                         referenzierten Mietvertrag. --}}
                    <p class="text-sm text-gray-600 mb-4">
                        {{ __('Dieser Mietvertrag ist gekoppelt an') }}
                        <a href="{{ route('leases.show', $lease->coupledToLease) }}" class="hover:underline">{{ $lease->coupledToLease->rentalUnit->label() }} &mdash; {{ $lease->coupledToLease->tenantNames() }}</a>
                        {{ __('– die Mietzahlung wird dort gebucht, hier ist keine eigene Buchung nötig.') }}
                    </p>
                @endif
                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Monat') }}</th>
                            <th class="py-2">{{ __('Betrag') }}</th>
                            <th class="py-2">{{ __('Buchungsdatum') }}</th>
                            <th class="py-2">{{ __('Status') }}</th>
                            @if ($managementGroup->kontosystemEnabled())
                                <th class="py-2">{{ __('Konto') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($rentPayments as $payment)
                            <tr>
                                <td class="py-2">{{ $payment->period_date->locale('de')->translatedFormat('F Y') }}</td>
                                <td class="py-2 whitespace-nowrap">
                                    {{ euro($payment->amount) }} €
                                    @if ($payment->contributingLeases->count() > 1)
                                        {{-- Konzept.md "### Mietvertrag": ein gekoppelter Mietvertrag (z. B. ein
                                             Stellplatz) wird nie separat gebucht, seine Miete fließt hier mit ein
                                             – die Aufschlüsselung macht den überwiesenen Betrag nachvollziehbar. --}}
                                        <br>
                                        <span class="text-xs text-gray-500">
                                            {{ $payment->contributingLeases->map(fn ($contributingLease) => euro($contributingLease->pivot->amount).' € '.$contributingLease->rentalUnit->label())->join(' + ') }}
                                        </span>
                                    @endif
                                </td>
                                @if ($lease->coupledToLease)
                                    @php $contribution = $contributionsByPeriod->get($payment->period_date->toDateString()); @endphp
                                    <td class="py-2 text-gray-400">{{ optional($contribution?->booking_date)->format('d.m.Y') ?? '–' }}</td>
                                    <td class="py-2 text-gray-500">
                                        @if ($contribution?->booking_date)
                                            <a href="{{ route('leases.show', $lease->coupledToLease).'#mietzahlungen' }}" class="hover:underline">{{ __('Gebucht beim referenzierten Mietvertrag') }}</a>
                                        @else
                                            {{ __('Wird beim referenzierten Mietvertrag gebucht') }}
                                        @endif
                                    </td>
                                    @if ($managementGroup->kontosystemEnabled())
                                        <td class="py-2"></td>
                                    @endif
                                @else
                                <td class="py-2">
                                    <form method="POST" action="{{ route('rent-payments.update', $payment) }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('PUT')
                                        <input type="date" name="booking_date" value="{{ ($payment->booking_date ?? $payment->period_date)->toDateString() }}" class="border-gray-300 rounded-md shadow-sm text-sm {{ $payment->isOpen() ? 'text-gray-400' : '' }}" @if (! $payment->isOpen()) onchange="this.form.submit()" @endif>
                                </td>
                                <td class="py-2">
                                    @if ($payment->isOpen())
                                        <button type="submit" name="confirm_booking" value="1" class="text-red-600 hover:underline">{{ __('Offen – Buchen') }}</button>
                                    @else
                                        <span class="text-green-700">{{ __('Gebucht') }}</span>
                                    @endif
                                </td>
                                @if ($managementGroup->kontosystemEnabled())
                                    <td class="py-2">
                                        <select name="account_id" class="border-gray-300 rounded-md shadow-sm text-sm" @if (! $payment->isOpen()) onchange="this.form.submit()" @endif>
                                            <option value="">{{ __('– kein Konto –') }}</option>
                                            @foreach ($managementGroup->accounts as $account)
                                                <option value="{{ $account->id }}" @selected($payment->accounts->contains($account->id))>{{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endif
                                    </form>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $managementGroup->kontosystemEnabled() ? 5 : 4 }}" class="py-2 text-gray-500">{{ __('Keine Mietzahlungen vorhanden.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6" id="forderungen">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">{{ __('Forderungen') }}</h3>
                    <a href="{{ route('settlement-claims.create', ['lease_id' => $lease->id]) }}" class="text-sm text-gray-600 hover:underline">
                        {{ __('Neue Forderung') }}
                    </a>
                </div>
                @php
                    $settlementClaims = $lease->settlementClaims->sortByDesc('date')->values();
                @endphp
                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Datum') }}</th>
                            <th class="py-2">{{ __('Abrechnungsintervall') }}</th>
                            <th class="py-2">{{ __('Betrag') }}</th>
                            <th class="py-2">{{ __('Buchungsdatum') }}</th>
                            <th class="py-2">{{ __('Status') }}</th>
                            <th class="py-2">{{ __('Notiz') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($settlementClaims as $claim)
                            <tr>
                                <td class="py-2">
                                    <a href="{{ route('settlement-claims.edit', $claim) }}" class="hover:underline">{{ $claim->date->format('d.m.Y') }}</a>
                                </td>
                                <td class="py-2">{{ $claim->billingInterval->start_date->format('d.m.Y') }} &ndash; {{ $claim->billingInterval->end_date->format('d.m.Y') }}</td>
                                <td class="py-2 whitespace-nowrap {{ $claim->amount < 0 ? 'text-red-600' : '' }}">{{ euro($claim->amount) }} €</td>
                                <td class="py-2">
                                    <form method="POST" action="{{ route('settlement-claims.update', $claim) }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="billing_interval_id" value="{{ $claim->billing_interval_id }}">
                                        <input type="hidden" name="date" value="{{ $claim->date->toDateString() }}">
                                        <input type="hidden" name="amount" value="{{ $claim->amount }}">
                                        <input type="date" name="booking_date" value="{{ ($claim->booking_date ?? $claim->date)->toDateString() }}" class="border-gray-300 rounded-md shadow-sm text-sm {{ $claim->isOpen() ? 'text-gray-400' : '' }}" @if (! $claim->isOpen()) onchange="this.form.submit()" @endif>
                                </td>
                                <td class="py-2">
                                    @if ($claim->isOpen())
                                        <button type="submit" class="text-red-600 hover:underline">{{ __('Offen – Buchen') }}</button>
                                    @else
                                        <span class="text-green-700">{{ __('Gebucht') }}</span>
                                    @endif
                                    </form>
                                </td>
                                <td class="py-2 text-gray-500">{{ $claim->notes }}</td>
                                <td class="py-2">
                                    <form method="POST" action="{{ route('settlement-claims.destroy', $claim) }}" onsubmit="return confirm('{{ __('Forderung wirklich löschen?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:underline">{{ __('Entfernen') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-2 text-gray-500">{{ __('Keine Forderungen vorhanden.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6" id="rechnungen">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">{{ __('Rechnungen') }}</h3>
                    <a href="{{ route('invoices.create', ['lease_id' => $lease->id]) }}" class="text-sm text-gray-600 hover:underline">
                        {{ __('Neue Rechnung') }}
                    </a>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-600">
                        <tr>
                            <th class="py-2">{{ __('Rechnungsdatum') }}</th>
                            <th class="py-2">{{ __('Rechnungssteller') }}</th>
                            <th class="py-2">{{ __('Betrag') }}</th>
                            <th class="py-2">{{ __('Periodizität') }}</th>
                            <th class="py-2">{{ __('Buchungsdatum') }}</th>
                            <th class="py-2">{{ __('Beschreibung') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($invoices as $invoice)
                            <tr>
                                <td class="py-2">
                                    <a href="{{ route('invoices.edit', $invoice) }}" class="hover:underline">{{ $invoice->invoice_date->format('d.m.Y') }}</a>
                                </td>
                                <td class="py-2">{{ $invoice->invoiceIssuer->fullName() }}</td>
                                <td class="py-2 whitespace-nowrap">{{ euro($invoice->amount) }} €</td>
                                <td class="py-2">{{ $invoice->periodicityLabel() }}</td>
                                <td class="py-2">{{ optional($invoice->booking_date)->format('d.m.Y') }}</td>
                                <td class="py-2 text-gray-500">{{ $invoice->description }}</td>
                                <td class="py-2">
                                    <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" onsubmit="return confirm('{{ __('Rechnung wirklich löschen?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:underline">{{ __('Entfernen') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-2 text-gray-500">{{ __('Keine Rechnungen vorhanden.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">{{ __('Dokumente') }}</h3>
                <ul class="text-sm divide-y mb-4">
                    @forelse ($lease->documents as $document)
                        <li class="py-2 flex justify-between items-center">
                            <a href="{{ Storage::url($document->file_path) }}" download="{{ $document->original_name }}" class="hover:underline">{{ $document->original_name }}</a>
                            <form method="POST" action="{{ route('lease-documents.destroy', $document) }}" onsubmit="return confirm('{{ __('Dokument wirklich löschen?') }}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">{{ __('Entfernen') }}</button>
                            </form>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('Keine Dokumente vorhanden.') }}</li>
                    @endforelse
                </ul>

                <form method="POST" action="{{ route('leases.documents.store', $lease) }}" enctype="multipart/form-data" class="flex items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input-label for="file" :value="__('Datei hochladen (PDF, Word, Bild)')" />
                        <input id="file" name="file" type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required class="mt-1 block w-full text-sm">
                        <x-input-error :messages="$errors->get('file')" class="mt-2" />
                    </div>
                    <x-primary-button>{{ __('Hochladen') }}</x-primary-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
