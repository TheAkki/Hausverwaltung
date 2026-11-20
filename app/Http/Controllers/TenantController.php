<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Rules\ValidIban;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manages CRUD operations for tenants, including their leases.
 */
class TenantController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Tenant::class, 'tenant');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $tenants = Tenant::visibleTo($request->user())->orderBy('last_name')->paginate(20);

        return view('tenants.index', compact('tenants'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $userGroups = $request->user()->groups;

        return view('tenants.create', compact('userGroups'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_group_id' => ['required', 'exists:groups,id'],
            'salutation' => ['nullable', 'in:Herr,Frau'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'mobile_phone' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34', new ValidIban],
            'bic' => ['nullable', 'string', 'max:11'],
            'notes' => ['nullable', 'string'],
        ]);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $tenant = Tenant::create($validated);

        return redirect()->route('tenants.show', $tenant);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Tenant $tenant): View
    {
        // Nur Mietverträge, die der Betrachter sehen darf – ein Mieter kann (Altdaten)
        // auch in Verwaltungsgruppen anderer Gruppen vorkommen.
        $tenant->load([
            'leases' => fn ($query) => $query->visibleTo($request->user()),
            'leases.rentalUnit.managementUnit',
        ]);

        return view('tenants.show', compact('tenant'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Tenant $tenant): View
    {
        $userGroups = $request->user()->groups;

        return view('tenants.edit', compact('tenant', 'userGroups'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'user_group_id' => ['required', 'exists:groups,id'],
            'salutation' => ['nullable', 'in:Herr,Frau'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'mobile_phone' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34', new ValidIban],
            'bic' => ['nullable', 'string', 'max:11'],
            'notes' => ['nullable', 'string'],
        ]);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $tenant->update($validated);

        return redirect()->route('tenants.show', $tenant);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tenant $tenant): RedirectResponse
    {
        $tenant->delete();

        return redirect()->route('tenants.index');
    }
}
