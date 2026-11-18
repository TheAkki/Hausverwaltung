<?php

namespace App\Http\Controllers;

use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD for Kundenaccounts (customer accounts) belonging to a Rechnungssteller (e.g.
 * separate accounts for electricity, water, district heating under the same issuer).
 */
class InvoiceIssuerAccountController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $invoiceIssuer = InvoiceIssuer::visibleTo($request->user())
            ->findOrFail($request->query('invoice_issuer_id'));

        return view('invoice-issuer-accounts.create', compact('invoiceIssuer'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_issuer_id' => ['required', 'exists:invoice_issuers,id'],
            'customer_number' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'direct_debit' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $invoiceIssuer = InvoiceIssuer::visibleTo($request->user())->findOrFail($validated['invoice_issuer_id']);

        $validated['direct_debit'] = $request->boolean('direct_debit');

        $invoiceIssuer->accounts()->create(collect($validated)->except('invoice_issuer_id')->all());

        return redirect()->route('invoice-issuers.show', $invoiceIssuer);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(InvoiceIssuerAccount $invoiceIssuerAccount): View
    {
        $this->authorize('update', $invoiceIssuerAccount->invoiceIssuer);

        return view('invoice-issuer-accounts.edit', compact('invoiceIssuerAccount'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InvoiceIssuerAccount $invoiceIssuerAccount): RedirectResponse
    {
        $this->authorize('update', $invoiceIssuerAccount->invoiceIssuer);

        $validated = $request->validate([
            'customer_number' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'direct_debit' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $validated['direct_debit'] = $request->boolean('direct_debit');

        $invoiceIssuerAccount->update($validated);

        return redirect()->route('invoice-issuers.show', $invoiceIssuerAccount->invoiceIssuer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InvoiceIssuerAccount $invoiceIssuerAccount): RedirectResponse
    {
        $this->authorize('update', $invoiceIssuerAccount->invoiceIssuer);

        $invoiceIssuer = $invoiceIssuerAccount->invoiceIssuer;

        $invoiceIssuerAccount->delete();

        return redirect()->route('invoice-issuers.show', $invoiceIssuer);
    }
}
