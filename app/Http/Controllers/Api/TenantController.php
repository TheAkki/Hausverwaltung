<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Rules\ValidIban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API-CRUD für Mieter (Konzept.md "## API"), gespiegelt von TenantController.
 */
class TenantController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Tenant::class, 'tenant');
    }

    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::visibleTo($request->user())->orderBy('last_name')->paginate(20);

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $tenant = Tenant::create($validated);

        return response()->json($tenant, 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load('leases.rentalUnit.managementUnit');

        return response()->json($tenant);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $this->validated($request);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $tenant->update($validated);

        return response()->json($tenant);
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $tenant->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
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
    }
}
