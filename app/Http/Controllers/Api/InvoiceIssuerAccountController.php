<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API für Kundenaccounts eines Rechnungsstellers, gespiegelt von
 * InvoiceIssuerAccountController.
 */
class InvoiceIssuerAccountController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_issuer_id' => ['required', 'exists:invoice_issuers,id'],
            ...$this->rules(),
        ]);

        $invoiceIssuer = InvoiceIssuer::visibleTo($request->user())->findOrFail($validated['invoice_issuer_id']);
        $validated['direct_debit'] = $request->boolean('direct_debit');

        $account = $invoiceIssuer->accounts()->create(collect($validated)->except('invoice_issuer_id')->all());

        return response()->json($account, 201);
    }

    public function update(Request $request, InvoiceIssuerAccount $invoiceIssuerAccount): JsonResponse
    {
        $this->authorize('update', $invoiceIssuerAccount->invoiceIssuer);

        $validated = $request->validate($this->rules());
        $validated['direct_debit'] = $request->boolean('direct_debit');

        $invoiceIssuerAccount->update($validated);

        return response()->json($invoiceIssuerAccount);
    }

    public function destroy(InvoiceIssuerAccount $invoiceIssuerAccount): JsonResponse
    {
        $this->authorize('update', $invoiceIssuerAccount->invoiceIssuer);

        $invoiceIssuerAccount->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'customer_number' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'direct_debit' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
