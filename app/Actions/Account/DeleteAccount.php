<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Enums\ProductStatus;
use App\Exceptions\Api\ApiException;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Self-service account deletion (App Store / Play Store requirement), shared
 * by `DELETE /api/v1/auth/me` and the web `POST /account/delete`.
 *
 * `User` has no SoftDeletes, and orders, quotes, RFQs and messages all point
 * at the users row — so the row is KEPT and anonymised rather than removed:
 * every foreign key stays valid, and nothing left on it identifies a person.
 *
 * Companies: a non-owner (or co-owner) is simply detached. The SOLE owner of
 * a company that still has other members is refused (409
 * `transfer_ownership_first`) — deleting them would leave a company nobody
 * can manage. A company whose only member is this user is archived along with
 * its products (never deleted: its orders and quotes still reference it).
 *
 * Staff accounts cannot self-delete (403) — they are offboarded by an admin.
 */
class DeleteAccount
{
    public const DELETED_NAME = 'Deleted user';

    /** Throws ApiException (403 / 409) when the account may not be deleted. */
    public function assertDeletable(User $user): void
    {
        if ($user->isStaff()) {
            throw new ApiException(403, 'staff_cannot_self_delete', __('Staff accounts cannot be deleted from here. Ask an administrator.'));
        }

        foreach ($user->companies()->get() as $company) {
            if ($this->blocksDeletion($user, $company)) {
                throw new ApiException(409, 'transfer_ownership_first', __('You are the only owner of :company, which has other members. Transfer ownership before deleting your account.', ['company' => $company->name]));
            }
        }
    }

    public function __invoke(User $user): void
    {
        $this->assertDeletable($user);

        DB::transaction(function () use ($user): void {
            $archived = [];
            $detached = [];

            foreach ($user->companies()->get() as $company) {
                $others = $company->users()->whereKeyNot($user->getKey())->count();

                if ($others === 0) {
                    $company->products()->where('status', '!=', ProductStatus::Archived->value)
                        ->update(['status' => ProductStatus::Archived->value]);
                    $company->forceFill(['status' => CompanyStatus::Archived])->save();
                    $archived[] = $company->getKey();
                }

                $detached[] = $company->getKey();
            }

            $user->companies()->detach();
            $user->tokens()->delete();
            $user->deviceTokens()->delete();
            $user->favorites()->delete();
            $user->follows()->delete();
            $user->syncRoles([]);

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }

            $columns = Schema::getColumnListing('users');
            $originalId = $user->getKey();

            $user->forceFill(array_intersect_key([
                'name' => self::DELETED_NAME,
                'email' => "deleted+{$originalId}@deleted.invalid",
                'phone' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'last_login_ip' => null,
                'last_login_user_agent' => null,
                'known_login_ips' => null,
            ], array_flip($columns)))->save();

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->withProperties(['detached_company_ids' => $detached, 'archived_company_ids' => $archived])
                ->log('account_deleted');
        });
    }

    private function blocksDeletion(User $user, Company $company): bool
    {
        $role = $company->pivot?->role;
        $role = $role instanceof CompanyUserRole ? $role->value : $role;

        if ($role !== CompanyUserRole::Owner->value) {
            return false;
        }

        $others = $company->users()->whereKeyNot($user->getKey());
        $otherOwners = (clone $others)->wherePivot('role', CompanyUserRole::Owner->value)->count();

        return $otherOwners === 0 && $others->count() > 0;
    }
}
