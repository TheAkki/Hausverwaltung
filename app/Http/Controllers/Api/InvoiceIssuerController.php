<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoiceIssuer;
use App\Rules\ValidIban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API-CRUD für Rechnungssteller, gespiegelt von InvoiceIssuerController.
 */
class InvoiceIssuerController extends Controller
{
    private const PER_PAGE = 20;

    private const ADDRESS_FIELDS = ['street', 'postal_code', 'city'];

    private const EMPTY_ADDRESS = '';

    public function __construct()
    {
        $this->authorizeResource(InvoiceIssuer::class, 'invoiceIssuer');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            InvoiceIssuer::visibleTo($request->user())->with('accounts')->orderBy('name')->paginate(self::PER_PAGE)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        return response()->json(InvoiceIssuer::create($validated), 201);
    }

    public function show(InvoiceIssuer $invoiceIssuer): JsonResponse
    {
        return response()->json($invoiceIssuer->load('accounts'));
    }

    public function update(Request $request, InvoiceIssuer $invoiceIssuer): JsonResponse
    {
        $validated = $this->validated($request);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $invoiceIssuer->update($validated);

        return response()->json($invoiceIssuer);
    }

    public function destroy(InvoiceIssuer $invoiceIssuer): JsonResponse
    {
        $invoiceIssuer->delete();

        return response()->json(status: 204);
    }

    /**
     * Die Adresse ist optional (z. B. Bank, Versicherung – wie im Seeder); die
     * Spalten sind NOT NULL, fehlende Werte werden daher als "" gespeichert.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'user_group_id' => ['required', 'exists:groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'salutation' => ['nullable', 'in:Herr,Frau'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile_phone' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34', new ValidIban],
            'bic' => ['nullable', 'string', 'max:11'],
            'comment' => ['nullable', 'string'],
        ]);

        foreach (self::ADDRESS_FIELDS as $field) {
            $validated[$field] ??= self::EMPTY_ADDRESS;
        }

        return $validated;
    }
}
