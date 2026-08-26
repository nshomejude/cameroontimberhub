<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AppLaunchSubscriber;
use App\Services\AntiSpamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Marketing page for the buyer mobile app (Expo / React Native).
 *
 * Two honesty rules drive everything in here:
 *
 * 1. The feature list is the app's *shipped* v1 scope — browse, product
 *    detail, species, suppliers, post an RFQ, read/accept/decline quotes,
 *    account. Chat, orders, payments and shipment tracking exist on the web
 *    product but NOT in the app, so the page never mentions them as app
 *    features.
 *
 * 2. The app has never been built, signed or published. There is no APK and no
 *    store listing, so the page shows a "notify me" capture instead of a
 *    download button or a dead store badge. `config('mobile.apk_url')` is the
 *    only switch: null (the default) = notify state, a URL = download button.
 */
class MobileAppController extends Controller
{
    /**
     * Screens shipped in v1, each mapped to a genuine mockup crop in
     * public/img/app/. Nothing here is a screen the app does not have.
     *
     * @return list<array{src: string, title: string, caption: string, alt: string}>
     */
    public static function screens(): array
    {
        return [
            [
                'src' => 'img/app/marketplace.jpg',
                'title' => 'Marketplace',
                'caption' => 'Search the catalogue, filter by species and product type, and scroll results endlessly.',
                'alt' => 'The marketplace screen: a search field, species and product-type filters, and a list of timber listings with price, MOQ and supplier.',
            ],
            [
                'src' => 'img/app/product.jpg',
                'title' => 'Product detail',
                'caption' => 'Gallery, price and minimum order quantity, dimensions, specifications and the supplier behind the listing.',
                'alt' => 'A product detail screen for Iroko sawn timber showing an image gallery, FOB price, minimum order quantity and a specifications table.',
            ],
            [
                'src' => 'img/app/species.jpg',
                'title' => 'Species directory',
                'caption' => 'Every species with its Cameroon classification — category, CITES listing and log-export status.',
                'alt' => 'The timber species directory: a searchable list of species with grain photographs, category and weight class.',
            ],
            [
                'src' => 'img/app/suppliers.jpg',
                'title' => 'Suppliers',
                'caption' => 'Browse verified suppliers and open a trading profile: species handled, export markets, certifications.',
                'alt' => 'The supplier directory: cards for verified Cameroonian timber suppliers with location, product categories and response rate.',
            ],
        ];
    }

    /**
     * @return list<array{icon: string, title: string, text: string}>
     */
    public static function features(): array
    {
        return [
            [
                'icon' => 'magnifying-glass',
                'title' => 'Browse the whole catalogue',
                'text' => 'The same listings as the website — searchable, filtered by species and product type, with infinite scroll and pull-to-refresh.',
            ],
            [
                'icon' => 'rectangle-group',
                'title' => 'Read a listing properly',
                'text' => 'Photo gallery, FOB price, minimum order quantity, dimensions and the full specification table, plus a link straight to the supplier.',
            ],
            [
                'icon' => 'squares-2x2',
                'title' => 'Look up a species',
                'text' => 'The full species directory with Cameroon classification: category, CITES appendix and the log-export status that decides what may leave the country.',
            ],
            [
                'icon' => 'building-office-2',
                'title' => 'Check a supplier',
                'text' => 'Trading profile, species handled, export markets and certifications — the same verification signals the web directory shows.',
            ],
            [
                'icon' => 'document-text',
                'title' => 'Post a request for quote',
                'text' => 'Species, quantity, form, grade, dimensions, moisture, incoterm, destination and target price, submitted from your phone.',
            ],
            [
                'icon' => 'inbox-arrow-down',
                'title' => 'Read and answer quotes',
                'text' => 'Track your RFQs, open the quotes suppliers send back, and accept or decline a quotation with a confirmation step.',
            ],
        ];
    }

    public function show(): View
    {
        $apk = config('mobile.apk_url');

        return view('public.mobile-app', [
            'screens' => self::screens(),
            'features' => self::features(),
            'apkUrl' => $apk,
            'apkVersion' => config('mobile.apk_version'),
            'apkSize' => config('mobile.apk_size'),
            'playStoreUrl' => config('mobile.play_store_url'),
            'appStoreUrl' => config('mobile.app_store_url'),
            'schema' => self::schema(),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Mobile App', 'url' => route('mobile.app')],
            ],
        ]);
    }

    /**
     * SoftwareApplication JSON-LD, restricted to facts that are true today.
     *
     * There is deliberately no `offers`, no `downloadUrl` and no
     * `aggregateRating`: the app is not distributed, not priced and has never
     * been rated, and inventing any of those would be a lie told to a search
     * engine in a machine-readable format.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'Cameroon Timber Hub — Buyer App',
            'applicationCategory' => 'BusinessApplication',
            'applicationSubCategory' => 'Marketplace',
            'operatingSystem' => 'Android, iOS',
            'url' => route('mobile.app'),
            'description' => 'Buyer app for Cameroon Timber Hub: browse timber listings, look up species and suppliers, post a request for quote and respond to the quotations you receive.',
            'inLanguage' => 'en',
            'publisher' => ['@id' => url('/#organization')],
        ];
    }

    /**
     * Record a "tell me when it ships" address.
     *
     * Honeypot first (same guard as every other public intake form), then a
     * plain validation pass. A repeat address is a success, not a 422 — see
     * AppLaunchSubscriber::subscribe().
     */
    public function subscribe(Request $request, AntiSpamService $antiSpam): RedirectResponse
    {
        if ($antiSpam->honeypotTripped($request->all())) {
            return back()->with('app_notify_sent', true)->withFragment('notify');
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:180'],
            'platform' => ['nullable', 'in:any,android,ios'],
            'website' => ['nullable'],
            'form_rendered_at' => ['nullable'],
        ]);

        AppLaunchSubscriber::subscribe(
            $data['email'],
            $data['platform'] ?? 'any',
            $request->ip(),
        );

        return back()->with('app_notify_sent', true)->withFragment('notify');
    }
}
