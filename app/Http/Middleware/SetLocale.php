<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Supported locales. Keep in sync with the `locale` route's validation
     * and the language-switcher options in the header component.
     */
    public const SUPPORTED = ['en', 'fr', 'zh_CN', 'th', 'vi', 'it', 'es', 'de'];

    /**
     * Maps an `Accept-Language` primary subtag to its supported locale
     * directory name, for the cases where they differ (the mobile client
     * sends bare `zh`, but the translation directory is `zh_CN` since we
     * only ship simplified Chinese today).
     */
    private const PRIMARY_SUBTAG_MAP = [
        'zh' => 'zh_CN',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Stateful (web) requests carry a session cookie and use the
        // session-stored preference set by the language switcher. Stateless
        // API requests (Sanctum-token-authed, no session cookie) have no
        // session to read, so fall back to the `Accept-Language` header —
        // the mobile client's way of declaring its locale.
        $locale = $request->hasSession() && $request->session()->has('locale')
            ? $request->session()->get('locale')
            : $this->resolveFromHeader($request);

        // An authenticated user's own saved preference, when one exists,
        // wins over both of the above (nothing to check today: no
        // `users.locale` column exists yet — see SetLocale::handle() note).
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = config('app.locale', 'en');
        }

        app()->setLocale($locale);

        return $next($request);
    }

    /**
     * Simple `Accept-Language` resolution: match the first supported locale
     * code in the header, ignoring quality values (`fr-FR;q=0.9,en;q=0.8`)
     * and any subtag — `fr-FR` and `fr` both match `fr`. Falls back to
     * `config('app.locale')` when the header is absent or names nothing
     * supported.
     */
    private function resolveFromHeader(Request $request): string
    {
        $header = (string) $request->headers->get('Accept-Language', '');

        if ($header === '') {
            return config('app.locale', 'en');
        }

        foreach (explode(',', $header) as $part) {
            $code = strtolower(trim(explode(';', $part)[0]));
            $primary = explode('-', $code)[0];
            $primary = self::PRIMARY_SUBTAG_MAP[$primary] ?? $primary;

            if (in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }

        return config('app.locale', 'en');
    }
}
