<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Blueprint §39: TOTP-based multi-factor authentication.
 *
 * Deliberately hand-rolled rather than pulling in Fortify's
 * TwoFactorAuthenticatable: this app has no Fortify installation (confirmed
 * against composer.json before writing this), and adding the whole package
 * just for its 2FA trait would be a much heavier footprint than
 * pragmarx/google2fa-laravel, which is what the blueprint calls for instead.
 */
trait TwoFactorAuthenticatable
{
    /**
     * Whether the user has completed 2FA setup (secret confirmed).
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Generate a fresh, unconfirmed TOTP secret and persist it.
     */
    public function generateTwoFactorSecret(): string
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return $secret;
    }

    /**
     * Verify a submitted TOTP code against the stored (unconfirmed or
     * confirmed) secret.
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        if (is_null($this->two_factor_secret)) {
            return false;
        }

        return (bool) app(Google2FA::class)->verifyKey($this->two_factor_secret, $code);
    }

    /**
     * Mark the currently-generated secret as confirmed and issue recovery
     * codes. Returns the plaintext codes (shown once, then only stored
     * hashed-equivalent via the model's `encrypted` cast).
     *
     * @return array<int, string>
     */
    public function confirmTwoFactor(): array
    {
        $codes = $this->generateRecoveryCodes();

        $this->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => json_encode($codes),
        ])->save();

        return $codes;
    }

    /**
     * Disable 2FA entirely, wiping the secret and recovery codes.
     */
    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Regenerate recovery codes for an already-confirmed 2FA setup.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(): array
    {
        $codes = $this->generateRecoveryCodes();

        $this->forceFill(['two_factor_recovery_codes' => json_encode($codes)])->save();

        return $codes;
    }

    /**
     * Consume a recovery code if valid and unused. Returns whether it matched.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $codes = $this->two_factor_recovery_codes ? json_decode($this->two_factor_recovery_codes, true) : [];

        if (! is_array($codes)) {
            return false;
        }

        $normalized = strtoupper(trim($code));
        $index = array_search($normalized, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);

        $this->forceFill(['two_factor_recovery_codes' => json_encode(array_values($codes))])->save();

        return true;
    }

    /**
     * @return array<int, string>
     */
    protected function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }

    public function twoFactorQrCodeSvg(string $secret): string
    {
        $google2fa = app(Google2FA::class);

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $this->email,
            $secret,
        );

        $writer = new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd,
            )
        );

        return $writer->writeString($qrCodeUrl);
    }
}
