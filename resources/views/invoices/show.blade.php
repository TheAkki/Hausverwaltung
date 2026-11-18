<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Rechnung') }}: {{ $invoice->invoiceIssuer->fullName() }}
            </h2>
            <a href="{{ route('invoices.edit', $invoice) }}" class="text-sm text-gray-600 hover:underline">{{ __('Bearbeiten') }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-[1536px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('Rechnungsdatum') }}</dt>
                        <dd>{{ $invoice->invoice_date->format('d.m.Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Betrag') }}</dt>
                        <dd>{{ euro($invoice->amount) }} € @if ($invoice->effective_amount) <span class="text-gray-500">({{ __('wirksam') }}: {{ euro($invoice->effective_amount) }} €)</span>@endif</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Periodizität') }}</dt>
                        <dd>{{ $invoice->periodicityLabel() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Rechnungsnummer') }}</dt>
                        <dd>{{ $invoice->invoice_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Zugeordnet zu') }}</dt>
                        <dd>{{ $invoice->costableLabel() }}</dd>
                    </div>
                </dl>
                @if ($invoice->description)
                    <p class="text-sm text-gray-700 mt-4">{{ $invoice->description }}</p>
                @endif
                @include('invoices._receipt')
            </div>

            @include('invoices._costs')

            @if ($invoice->periodicity === 'manual' && $invoice->occurrences->isNotEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-3">{{ __('Termine') }}</h3>
                    <ul class="text-sm divide-y">
                        @foreach ($invoice->occurrences as $occurrence)
                            <li class="py-2 flex justify-between">
                                <span>{{ $occurrence->occurrence_date->format('d.m.Y') }}</span>
                                <span>{{ euro($occurrence->amount) }} €</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
