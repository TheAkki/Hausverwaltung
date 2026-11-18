<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Main database seeder: orders the reference-data seeders, creates a default test
 * user and delegates to TestDataSeeder for optional fixture data.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CostCategorySeeder::class);
        $this->call(BillingTemplateSeeder::class);
        $this->call(ThemeSeeder::class);
        $this->call(AdminUserSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(TestDataSeeder::class);
    }
}
