<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a selectable UI theme (built-in or custom) with optional CSS overrides,
 * used by many Users.
 */
#[Fillable(['name', 'slug', 'css', 'is_builtin'])]
class Theme extends Model
{
    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The distinct CSS selectors used by the built-in "Matrix" theme, treated as the
     * canonical list of "known" customizable UI elements. Shown as a checklist when
     * authoring a custom theme, so a theme created before new selectors were added
     * elsewhere in the app visibly shows which ones it doesn't cover yet.
     *
     * @return array<int, string>
     */
    public static function knownSelectors(): array
    {
        $reference = static::where('slug', 'matrix')->value('css') ?? '';

        preg_match_all('/([^{}]+)\{/', $reference, $matches);

        $selectors = [];
        foreach ($matches[1] as $group) {
            foreach (explode(',', $group) as $selector) {
                $selector = trim($selector);
                if ($selector !== '') {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }
}
