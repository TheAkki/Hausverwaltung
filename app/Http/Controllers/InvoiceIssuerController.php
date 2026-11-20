<?php

namespace App\Http\Controllers;

use App\Http\Requests\CopyInvoiceIssuerRequest;
use App\Models\InvoiceIssuer;
use App\Rules\ValidIban;
use App\Services\GroupOwnership\MasterDataCopier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD for Rechnungssteller (invoice issuers).
 */
class InvoiceIssuerController extends Controller
{
    /** Session-Schlüssel der Meldung nach „In andere Gruppe kopieren“. */
    public const COPY_STATUS = 'invoice_issuer_copy';

    public function __construct()
    {
        $this->authorizeResource(InvoiceIssuer::class, 'invoiceIssuer');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $invoiceIssuers = InvoiceIssuer::visibleTo($request->user())->orderBy('name')->paginate(20);

        return view('invoice-issuers.index', compact('invoiceIssuers'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $userGroups = $request->user()->groups;

        return view('invoice-issuers.create', compact('userGroups'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_group_id' => ['required', 'exists:groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'salutation' => ['nullable', 'in:Herr,Frau'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile_phone' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34', new ValidIban],
            'bic' => ['nullable', 'string', 'max:11'],
            'comment' => ['nullable', 'string'],
        ]);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $invoiceIssuer = InvoiceIssuer::create($validated);

        return redirect()->route('invoice-issuers.show', $invoiceIssuer);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, InvoiceIssuer $invoiceIssuer): View
    {
        // Nur Rechnungen, die der Betrachter sehen darf (sonst verriete die Liste
        // Rechnungen anderer Gruppen); Rechnungen ohne Zuordnung gehören zur Gruppe des
        // Rechnungsstellers selbst.
        $invoiceIssuer->load([
            'invoices' => fn ($query) => $query->where(
                fn ($visible) => $visible->visibleTo($request->user())->orWhereNull('costable_type')
            ),
            'accounts',
        ]);

        $copyTargets = $request->user()->groups->where('id', '!=', $invoiceIssuer->user_group_id)->values();

        return view('invoice-issuers.show', compact('invoiceIssuer', 'copyTargets'));
    }

    /**
     * „In andere Gruppe kopieren“: legt den Rechnungssteller samt Kundenaccounts in
     * der gewählten Gruppe an – oder ergänzt einen gleichen, der dort schon existiert
     * (MasterDataCopier). Zum Beispiel für dieselben Stadtwerke in der persönlichen
     * und einer gemeinsamen Gruppe.
     */
    public function copy(CopyInvoiceIssuerRequest $request, InvoiceIssuer $invoiceIssuer, MasterDataCopier $copier): RedirectResponse
    {
        [$target, $created] = $copier->copyTo($invoiceIssuer, $request->targetGroupId());

        return redirect()->route('invoice-issuers.show', $target)->with(self::COPY_STATUS, $created
            ? __('Der Rechnungssteller wurde mit seinen Kundenaccounts in diese Gruppe kopiert.')
            : __('Der Rechnungssteller existierte in dieser Gruppe schon; fehlende Kundenaccounts wurden ergänzt.'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, InvoiceIssuer $invoiceIssuer): View
    {
        $userGroups = $request->user()->groups;

        return view('invoice-issuers.edit', compact('invoiceIssuer', 'userGroups'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InvoiceIssuer $invoiceIssuer): RedirectResponse
    {
        $validated = $request->validate([
            'user_group_id' => ['required', 'exists:groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'salutation' => ['nullable', 'in:Herr,Frau'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile_phone' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34', new ValidIban],
            'bic' => ['nullable', 'string', 'max:11'],
            'comment' => ['nullable', 'string'],
        ]);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $invoiceIssuer->update($validated);

        return redirect()->route('invoice-issuers.show', $invoiceIssuer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InvoiceIssuer $invoiceIssuer): RedirectResponse
    {
        $invoiceIssuer->delete();

        return redirect()->route('invoice-issuers.index');
    }
}
