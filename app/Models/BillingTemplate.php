<?php

namespace App\Models;

use App\Observers\BillingTemplateObserver;
use App\Support\FileStorage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a Blade-based Betriebskostenabrechnung template with optional header
 * image, used as the default template by many ManagementGroups. `user_group_id` is
 * null for the built-in/"internal" templates (visible to everyone); self-created
 * templates are assigned to one of the creating user's Benutzergruppen.
 */
#[Fillable(['user_group_id', 'name', 'content', 'css_content', 'header', 'footer', 'header_image_path', 'is_default'])]
#[ObservedBy(BillingTemplateObserver::class)]
class BillingTemplate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ManagementGroup, $this>
     */
    public function managementGroups(): HasMany
    {
        return $this->hasMany(ManagementGroup::class, 'default_billing_template_id');
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Scopes to the built-in/"internal" templates (no group, visible to everyone) plus
     * those owned by a group the given user belongs to.
     *
     * @param  Builder<BillingTemplate>  $query
     * @return Builder<BillingTemplate>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('user_group_id')->orWhereIn('user_group_id', $user->groups->pluck('id'))
        );
    }

    public function headerImageUrl(): ?string
    {
        return $this->header_image_path ? route('files.billing-template-image', $this) : null;
    }

    /**
     * The header image embedded as a base64 data URI, for use in the Betriebskosten-
     * abrechnung's rendered HTML/PDF. DomPDF refuses to fetch "remote" URLs (including
     * our own storage URL) unless `enable_remote` is turned on, and even with that on, a
     * single-threaded `php artisan serve` would deadlock fetching a URL from itself while
     * still handling the very request generating the PDF. A data URI sidesteps both
     * issues entirely since no HTTP request is needed to load it.
     */
    public function headerImageDataUri(): ?string
    {
        if ($this->header_image_path === null || ! FileStorage::disk()->exists($this->header_image_path)) {
            return null;
        }

        $contents = FileStorage::disk()->get($this->header_image_path);
        $mimeType = FileStorage::disk()->mimeType($this->header_image_path) ?: 'application/octet-stream';

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
