{{--
    "Leerstand" (Konzept.md "##### Leerstände": "Abrechnungsrelevante, aber
    leerstehende Mieteinheiten müssen berücksichtigt werden. Berechnet werden diese
    mit einem 0 Personenhaushalt. Ansonsten werden sie wie ein Mieter gehandhabt.")
    for one Abrechnungsintervall. Die eigentliche Kostenberechnung findet direkt in
    der Betriebskostenabrechnung selbst statt (siehe BillingCostAllocationService::
    vacancyLeaseEntries(), dort mit "isVacancy" markiert) – hier nur eine Übersicht
    der Leerstands-Zeiträume je Mieteinheit samt Forderungsverwaltung.
    $vacancy = vacancyLeaseEntries()'s return value for that interval.
    $managementUnit/$billingInterval werden für die Forderungen-Links gebraucht.
--}}
<div class="mt-2 pl-4 space-y-4">
    @forelse ($vacancy->groupBy(fn ($entry) => $entry['rentalUnit']->id) as $rentalUnitId => $entries)
        @php $rentalUnit = $entries->first()['rentalUnit']; @endphp
        <div>
            <div class="flex justify-between items-center">
                <p class="text-gray-600">{{ $rentalUnit->description ?: $rentalUnit->type }}</p>
                <a href="{{ route('settlement-claims.create', ['rental_unit_id' => $rentalUnit->id]) }}" class="text-xs text-gray-600 hover:underline">
                    {{ __('Neue Forderung') }}
                </a>
            </div>
            @foreach ($entries as $entry)
                <p class="text-gray-600 text-sm">
                    {{ __('leer vom') }} {{ $entry['periodStart']->format('d.m.Y') }} {{ __('bis') }} {{ $entry['periodEnd']->format('d.m.Y') }}
                    ({{ $entry['days'] }} {{ __('Tage') }})
                </p>
            @endforeach

            @php $claims = $entries->first()['claims']; @endphp
            @if ($claims->isNotEmpty())
                <div class="overflow-x-auto mt-1">
                    <table class="w-full text-xs text-left">
                        <thead class="text-gray-500">
                            <tr>
                                <th class="py-1">{{ __('Datum') }}</th>
                                <th class="py-1">{{ __('Betrag') }}</th>
                                <th class="py-1">{{ __('Status') }}</th>
                                <th class="py-1">{{ __('Notiz') }}</th>
                                <th class="py-1">{{ __('Beleg') }}</th>
                                <th class="py-1"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach ($claims as $claim)
                                <tr>
                                    <td class="py-1">
                                        <a href="{{ route('settlement-claims.edit', $claim) }}" class="hover:underline">{{ $claim->date->format('d.m.Y') }}</a>
                                    </td>
                                    <td class="py-1 whitespace-nowrap {{ $claim->amount < 0 ? 'text-red-600' : '' }}">{{ euro($claim->amount) }} €</td>
                                    <td class="py-1">{{ $claim->isOpen() ? __('Offen') : __('Gebucht') }}</td>
                                    <td class="py-1 text-gray-500">{{ $claim->notes }}</td>
                                    <td class="py-1"><x-receipt-link :record="$claim" route="files.settlement-claim" /></td>
                                    <td class="py-1">
                                        <form method="POST" action="{{ route('settlement-claims.destroy', $claim) }}" onsubmit="return confirm('{{ __('Forderung wirklich löschen?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:underline">{{ __('Entfernen') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @empty
        <p class="text-gray-500">{{ __('Kein Leerstand in diesem Abrechnungsintervall.') }}</p>
    @endforelse
</div>
