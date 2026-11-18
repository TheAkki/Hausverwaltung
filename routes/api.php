<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BillingIntervalController;
use App\Http\Controllers\Api\BillingTemplateController;
use App\Http\Controllers\Api\CostCategoryController;
use App\Http\Controllers\Api\CostController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupMembershipController;
use App\Http\Controllers\Api\InvoiceBookingController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceIssuerAccountController;
use App\Http\Controllers\Api\InvoiceIssuerController;
use App\Http\Controllers\Api\LandlordController;
use App\Http\Controllers\Api\LeaseController;
use App\Http\Controllers\Api\LeaseDocumentController;
use App\Http\Controllers\Api\LeaseIntervalController;
use App\Http\Controllers\Api\MaintenanceContractController;
use App\Http\Controllers\Api\ManagementGroupController;
use App\Http\Controllers\Api\ManagementUnitController;
use App\Http\Controllers\Api\OpenBookingController;
use App\Http\Controllers\Api\RentalUnitController;
use App\Http\Controllers\Api\RentPaymentBookingController;
use App\Http\Controllers\Api\RentPaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettlementClaimController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Alle Routennamen tragen das Präfix "api." – ohne dieses würden gleichnamige
 * apiResource()-Routen (z. B. "tenants.show") die gleichnamigen Web-Routen aus
 * routes/web.php überschreiben, sodass route()-Aufrufe in den Web-Controllern
 * (z. B. redirect()->route('tenants.show', $tenant)) auf den JSON-API-Endpunkt
 * statt auf die HTML-Seite verweisen würden.
 */
Route::name('api.')->group(function () {
    Route::post('/login', [AuthTokenController::class, 'store'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthTokenController::class, 'destroy'])->name('logout');
        Route::get('/user', fn (Request $request) => $request->user())->name('user');

        Route::apiResource('tenants', TenantController::class);
        Route::apiResource('leases', LeaseController::class);
        Route::apiResource('rental-units', RentalUnitController::class)->parameters(['rental-units' => 'rentalUnit']);
        Route::apiResource('management-groups', ManagementGroupController::class)->parameters(['management-groups' => 'managementGroup']);
        Route::apiResource('management-units', ManagementUnitController::class)->parameters(['management-units' => 'managementUnit']);
        Route::apiResource('invoices', InvoiceController::class);
        Route::post('/invoices/{invoice}/file', [InvoiceController::class, 'storeFile'])->name('invoices.file.store');
        Route::get('/invoices/{invoice}/file', [InvoiceController::class, 'showFile'])->name('invoices.file.show');
        Route::delete('/invoices/{invoice}/file', [InvoiceController::class, 'destroyFile'])->name('invoices.file.destroy');
        Route::post('/invoices/{invoice}/bookings', [InvoiceBookingController::class, 'store'])->name('invoices.bookings.store');
        Route::post('/invoices/{invoice}/series', [InvoiceController::class, 'extendSeries'])->name('invoices.series.store');

        Route::get('/open-bookings', [OpenBookingController::class, 'index'])->name('open-bookings.index');

        Route::post('/costs', [CostController::class, 'store'])->name('costs.store');
        Route::put('/costs/{cost}', [CostController::class, 'update'])->name('costs.update');
        Route::delete('/costs/{cost}', [CostController::class, 'destroy'])->name('costs.destroy');

        Route::get('/backup', [BackupController::class, 'export'])->name('backup.export');
        Route::get('/backup/full', [BackupController::class, 'exportFull'])->name('backup.export-full');
        Route::post('/backup', [BackupController::class, 'import'])->name('backup.import');

        Route::apiResource('users', UserController::class)->except(['destroy']);
        Route::apiResource('groups', GroupController::class);
        Route::post('/groups/{group}/members', [GroupMembershipController::class, 'store'])->name('groups.members.store');
        Route::patch('/groups/{group}/members/{user}', [GroupMembershipController::class, 'update'])->name('groups.members.update');
        Route::delete('/groups/{group}/members/{user}', [GroupMembershipController::class, 'destroy'])->name('groups.members.destroy');

        Route::apiResource('invoice-issuers', InvoiceIssuerController::class)->parameters(['invoice-issuers' => 'invoiceIssuer']);
        Route::post('/invoice-issuer-accounts', [InvoiceIssuerAccountController::class, 'store'])->name('invoice-issuer-accounts.store');
        Route::put('/invoice-issuer-accounts/{invoiceIssuerAccount}', [InvoiceIssuerAccountController::class, 'update'])->name('invoice-issuer-accounts.update');
        Route::delete('/invoice-issuer-accounts/{invoiceIssuerAccount}', [InvoiceIssuerAccountController::class, 'destroy'])->name('invoice-issuer-accounts.destroy');

        Route::get('/management-groups/{managementGroup}/billing-intervals', [BillingIntervalController::class, 'index'])->name('billing-intervals.index');
        Route::post('/billing-intervals', [BillingIntervalController::class, 'store'])->name('billing-intervals.store');
        Route::delete('/billing-intervals/{billingInterval}', [BillingIntervalController::class, 'destroy'])->name('billing-intervals.destroy');

        Route::get('/management-groups/{managementGroup}/landlord', [LandlordController::class, 'show'])->name('landlords.show');
        Route::put('/management-groups/{managementGroup}/landlord', [LandlordController::class, 'update'])->name('landlords.update');
        Route::put('/groups/{group}/landlord', [LandlordController::class, 'updateForGroup'])->name('groups.landlord.update');

        Route::apiResource('cost-categories', CostCategoryController::class)->except(['destroy'])->parameters(['cost-categories' => 'costCategory']);
        Route::put('/management-groups/{managementGroup}/cost-categories/{costCategory}', [CostCategoryController::class, 'allocationKey'])
            ->name('management-groups.cost-categories.update');

        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
        Route::post('/account-transfers', [AccountController::class, 'transfer'])->name('account-transfers.store');

        Route::post('/maintenance-contracts', [MaintenanceContractController::class, 'store'])->name('maintenance-contracts.store');
        Route::put('/maintenance-contracts/{maintenanceContract}', [MaintenanceContractController::class, 'update'])->name('maintenance-contracts.update');
        Route::delete('/maintenance-contracts/{maintenanceContract}', [MaintenanceContractController::class, 'destroy'])->name('maintenance-contracts.destroy');

        Route::post('/lease-intervals', [LeaseIntervalController::class, 'store'])->name('lease-intervals.store');
        Route::put('/lease-intervals/{leaseInterval}', [LeaseIntervalController::class, 'update'])->name('lease-intervals.update');
        Route::delete('/lease-intervals/{leaseInterval}', [LeaseIntervalController::class, 'destroy'])->name('lease-intervals.destroy');
        Route::get('/lease-intervals/{leaseInterval}/rent-payments', [RentPaymentController::class, 'index'])->name('rent-payments.index');
        Route::put('/rent-payments/{rentPayment}', [RentPaymentController::class, 'update'])->name('rent-payments.update');
        Route::post('/leases/{lease}/rent-payments/book', [RentPaymentBookingController::class, 'store'])->name('leases.rent-payments.book');

        Route::post('/leases/{lease}/documents', [LeaseDocumentController::class, 'store'])->name('leases.documents.store');
        Route::delete('/lease-documents/{leaseDocument}', [LeaseDocumentController::class, 'destroy'])->name('lease-documents.destroy');

        Route::post('/settlement-claims', [SettlementClaimController::class, 'store'])->name('settlement-claims.store');
        Route::put('/settlement-claims/{settlementClaim}', [SettlementClaimController::class, 'update'])->name('settlement-claims.update');
        Route::delete('/settlement-claims/{settlementClaim}', [SettlementClaimController::class, 'destroy'])->name('settlement-claims.destroy');

        Route::apiResource('billing-templates', BillingTemplateController::class)->parameters(['billing-templates' => 'billingTemplate']);
        Route::post('/billing-templates/{billingTemplate}/header-image', [BillingTemplateController::class, 'storeHeaderImage'])
            ->name('billing-templates.header-image.store');

        Route::get('/management-groups/{managementGroup}/report', [ReportController::class, 'financialOverview'])
            ->name('reports.financial-overview');
        Route::get('/management-units/{managementUnit}/billing-intervals/{billingInterval}/statement.pdf', [ReportController::class, 'statementPdf'])
            ->name('reports.statement-pdf');
        Route::get('/management-units/{managementUnit}/billing-intervals/{billingInterval}/statement.zip', [ReportController::class, 'statementZip'])
            ->name('reports.statement-zip');
        Route::get('/management-units/{managementUnit}/billing-intervals/{billingInterval}/leases/{lease}/statement.pdf', [ReportController::class, 'leaseStatementPdf'])
            ->name('reports.lease-statement-pdf');
    });
});
