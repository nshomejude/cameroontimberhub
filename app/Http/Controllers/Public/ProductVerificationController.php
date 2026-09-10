<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductQrCodeService;
use Illuminate\View\View;

/**
 * Public product-listing verification page (gap-plan §1.1). Open to anyone
 * holding the link or scanning the QR; rate-limited (`product-verify`) and
 * discloses only an allow-listed set of fields. The numeric primary key is
 * never exposed — lookup and the route binding are on `public_id`.
 *
 * Only `active` listings resolve; anything else 404s with the same shape so
 * the existence of a draft/archived listing is never leaked.
 */
class ProductVerificationController extends Controller
{
    public function __construct(private readonly ProductQrCodeService $qr) {}

    public function show(string $publicId): View
    {
        $with = ['company', 'species', 'verification'];

        // documents() / certificates() are B2 / B3 work — load them only once
        // those relations exist on Product.
        if (method_exists(Product::class, 'documents')) {
            $with[] = 'documents';
        }
        if (method_exists(Product::class, 'certificates')) {
            $with[] = 'certificates';
        }

        $product = Product::query()
            ->where('public_id', $publicId)
            ->where('status', ProductStatus::Active)
            ->with($with)
            ->firstOrFail();

        return view('public.products.verify', [
            'product' => $product,
            'company' => $product->company,
            'species' => $product->species,
            'stageLabel' => $product->verification?->stage?->label(),
            'isVerified' => $product->isVerified(),
            'qrSvg' => $this->qr->svg($product),
            'verificationUrl' => $this->qr->verificationUrl($product),
            'documents' => in_array('documents', $with, true) ? $product->documents : collect(),
            'certificates' => in_array('certificates', $with, true) ? $product->certificates : collect(),
        ]);
    }
}
