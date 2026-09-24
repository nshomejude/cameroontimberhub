<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\BarcodeService;
use App\Services\CertificateQrCodeService;
use App\Services\CertificateVerifier;
use App\Services\CertificateWatermarkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public certificate-verification page. Mirrors
 * app/Http/Controllers/Public/ReceiptVerificationController.php's shape and
 * reasoning: rate-limited, allow-list-only disclosure, and a consistent
 * redirect flow whether the token is unknown or points at a
 * non-current/revoked certificate.
 *
 * token() redirects rather than rendering, for the same reason the receipt
 * controller does: the token is a bearer secret, and rendering that URL
 * would bake it into the page's canonical/og:url tags, its browser history
 * entry, and the Referer header of every outbound link on it.
 */
class CertificateVerificationController extends Controller
{
    public function __construct(
        private readonly CertificateVerifier $verifier,
        private readonly CertificateQrCodeService $qr,
        private readonly BarcodeService $barcode,
        private readonly CertificateWatermarkService $watermark,
    ) {}

    public function create(Request $request): View
    {
        $certificate = ($id = $request->session()->get('verified_certificate_id'))
            ? Certificate::with('subject')->find($id)
            : null;

        return view('public.certificates.verify', [
            'result' => $certificate ? $this->verifier->publicPayload($certificate) : null,
            'searched' => $certificate !== null || $request->session()->get('verified_certificate_missing', false),
        ]);
    }

    public function token(string $token): RedirectResponse
    {
        $certificate = $this->verifier->findByToken($token);

        if (! $certificate) {
            return redirect()->route('certificates.verify')->with('verified_certificate_missing', true);
        }

        return redirect()->route('certificates.verify')->with('verified_certificate_id', $certificate->getKey());
    }

    /**
     * The staff-facing printable certificate document -- the house
     * window.print() pattern (see resources/views/public/orders/receipt.blade.php),
     * not a generated PDF: no PDF library exists in this codebase and this
     * exact "printable, verifiable document" problem is already solved that
     * way for receipts.
     *
     * Uses abort_unless rather than $this->authorize(): this project's base
     * Controller does not pull in the AuthorizesRequests trait.
     */
    public function show(Request $request, string $certificateNumber): View
    {
        abort_unless($request->user()?->can('certificates.manage'), 403);

        $certificate = Certificate::with('subject')
            ->forNumber($certificateNumber)
            ->liveVersion()
            ->orderByDesc('version')
            ->firstOrFail();

        return view('public.certificates.show', [
            'certificate' => $certificate,
            'qrDataUri' => $this->qr->dataUri($certificate),
            'verificationUrl' => $this->qr->verificationUrl($certificate),
            'barcodeDataUri' => $this->barcode->svgDataUri($certificate->certificate_number),
            'watermarkStyle' => $this->watermark->backgroundStyle($certificate),
            'showVoidStamp' => $this->watermark->shouldShowVoidStamp($certificate),
            'voidLabel' => $this->watermark->voidLabel($certificate),
        ]);
    }
}
