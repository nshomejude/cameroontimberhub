<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\DeleteAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * `DELETE /api/v1/auth/me` — in-app account deletion (store requirement).
 * Re-authenticates with the current `password`, then hands off to
 * DeleteAccount (anonymise + revoke every token + detach/archive companies).
 * Kept out of AuthController so the deletion path has one obvious home.
 */
class AccountDeletionController extends Controller
{
    public function destroy(Request $request, DeleteAccount $deleteAccount): Response
    {
        $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        // Staff/ownership refusals first: a 403/409 must not depend on the password.
        $deleteAccount->assertDeletable($user);

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('The provided password does not match your current password.'),
            ]);
        }

        $deleteAccount($user);

        return response()->noContent();
    }
}
