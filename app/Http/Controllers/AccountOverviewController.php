<?php

namespace App\Http\Controllers;

use App\Models\ManagementGroup;
use App\Services\FinancialOverviewService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Displays the Kontoübersicht: monthly balances per Konto of a ManagementGroup, only
 * accessible when that group's Benutzergruppe has the Kontensystem enabled.
 */
class AccountOverviewController extends Controller
{
    public function show(Request $request, ManagementGroup $managementGroup, FinancialOverviewService $overview): View
    {
        $this->authorize('view', $managementGroup);
        abort_unless($managementGroup->kontosystemEnabled(), 404);

        $year = (int) $request->query('year', now()->year);

        $managementGroup->load('accounts');
        $balances = $overview->accountBalances($managementGroup, $year);

        return view('accounts.overview', compact('managementGroup', 'year', 'balances'));
    }
}
