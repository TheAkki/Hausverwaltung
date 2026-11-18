<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Represents an authenticated application user, belonging to many Groups (with a
 * role pivot), owning Groups, and having a UI Theme.
 */
#[Fillable(['name', 'email', 'password', 'theme_id'])]
#[Hidden(['password', 'remember_token'])]
#[ObservedBy(UserObserver::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower($value),
        );
    }

    /**
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function ownedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    /**
     * Die persönliche Gruppe ("Gruppe von <Name>"): Der UserObserver legt sie beim
     * Erstellen des Benutzers an, sie ist deshalb die erste Gruppe, die er besitzt.
     * Gemeinsame Gruppen, in denen er nur Mitglied ist, zählen nicht.
     */
    public function personalGroup(): ?Group
    {
        return $this->ownedGroups()->orderBy('id')->first();
    }

    public function isAdminOf(Group $group): bool
    {
        $membership = $this->groups->firstWhere('id', $group->id);

        return $membership !== null && $membership->pivot->role === 'admin';
    }

    /**
     * Whether this user is the bootstrapped Administrator account (see
     * AdminUserSeeder), which may additionally export/import ALL settings
     * (Backup-Konzept), not just its own data.
     */
    public function isAdministrator(): bool
    {
        return $this->email === strtolower((string) config('app.admin_email'));
    }

    /**
     * @return BelongsTo<Theme, $this>
     */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}
