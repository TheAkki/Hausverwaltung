<?php

namespace App\Models;

use App\Observers\CostCategoryObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Represents a Betriebskosten category (e.g. Heizkosten, Wasserversorgung) that can be
 * linked to management groups with a per-group allocation key. `user_group_id` is null
 * for the built-in/"systemweite" categories (CostCategorySeeder, visible to everyone);
 * self-created categories are assigned to one of the creating user's Benutzergruppen.
 *
 * A category's `name` can also encode an unbounded chain of Unterkategorien, each
 * segment joined by SEPARATOR (e.g. "Sonstige Betriebskosten::Hausmeister::Winterdienst")
 * – see Konzept.md "### Kosten". Whether such a subcategory counts as
 * abrechnungs-/steuerrelevant is always determined by its Hauptkategorie (the outermost/
 * root segment, itself a plain category with no SEPARATOR in its name), never stored
 * independently; CostCategoryObserver enforces this on every save.
 */
#[Fillable(['user_group_id', 'name', 'is_relevant_for_billing', 'is_tax_relevant'])]
#[ObservedBy(CostCategoryObserver::class)]
class CostCategory extends Model
{
    /**
     * Joins the segments of a Haupt-/Unterkategorie chain in `name` (e.g.
     * "Hauswart::Winterdienst"). Reserved – a single segment's own name may not contain
     * it (validated in CostCategoryController).
     */
    public const SEPARATOR = '::';

    protected function casts(): array
    {
        return [
            'is_relevant_for_billing' => 'boolean',
            'is_tax_relevant' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<ManagementGroup, $this>
     */
    public function managementGroups(): BelongsToMany
    {
        return $this->belongsToMany(ManagementGroup::class, 'management_group_cost_category')
            ->withPivot('allocation_key')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Whether this category is a subcategory, i.e. its name has at least one SEPARATOR.
     */
    public function isSubcategory(): bool
    {
        return str_contains($this->name, self::SEPARATOR);
    }

    /**
     * How deeply this category is nested: 0 for a Hauptkategorie, 1 for a direct
     * subcategory, 2 for a subcategory of a subcategory, and so on.
     */
    public function depth(): int
    {
        return substr_count($this->name, self::SEPARATOR);
    }

    /**
     * The outermost/root segment of the name, e.g. "Hauswart" for both "Hauswart" and
     * "Hauswart::Winterdienst" – the name under which the actual Hauptkategorie row (if
     * any) is looked up.
     */
    public function mainCategoryName(): string
    {
        return Str::before($this->name, self::SEPARATOR);
    }

    /**
     * The immediate parent's full name, or null for a Hauptkategorie itself – e.g.
     * "Hauswart" for "Hauswart::Winterdienst::2025", or "Hauswart::Winterdienst" for
     * "Hauswart::Winterdienst::2025::Extra".
     */
    public function parentName(): ?string
    {
        return $this->isSubcategory() ? Str::beforeLast($this->name, self::SEPARATOR) : null;
    }

    /**
     * The last segment of the name, e.g. "Winterdienst" for "Hauswart::Winterdienst" –
     * used for the indented display in cost-categories.index.
     */
    public function displayName(): string
    {
        return Str::afterLast($this->name, self::SEPARATOR);
    }

    /**
     * The Hauptkategorie this category's is_relevant_for_billing/is_tax_relevant are
     * derived from: itself if this is already a Hauptkategorie, otherwise the row named
     * mainCategoryName() (or null if that Hauptkategorie doesn't exist (yet)).
     */
    public function mainCategory(): ?self
    {
        if (! $this->isSubcategory()) {
            return $this;
        }

        return static::where('name', $this->mainCategoryName())->first();
    }

    /**
     * Scopes to the built-in/"systemweiten" categories (no group, visible to everyone)
     * plus those owned by a group the given user belongs to.
     *
     * @param  Builder<CostCategory>  $query
     * @return Builder<CostCategory>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('user_group_id')->orWhereIn('user_group_id', $user->groups->pluck('id'))
        );
    }
}
