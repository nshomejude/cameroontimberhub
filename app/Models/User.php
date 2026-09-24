<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Concerns\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'phone', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

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
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'last_login_at' => 'datetime',
            'known_login_ips' => 'array',
        ];
    }

    /**
     * Panel access (spec decision F): one `web` guard, two role-gated panels.
     * Platform staff (spatie roles) reach /admin; company members reach
     * /dashboard. Company membership is the `company_user` pivot, guarded so
     * this is safe before the Phase B migration creates the table.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasAnyRole([
                'super_admin', 'admin', 'verification_officer', 'content_manager',
                'compliance_officer', 'billing_officer', 'finance_officer',
                'support_officer', 'moderator',
            ]),
            'exporter' => Schema::hasTable('company_user') && $this->companies()->exists(),
            default => false,
        };
    }

    /**
     * Companies this user belongs to (with their company-side role).
     * Backing table arrives in Phase B; referencing the class here is safe.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Registered Expo push tokens for this user's mobile devices. */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** This user's notification channel/type preferences (auto-created with defaults on first access via `forUser()`). */
    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    /** Products/Companies this user has favorited. */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /** Companies/Users this user follows. */
    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    /** Follow rows where this user is the one being followed (i.e. this user's followers). */
    public function followedBy(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }
}
