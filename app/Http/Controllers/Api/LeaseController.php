<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\RentalUnit;
use App\Rules\DateAfterField;
use App\Rules\NotOverlappingLease;
use App\Rules\PreContractRequiresFollowingLease;
use App\Rules\ValidLeaseCoupling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * REST-API-CRUD für Mietverträge (Konzept.md "## API"), gespiegelt von
 * LeaseController – inklusive derselben Überlappungs-/Vorvertrags-/Kopplungs-
 * validierung (siehe dortige Rule-Klassen).
 */
class LeaseController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Lease::class, 'lease');
    }

    public function index(Request $request): JsonResponse
    {
        $showExpired = $request->boolean('show_expired');

        $leases = Lease::whereHas(
            'rentalUnit.managementUnit.managementGroup',
            fn ($q) => $q->visibleTo($request->user())
        )->with('tenants', 'rentalUnit.managementUnit')->get();

        if (! $showExpired) {
            $leases = $leases->reject->isExpired()->values();
        }

        return response()->json($leases);
    }

    public function store(Request $request): JsonResponse
    {
        $assignmentMode = $request->input('assignment_mode', 'tenants');

        $validated = $request->validate([
            'rental_unit_id' => ['required', 'exists:rental_units,id'],
            'assignment_mode' => ['nullable', 'in:tenants,reference'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
            'is_pre_contract' => ['boolean'],
            'rent_adjustment_type' => ['required', 'in:fixed,stepped,indexed'],
            'cold_rent' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'min_term_months' => ['nullable', 'integer', 'min:0'],
            'notice_period' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            ...($assignmentMode === 'reference' ? [
                'coupled_to_lease_id' => ['required', 'integer', 'exists:leases,id'],
            ] : [
                'tenant_ids' => ['required', 'array', 'min:1'],
                'tenant_ids.*' => [Rule::exists('tenants', 'id')->whereIn('user_group_id', $request->user()->groups->pluck('id'))],
            ]),
        ]);

        $rentalUnit = RentalUnit::findOrFail($validated['rental_unit_id']);
        $this->authorize('view', $rentalUnit);

        $request->validate([
            'start_date' => [new NotOverlappingLease(
                $validated['rental_unit_id'],
                $validated['start_date'],
                $validated['end_date'] ?? null,
            )],
            'is_pre_contract' => [new PreContractRequiresFollowingLease(
                (int) $validated['rental_unit_id'],
                $validated['start_date'],
            )],
            ...($assignmentMode === 'reference' ? [
                'coupled_to_lease_id' => [new ValidLeaseCoupling(
                    (int) $validated['rental_unit_id'],
                    $validated['end_date'] ?? null,
                )],
            ] : []),
        ]);

        $attributes = collect($validated)->except(['tenant_ids', 'assignment_mode', 'coupled_to_lease_id'])->all();

        if ($assignmentMode === 'reference') {
            $targetLease = Lease::findOrFail($validated['coupled_to_lease_id']);
            $attributes['coupled_to_lease_id'] = $targetLease->id;
            $tenantIds = $targetLease->tenants()->pluck('tenants.id')->all();
        } else {
            $tenantIds = $validated['tenant_ids'];
        }

        $lease = Lease::create($attributes);
        $lease->tenants()->attach($tenantIds);

        return response()->json($lease, 201);
    }

    public function show(Lease $lease): JsonResponse
    {
        $lease->load('tenants', 'rentalUnit.managementUnit.managementGroup', 'leaseIntervals', 'documents', 'coupledToLease.rentalUnit', 'coupledToLease.tenants', 'settlementClaims');

        return response()->json($lease);
    }

    public function update(Request $request, Lease $lease): JsonResponse
    {
        $currentInterval = $lease->currentLeaseInterval();

        $validated = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1'],
            'tenant_ids.*' => [Rule::exists('tenants', 'id')->whereIn('user_group_id', $request->user()->groups->pluck('id'))],
            'start_date' => ['required', 'date'],
            'end_date' => [
                'nullable', 'date',
                new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.'),
                ...($currentInterval !== null ? [new DateAfterField(
                    $currentInterval->start_date->toDateString(),
                    'Das Enddatum darf nicht vor dem Beginn des aktuellen Mietintervalls liegen.',
                    orEqual: true,
                )] : []),
            ],
            'is_pre_contract' => ['boolean'],
            'rent_adjustment_type' => ['required', 'in:fixed,stepped,indexed'],
            'cold_rent' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'min_term_months' => ['nullable', 'integer', 'min:0'],
            'notice_period' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $request->validate([
            'start_date' => [new NotOverlappingLease(
                $lease->rental_unit_id,
                $validated['start_date'],
                $validated['end_date'] ?? null,
                $lease->id,
            )],
            'is_pre_contract' => [new PreContractRequiresFollowingLease(
                $lease->rental_unit_id,
                $validated['start_date'],
                $lease->id,
            )],
        ]);

        $lease->update(collect($validated)->except('tenant_ids')
            ->merge(['is_pre_contract' => $request->boolean('is_pre_contract')])->all());
        $lease->tenants()->sync($validated['tenant_ids']);

        return response()->json($lease);
    }

    public function destroy(Lease $lease): JsonResponse
    {
        $lease->delete();

        return response()->json(status: 204);
    }
}
