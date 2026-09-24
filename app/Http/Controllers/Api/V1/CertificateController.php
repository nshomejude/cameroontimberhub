<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CertificateStatus;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\Product;
use App\Services\CertificateQrCodeService;
use App\Services\CertificateSigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Public (no-auth) certificate directory + detail JSON API — the mobile
 * counterpart of Public\CertificateVerificationController. Only ever
 * exposes issued-and-currently-valid certificates
 * (`CertificateStatus::isCurrentlyValid()`: Issued/Active), scoped to
 * `Certificate::scopeLiveVersion()` so a superseded/replaced row never
 * appears here — the same "public, allow-list-only disclosure" boundary
 * that controller's docblock describes.
 */
class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateQrCodeService $qr,
        private readonly CertificateSigningService $signer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Certificate::query()
            ->liveVersion()
            ->whereIn('status', array_map(
                fn (CertificateStatus $s) => $s->value,
                array_filter(CertificateStatus::cases(), fn (CertificateStatus $s) => $s->isCurrentlyValid()),
            ));

        if ($companySlug = $request->query('company')) {
            $companyIds = Company::query()->where('slug', $companySlug)->pluck('id');
            $query->where('subject_type', Company::class)->whereIn('subject_id', $companyIds);
        } elseif ($productSlug = $request->query('product')) {
            $productIds = Product::query()->where('slug', $productSlug)->pluck('id');
            $query->where('subject_type', Product::class)->whereIn('subject_id', $productIds);
        }

        $certificates = $query->with('subject')->orderByDesc('issued_at')->get();

        return response()->json([
            'data' => $certificates->map(fn (Certificate $c) => $this->summary($c))->values(),
        ]);
    }

    public function show(string $certificateNumber): JsonResponse
    {
        $certificate = Certificate::query()
            ->with('subject')
            ->forNumber($certificateNumber)
            ->liveVersion()
            ->orderByDesc('version')
            ->first();

        if (! $certificate) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['data' => $this->detail($certificate)]);
    }

    /** @return array<string, mixed> */
    private function summary(Certificate $certificate): array
    {
        return [
            'number' => $certificate->certificate_number,
            'type' => $certificate->subject_type ? class_basename($certificate->subject_type) : null,
            'status' => $certificate->status->value,
            'version' => $certificate->version,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'subject' => $this->subject($certificate),
            'verification_url' => $this->qr->verificationUrl($certificate),
            'barcode_value' => $certificate->certificate_number,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Certificate $certificate): array
    {
        return array_merge($this->summary($certificate), [
            'geo' => $certificate->geospatial_data,
            'data_hash' => $certificate->data_hash,
            'signature_valid' => $this->signatureIsValid($certificate),
        ]);
    }

    /** @return array{type: string|null, name: string|null, slug: string|null} */
    private function subject(Certificate $certificate): array
    {
        $subject = $certificate->subject;

        if (! $subject) {
            return ['type' => null, 'name' => null, 'slug' => null];
        }

        return [
            'type' => class_basename($subject),
            'name' => $subject->name ?? $subject->lot_number ?? null,
            'slug' => $subject->slug ?? $subject->lot_number ?? null,
        ];
    }

    /**
     * Same degrade-to-false-never-500 rule as
     * CertificateVerifier::signatureIsValid(): this endpoint is public, so a
     * missing/retired signing key must not surface as a server error.
     */
    private function signatureIsValid(Certificate $certificate): bool
    {
        if ($certificate->signature === null || $certificate->data_hash === null) {
            return false;
        }

        try {
            return $this->signer->verify($certificate->data_hash, $certificate->signature);
        } catch (RuntimeException) {
            return false;
        }
    }
}
