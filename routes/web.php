<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountOverviewController;
use App\Http\Controllers\AccountTransferController;
use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BillingIntervalController;
use App\Http\Controllers\BillingStatementController;
use App\Http\Controllers\BillingTemplateController;
use App\Http\Controllers\CostCategoryController;
use App\Http\Controllers\CostController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupMembershipController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceIssuerAccountController;
use App\Http\Controllers\InvoiceIssuerController;
use App\Http\Controllers\LandlordController;
use App\Http\Controllers\LeaseController;
use App\Http\Controllers\LeaseDocumentController;
use App\Http\Controllers\LeaseIntervalController;
use App\Http\Controllers\MaintenanceContractController;
use App\Http\Controllers\ManagementGroupController;
use App\Http\Controllers\ManagementGroupCostCategoryController;
use App\Http\Controllers\ManagementUnitController;
use App\Http\Controllers\OpenPaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalUnitController;
use App\Http\Controllers\RentPaymentController;
use App\Http\Controllers\SettlementClaimController;
use App\Http\Controllers\TaxOverviewController;
use App\Http\Controllers\TemplateHelpController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, SetLocale::SUPPORTED_LOCALES, true), 404);

    session(['locale' => $locale]);

    return back();
})->name('locale.switch');

Route::get('/api-docs', [ApiDocumentationController::class, 'show'])->name('api-docs');
Route::get('/api-docs/openapi.yaml', [ApiDocumentationController::class, 'spec'])->name('api-docs.spec');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/theme', [ProfileController::class, 'updateTheme'])->name('profile.theme.update');
    Route::post('/profile/api-tokens', [ApiTokenController::class, 'store'])->name('profile.api-tokens.store');
    Route::delete('/profile/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])
        ->whereNumber('tokenId')->name('profile.api-tokens.destroy');
    Route::get('/help', [HelpController::class, 'show'])->name('help');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/backup/export', [BackupController::class, 'export'])->name('backup.export');
    Route::get('/backup/export-full', [BackupController::class, 'exportFull'])->name('backup.export-full');
    Route::post('/backup/import', [BackupController::class, 'import'])->name('backup.import');
    Route::get('/open-payments', [OpenPaymentController::class, 'index'])->name('open-payments.index');

    Route::resource('users', UserController::class)->except(['destroy']);

    Route::resource('groups', GroupController::class);
    Route::post('/groups/{group}/members', [GroupMembershipController::class, 'store'])->name('groups.members.store');
    Route::patch('/groups/{group}/members/{user}', [GroupMembershipController::class, 'update'])->name('groups.members.update');
    Route::delete('/groups/{group}/members/{user}', [GroupMembershipController::class, 'destroy'])->name('groups.members.destroy');

    Route::resource('management-groups', ManagementGroupController::class)
        ->parameters(['management-groups' => 'managementGroup']);
    Route::resource('management-units', ManagementUnitController::class)
        ->parameters(['management-units' => 'managementUnit']);
    Route::resource('rental-units', RentalUnitController::class)
        ->parameters(['rental-units' => 'rentalUnit']);
    Route::resource('tenants', TenantController::class);
    Route::resource('leases', LeaseController::class);
    Route::post('/leases/{lease}/documents', [LeaseDocumentController::class, 'store'])->name('leases.documents.store');
    Route::delete('/lease-documents/{leaseDocument}', [LeaseDocumentController::class, 'destroy'])->name('lease-documents.destroy');

    Route::get('/lease-intervals/create', [LeaseIntervalController::class, 'create'])->name('lease-intervals.create');
    Route::post('/lease-intervals', [LeaseIntervalController::class, 'store'])->name('lease-intervals.store');
    Route::get('/lease-intervals/{leaseInterval}/edit', [LeaseIntervalController::class, 'edit'])->name('lease-intervals.edit');
    Route::put('/lease-intervals/{leaseInterval}', [LeaseIntervalController::class, 'update'])->name('lease-intervals.update');
    Route::delete('/lease-intervals/{leaseInterval}', [LeaseIntervalController::class, 'destroy'])->name('lease-intervals.destroy');

    Route::put('/rent-payments/{rentPayment}', [RentPaymentController::class, 'update'])->name('rent-payments.update');

    Route::get('/settlement-claims/create', [SettlementClaimController::class, 'create'])->name('settlement-claims.create');
    Route::post('/settlement-claims', [SettlementClaimController::class, 'store'])->name('settlement-claims.store');
    Route::get('/settlement-claims/{settlementClaim}/edit', [SettlementClaimController::class, 'edit'])->name('settlement-claims.edit');
    Route::put('/settlement-claims/{settlementClaim}', [SettlementClaimController::class, 'update'])->name('settlement-claims.update');
    Route::delete('/settlement-claims/{settlementClaim}', [SettlementClaimController::class, 'destroy'])->name('settlement-claims.destroy');

    Route::get('/billing-intervals/create', [BillingIntervalController::class, 'create'])->name('billing-intervals.create');
    Route::post('/billing-intervals', [BillingIntervalController::class, 'store'])->name('billing-intervals.store');

    Route::resource('invoice-issuers', InvoiceIssuerController::class)
        ->parameters(['invoice-issuers' => 'invoiceIssuer']);

    Route::get('/invoice-issuer-accounts/create', [InvoiceIssuerAccountController::class, 'create'])->name('invoice-issuer-accounts.create');
    Route::post('/invoice-issuer-accounts', [InvoiceIssuerAccountController::class, 'store'])->name('invoice-issuer-accounts.store');
    Route::get('/invoice-issuer-accounts/{invoiceIssuerAccount}/edit', [InvoiceIssuerAccountController::class, 'edit'])->name('invoice-issuer-accounts.edit');
    Route::put('/invoice-issuer-accounts/{invoiceIssuerAccount}', [InvoiceIssuerAccountController::class, 'update'])->name('invoice-issuer-accounts.update');
    Route::delete('/invoice-issuer-accounts/{invoiceIssuerAccount}', [InvoiceIssuerAccountController::class, 'destroy'])->name('invoice-issuer-accounts.destroy');

    Route::resource('invoices', InvoiceController::class);
    Route::post('/invoices/{invoice}/complete-series', [InvoiceController::class, 'completeSeries'])->name('invoices.complete-series');

    Route::post('/costs', [CostController::class, 'store'])->name('costs.store');
    Route::put('/costs/{cost}', [CostController::class, 'update'])->name('costs.update');
    Route::delete('/costs/{cost}', [CostController::class, 'destroy'])->name('costs.destroy');
    Route::post('/costs/{cost}/split', [CostController::class, 'split'])->name('costs.split');

    Route::get('/cost-categories', [CostCategoryController::class, 'index'])->name('cost-categories.index');
    Route::get('/cost-categories/create', [CostCategoryController::class, 'create'])->name('cost-categories.create');
    Route::post('/cost-categories', [CostCategoryController::class, 'store'])->name('cost-categories.store');
    Route::patch('/cost-categories/{costCategory}', [CostCategoryController::class, 'update'])->name('cost-categories.update');

    Route::patch(
        '/management-groups/{managementGroup}/cost-categories/{costCategory}',
        [ManagementGroupCostCategoryController::class, 'update']
    )->name('management-groups.cost-categories.update');

    Route::get('/management-groups/{managementGroup}/landlord/edit', [LandlordController::class, 'edit'])
        ->name('management-groups.landlord.edit');
    Route::put('/management-groups/{managementGroup}/landlord', [LandlordController::class, 'update'])
        ->name('management-groups.landlord.update');

    Route::get('/management-groups/{managementGroup}/tax-overview', [TaxOverviewController::class, 'show'])
        ->name('management-groups.tax-overview');

    Route::get('/maintenance-contracts/create', [MaintenanceContractController::class, 'create'])->name('maintenance-contracts.create');
    Route::post('/maintenance-contracts', [MaintenanceContractController::class, 'store'])->name('maintenance-contracts.store');
    Route::get('/maintenance-contracts/{maintenanceContract}/edit', [MaintenanceContractController::class, 'edit'])->name('maintenance-contracts.edit');
    Route::put('/maintenance-contracts/{maintenanceContract}', [MaintenanceContractController::class, 'update'])->name('maintenance-contracts.update');
    Route::delete('/maintenance-contracts/{maintenanceContract}', [MaintenanceContractController::class, 'destroy'])->name('maintenance-contracts.destroy');

    Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    Route::post('/account-transfers', [AccountTransferController::class, 'store'])->name('account-transfers.store');
    Route::get('/management-groups/{managementGroup}/account-overview', [AccountOverviewController::class, 'show'])->name('account-overview.show');

    // Must be registered before the billing-templates resource below, otherwise
    // "/billing-templates/help" would be swallowed by the resource's "{billingTemplate}"
    // show route (tried as a route-model-binding lookup and 404ing) instead of reaching
    // these routes.
    Route::get('/billing-templates/help', [TemplateHelpController::class, 'show'])->name('billing-templates.help');
    Route::get('/billing-templates/help/preview', [TemplateHelpController::class, 'preview'])->name('billing-templates.help.preview');

    Route::resource('billing-templates', BillingTemplateController::class)
        ->parameters(['billing-templates' => 'billingTemplate']);
    Route::post('/billing-templates/{billingTemplate}/duplicate', [BillingTemplateController::class, 'duplicate'])
        ->name('billing-templates.duplicate');
    // The edit form spoofs PUT (@method('PUT')), which applies to every button inside
    // it regardless of its own formaction – so this must accept PUT, not just POST.
    Route::match(['post', 'put'], '/billing-templates/{billingTemplate}/preview', [BillingTemplateController::class, 'preview'])
        ->name('billing-templates.preview');
    Route::match(['post', 'put'], '/billing-templates/{billingTemplate}/check', [BillingTemplateController::class, 'check'])
        ->name('billing-templates.check');

    Route::get(
        '/management-units/{managementUnit}/billing-intervals/{billingInterval}/statement',
        [BillingStatementController::class, 'show']
    )->name('billing-statements.show');
    Route::get(
        '/management-units/{managementUnit}/billing-intervals/{billingInterval}/statement/pdf',
        [BillingStatementController::class, 'pdf']
    )->name('billing-statements.pdf');
    Route::get(
        '/management-units/{managementUnit}/billing-intervals/{billingInterval}/statement/zip',
        [BillingStatementController::class, 'zip']
    )->name('billing-statements.zip');
    Route::get(
        '/management-units/{managementUnit}/billing-intervals/{billingInterval}/statement/lease/{lease}',
        [BillingStatementController::class, 'showLease']
    )->name('billing-statements.show-lease');
    Route::get(
        '/management-units/{managementUnit}/billing-intervals/{billingInterval}/statement/lease/{lease}/pdf',
        [BillingStatementController::class, 'pdfLease']
    )->name('billing-statements.pdf-lease');

    Route::resource('themes', ThemeController::class)->except(['show']);
});

require __DIR__.'/auth.php';
