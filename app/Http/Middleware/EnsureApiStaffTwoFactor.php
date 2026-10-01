<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Api\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API twin of EnsureStaffTwoFactor for /api/v1/staff/*: a staff bearer token
 * whose owner has no confirmed 2FA secret is refused with 403
 * `two_factor_enrollment_required`. Defence in depth — API login already
 * refuses to mint such tokens (Api\V1\AuthController::login), but tokens
 * minted before that rule, or 2FA being reset later, must not reach staff
 * endpoints.
 */
class EnsureApiStaffTwoFactor
{
    public const CODE = 'two_factor_enrollment_required';

    public static function message(): string
    {
        return __('Two-factor authentication is required for staff accounts. Set it up on the web at :url, then sign in again.', [
            'url' => url('/security/two-factor'),
        ]);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('auth.require_staff_2fa', true)
            && $user instanceof User
            && $user->isStaff()
            && ! $user->hasTwoFactorEnabled()) {
            throw new ApiException(403, self::CODE, self::message());
        }

        return $next($request);
    }
}
