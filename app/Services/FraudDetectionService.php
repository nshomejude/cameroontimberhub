<?php

namespace App\Services;

use App\Enums\FraudSignalSeverity;
use App\Enums\FraudSignalType;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\FraudSignal;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Initial anti-fraud detection (blueprint §25): duplicate-entity detection,
 * pricing-anomaly alerts and login-anomaly detection.
 *
 * DETECTION AND ALERTING ONLY. Every public method here only ever computes
 * candidates and, where warranted, writes a FraudSignal row for a human
 * admin to review — nothing in this class blocks a save, rejects a login,
 * suspends a company, or hides a listing. Callers (observers/listeners) wrap
 * every call in try/catch so a bug here can never break the real operation
 * it is attached to.
 */
class FraudDetectionService
{
    /** Price deviation multiple (above or below the comparison median) that triggers a pricing-anomaly flag. */
    public const PRICE_DEVIATION_THRESHOLD = 3.0;

    /** Minimum number of comparable Active products needed before a median is trusted. */
    public const MIN_COMPARABLE_PRODUCTS = 3;

    /** How many recent login IPs are remembered per user for the new-IP heuristic. */
    public const KNOWN_IP_HISTORY_LIMIT = 10;

    public function __construct(private readonly AiFraudConsistencyChecker $aiConsistencyChecker) {}

    /**
     * Fuzzy-matches this company's name (slug comparison, no new dependency)
     * plus same registration_number or same primary contact email/phone
     * against every other company. Returns the candidate matches found; when
     * any are found, also records a DuplicateCompany FraudSignal.
     *
     * @return list<array{company_id: int, reason: string, match: string}>
     */
    public function detectDuplicateCompanies(Company $company): array
    {
        $candidates = [];
        $slug = Str::slug($company->name);

        $others = Company::query()
            ->where('id', '!=', $company->getKey())
            ->when(true, function ($query) use ($company) {
                $query->where(function ($q) use ($company) {
                    $q->orWhere('legal_name', $company->legal_name)
                        ->when($company->trade_name, fn ($q2) => $q2->orWhere('trade_name', $company->trade_name))
                        ->when($company->registration_number, fn ($q2) => $q2->orWhere('registration_number', $company->registration_number))
                        ->when($company->email, fn ($q2) => $q2->orWhere('email', $company->email))
                        ->when($company->phone, fn ($q2) => $q2->orWhere('phone', $company->phone));
                });
            })
            ->get();

        foreach ($others as $other) {
            $reasons = [];

            if ($slug !== '' && Str::slug($other->name) === $slug) {
                $reasons[] = 'name_match';
            }

            if ($company->registration_number && $other->registration_number === $company->registration_number) {
                $reasons[] = 'registration_number_match';
            }

            if ($company->email && strcasecmp((string) $other->email, (string) $company->email) === 0) {
                $reasons[] = 'email_match';
            }

            if ($company->phone && $this->normalisePhone($other->phone) === $this->normalisePhone($company->phone) && $this->normalisePhone($company->phone) !== '') {
                $reasons[] = 'phone_match';
            }

            if ($reasons === []) {
                continue;
            }

            $candidates[] = [
                'company_id' => $other->getKey(),
                'reason' => implode(',', $reasons),
                'match' => $other->name,
            ];
        }

        if ($candidates !== []) {
            $severity = collect($candidates)->contains(fn (array $c) => str_contains($c['reason'], 'registration_number_match') || str_contains($c['reason'], 'email_match'))
                ? FraudSignalSeverity::High
                : FraudSignalSeverity::Medium;

            FraudSignal::create([
                'subject_type' => Company::class,
                'subject_id' => $company->getKey(),
                'signal_type' => FraudSignalType::DuplicateCompany,
                'severity' => $severity,
                'details' => ['candidates' => $candidates],
            ]);
        }

        return $candidates;
    }

    /**
     * Compares $product's price against the median price of other Active
     * products in the same species (falling back to same category when the
     * product has no species). Flags when the deviation exceeds
     * PRICE_DEVIATION_THRESHOLD, and only once at least MIN_COMPARABLE_PRODUCTS
     * comparable products exist, to avoid false positives on thin data.
     *
     * @return array{median: float, price: float, ratio: float}|null
     */
    public function detectPricingAnomaly(Product $product): ?array
    {
        if ($product->price_amount === null || (float) $product->price_amount <= 0) {
            return null;
        }

        if (! $product->species_id && ! $product->category_id) {
            return null;
        }

        $query = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->where('id', '!=', $product->getKey())
            ->where('price_currency', $product->price_currency)
            ->whereNotNull('price_amount');

        if ($product->species_id) {
            $query->where('species_id', $product->species_id);
        } else {
            $query->where('category_id', $product->category_id);
        }

        $comparablePrices = $query->pluck('price_amount')->map(fn ($v) => (float) $v)->values();

        if ($comparablePrices->count() < self::MIN_COMPARABLE_PRODUCTS) {
            return null;
        }

        $median = $this->median($comparablePrices->all());

        if ($median <= 0) {
            return null;
        }

        $price = (float) $product->price_amount;
        $ratio = $price / $median;

        $isAnomaly = $ratio >= self::PRICE_DEVIATION_THRESHOLD || $ratio <= (1 / self::PRICE_DEVIATION_THRESHOLD);

        if (! $isAnomaly) {
            return null;
        }

        $result = ['median' => $median, 'price' => $price, 'ratio' => round($ratio, 2)];

        FraudSignal::create([
            'subject_type' => Product::class,
            'subject_id' => $product->getKey(),
            'signal_type' => FraudSignalType::PricingAnomaly,
            'severity' => $ratio >= 5.0 || $ratio <= 0.2 ? FraudSignalSeverity::High : FraudSignalSeverity::Medium,
            'details' => $result + ['comparable_count' => $comparablePrices->count()],
        ]);

        return $result;
    }

    /**
     * Simple heuristic: flags when this IP is genuinely new for a user who
     * already has an established login history (a recorded last_login_at and
     * at least one known IP) — i.e. this is not the user's first-ever login,
     * and this IP has never been seen for them before. A first-ever login has
     * nothing to compare against, so it is never flagged.
     *
     * @return array{ip: string, known_ips: list<string>}|null
     */
    public function detectLoginAnomaly(User $user, string $ip, ?string $userAgent = null): ?array
    {
        if ($ip === '') {
            return null;
        }

        $hasHistory = $user->last_login_at !== null;
        $knownIps = collect($user->known_login_ips ?? [])->filter()->values();

        if (! $hasHistory || $knownIps->isEmpty()) {
            return null;
        }

        if ($knownIps->contains($ip)) {
            return null;
        }

        $result = ['ip' => $ip, 'known_ips' => $knownIps->all()];

        FraudSignal::create([
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
            'signal_type' => FraudSignalType::LoginAnomaly,
            'severity' => FraudSignalSeverity::Low,
            'details' => $result + ['user_agent' => $userAgent],
        ]);

        return $result;
    }

    /**
     * AI-assisted cross-field consistency check on company onboarding data
     * (blueprint §25/§35). Optional/best-effort: AiFraudConsistencyChecker
     * itself returns null (never throws) when the AI gateway is not
     * configured or the call fails, so this never becomes a hard dependency
     * of company creation.
     *
     * @return array{summary: string, severity: string, reasoning: string}|null
     */
    public function detectAiConsistencyIssue(Company $company): ?array
    {
        $result = $this->aiConsistencyChecker->check($company);

        if ($result === null) {
            return null;
        }

        $severity = match ($result['severity']) {
            'high' => FraudSignalSeverity::High,
            'medium' => FraudSignalSeverity::Medium,
            default => FraudSignalSeverity::Low,
        };

        FraudSignal::create([
            'subject_type' => Company::class,
            'subject_id' => $company->getKey(),
            'signal_type' => FraudSignalType::AiConsistencyCheck,
            'severity' => $severity,
            'details' => $result,
        ]);

        return $result;
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
        }

        return (float) $values[$middle];
    }

    private function normalisePhone(?string $phone): string
    {
        return $phone ? preg_replace('/\D+/', '', $phone) ?? '' : '';
    }
}
