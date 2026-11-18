<?php

// KI-generiert

namespace Tests\Feature\Api;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/user liefert die persönliche Gruppe mit, damit ein Client ihren Namen
 * nicht erraten muss (Konzept.md "## API": "Benutzer Eigenschaften auslesen").
 */
class CurrentUserControllerTest extends TestCase
{
    use RefreshDatabase;

    // KI-generiert
    public function test_the_personal_group_is_part_of_the_current_user(): void
    {
        $user = User::factory()->create(['name' => 'Anna']);
        Sanctum::actingAs($user);

        $this->getJson(route('api.user'))
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('personal_group.id', $user->personalGroup()->id)
            ->assertJsonPath('personal_group.name', 'Gruppe von Anna')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');
    }

    // KI-generiert
    public function test_a_renamed_personal_group_is_reported_with_its_current_name(): void
    {
        $user = User::factory()->create(['name' => 'Anna']);
        $user->personalGroup()->update(['name' => 'Annas Häuser']);
        Sanctum::actingAs($user);

        $this->getJson(route('api.user'))->assertJsonPath('personal_group.name', 'Annas Häuser');
    }

    // KI-generiert
    public function test_shared_groups_are_not_mistaken_for_the_personal_group(): void
    {
        $user = User::factory()->create(['name' => 'Anna']);
        $owner = User::factory()->create();
        Group::create(['name' => 'Fremde GbR', 'owner_id' => $owner->id])->users()->attach($user->id, ['role' => 'admin']);
        Sanctum::actingAs($user);

        $this->getJson(route('api.user'))->assertJsonPath('personal_group.name', 'Gruppe von Anna');
    }

    // KI-generiert
    public function test_without_a_personal_group_the_field_is_null(): void
    {
        $user = User::factory()->create();
        $user->personalGroup()->delete();
        Sanctum::actingAs($user);

        $this->getJson(route('api.user'))->assertOk()->assertJsonPath('personal_group', null);
    }

    // KI-generiert
    public function test_it_requires_authentication(): void
    {
        $this->getJson(route('api.user'))->assertUnauthorized();
    }
}
