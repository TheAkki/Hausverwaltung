<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use Database\Seeders\ThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CRUD for custom UI Themes, and that built-in themes (Standard/Matrix/Norton
 * Commander) are protected from editing and deletion.
 */
class ThemeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_lists_built_in_themes(): void
    {
        $this->seed(ThemeSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('themes.index'));

        $response->assertOk();
        $response->assertSee('Matrix');
        $response->assertSee('Norton Commander');
    }

    public function test_the_create_form_lists_known_css_selectors_from_the_matrix_theme(): void
    {
        $this->seed(ThemeSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('themes.create'));

        $response->assertOk();
        $response->assertSee('data-selector="body"', false);
        $response->assertSee('data-selector=".bg-white', false);
    }

    public function test_known_selectors_returns_an_empty_array_without_a_matrix_theme(): void
    {
        $this->assertSame([], Theme::knownSelectors());
    }

    public function test_a_custom_theme_can_be_created(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('themes.store'), [
            'name' => 'Mein Theme',
            'slug' => 'mein-theme',
            'css' => 'body { color: red; }',
        ]);

        $theme = Theme::where('slug', 'mein-theme')->first();
        $this->assertNotNull($theme);
        $response->assertRedirect(route('themes.index'));
        $this->assertFalse($theme->is_builtin);
    }

    public function test_creating_a_theme_with_a_duplicate_slug_fails_validation(): void
    {
        $this->seed(ThemeSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('themes.store'), [
            'name' => 'Zweites Matrix',
            'slug' => 'matrix',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_a_custom_theme_can_be_updated(): void
    {
        $user = User::factory()->create();
        $theme = Theme::create(['name' => 'Mein Theme', 'slug' => 'mein-theme', 'is_builtin' => false]);

        $response = $this->actingAs($user)->put(route('themes.update', $theme), [
            'name' => 'Umbenannt',
            'css' => 'body { color: blue; }',
        ]);

        $response->assertRedirect(route('themes.index'));
        $this->assertSame('Umbenannt', $theme->fresh()->name);
    }

    public function test_a_built_in_theme_cannot_be_edited(): void
    {
        $this->seed(ThemeSeeder::class);
        $user = User::factory()->create();
        $matrix = Theme::where('slug', 'matrix')->first();

        $this->actingAs($user)->get(route('themes.edit', $matrix))->assertForbidden();
        $this->actingAs($user)->put(route('themes.update', $matrix), ['name' => 'Hijacked'])->assertForbidden();
        $this->assertSame('Matrix', $matrix->fresh()->name);
    }

    public function test_a_custom_theme_can_be_deleted_and_its_users_fall_back(): void
    {
        $theme = Theme::create(['name' => 'Mein Theme', 'slug' => 'mein-theme', 'is_builtin' => false]);
        $user = User::factory()->create(['theme_id' => $theme->id]);

        $response = $this->actingAs($user)->delete(route('themes.destroy', $theme));

        $response->assertRedirect(route('themes.index'));
        $this->assertNull(Theme::find($theme->id));
        $this->assertNull($user->fresh()->theme_id);
    }

    public function test_a_built_in_theme_cannot_be_deleted(): void
    {
        $this->seed(ThemeSeeder::class);
        $user = User::factory()->create();
        $standard = Theme::where('slug', 'standard')->first();

        $response = $this->actingAs($user)->delete(route('themes.destroy', $standard));

        $response->assertForbidden();
        $this->assertNotNull(Theme::find($standard->id));
    }
}
