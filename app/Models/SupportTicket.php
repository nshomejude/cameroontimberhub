<?php

namespace App\Models;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A "live chat with support" thread between one user and platform staff.
 * Staff = any user holding the `support.manage` permission (see
 * RolesAndPermissionsSeeder) — the same check gates the API staff inbox and
 * the Filament resource.
 */
class SupportTicket extends Model
{
    public const STAFF_PERMISSION = 'support.manage';

    protected $fillable = [
        'reference', 'user_id', 'order_id', 'subject', 'category', 'status',
        'last_activity_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'category' => SupportTicketCategory::class,
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public static function isStaff(?User $user): bool
    {
        return $user !== null && $user->can(self::STAFF_PERMISSION);
    }

    /** Users who should hear about new tickets / user replies. */
    public static function staffRecipients(): Collection
    {
        $perm = self::STAFF_PERMISSION;

        return User::query()
            ->where(function (Builder $q) use ($perm) {
                $q->whereHas('roles', function (Builder $r) use ($perm) {
                    $r->where('name', 'super_admin')
                        ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $perm));
                })->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $perm));
            })
            ->get();
    }

    public static function generateReference(): string
    {
        do {
            $ref = 'SUP-'.now()->year.'-'.Str::upper(Str::random(5));
        } while (self::query()->where('reference', $ref)->exists());

        return $ref;
    }
}
