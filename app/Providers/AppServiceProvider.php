<?php

namespace App\Providers;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\PersonalAccessToken;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CanonicalUrl;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Registers and bootstraps application-level services, including the polymorphic
 * morph map used for costable relations (ManagementGroup, ManagementUnit, RentalUnit,
 * Lease – the latter only for "individuell" abgerechnete Kostenzuweisungen).
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Konzept.md "#### Template" c): absoluter Beginn des Inhalts auf der ersten
        // Seite eines Briefs, z. B. @contentStart('75mm') – siehe ContentStart.
        Blade::directive('contentStart', fn (string $expression) => "<?php echo \\App\\Services\\ContentStart::marker({$expression}); ?>");

        Relation::enforceMorphMap([
            'management_group' => ManagementGroup::class,
            'management_unit' => ManagementUnit::class,
            'rental_unit' => RentalUnit::class,
            'lease' => Lease::class,
            // Sanctum's personal_access_tokens.tokenable (REST-API-Login).
            'user' => User::class,
        ]);

        // Letzte Token-Nutzung nur minutengenau speichern (siehe PersonalAccessToken).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Links und Weiterleitungen aus APP_URL bilden (Docker, siehe CanonicalUrl).
        $this->app->make(CanonicalUrl::class)->apply();
    }
}
