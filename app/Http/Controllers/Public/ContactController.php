<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageMail;
use App\Models\Page;
use App\Services\IntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(): View
    {
        $page = Page::where('slug', 'contact')->where('is_published', true)->firstOrFail();

        $details = self::details($page);

        return view('public.pages.contact', [
            'page' => $page,
            'details' => $details,
            'mapUrl' => self::mapUrl($details),
            'schema' => self::schema($details),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => $page->title, 'url' => route('contact')],
            ],
        ]);
    }

    /**
     * Contact details, resolved from config/contact.php and overridable per
     * page from the CMS (`pages.data.contact`) so an admin can correct an
     * address or a phone number without a deploy.
     *
     * @return array<string, mixed>
     */
    public static function details(?Page $page = null): array
    {
        $base = config('contact');
        $override = is_array($page?->data) ? ($page->data['contact'] ?? []) : [];

        return is_array($override) ? array_replace_recursive($base, $override) : $base;
    }

    /**
     * Outbound link to a maps provider. We deliberately do not embed a
     * third-party map iframe/script: it would need a CSP exception and would
     * expose every visitor's IP to the map vendor before they consent.
     *
     * @param  array<string, mixed>  $details
     */
    private static function mapUrl(array $details): string
    {
        $lat = $details['geo']['latitude'] ?? null;
        $lng = $details['geo']['longitude'] ?? null;

        if ($lat === null || $lng === null) {
            return 'https://www.openstreetmap.org/';
        }

        return 'https://www.openstreetmap.org/?mlat='.$lat.'&mlon='.$lng.'#map=15/'.$lat.'/'.$lng;
    }

    /**
     * ContactPage JSON-LD. A ContactPoint is emitted only when we actually
     * hold a phone number or a mailbox, and `sameAs` only lists social
     * profiles that are configured — never invented ones.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function schema(array $details): array
    {
        $points = [];

        foreach ($details['phones'] ?? [] as $i => $phone) {
            $points[] = array_filter([
                '@type' => 'ContactPoint',
                'contactType' => $i === 0 ? 'customer support' : 'sales',
                'telephone' => $phone,
                'email' => $details['emails'][$i] ?? null,
                'areaServed' => 'Worldwide',
                'availableLanguage' => ['en', 'fr'],
            ]);
        }

        if ($points === []) {
            foreach ($details['emails'] ?? [] as $email) {
                $points[] = [
                    '@type' => 'ContactPoint',
                    'contactType' => 'customer support',
                    'email' => $email,
                ];
            }
        }

        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $details['address']['lines'][0] ?? null,
            'addressLocality' => $details['address']['locality'] ?? null,
            'addressRegion' => $details['address']['region'] ?? null,
            'postalCode' => $details['address']['postal_code'] ?: null,
            'addressCountry' => $details['address']['country'] ?? null,
        ]);

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ContactPage',
            'name' => 'Contact '.($details['organisation'] ?? config('app.name')),
            'url' => route('contact'),
            'mainEntity' => array_filter([
                '@type' => 'Organization',
                '@id' => url('/#organization'),
                'name' => $details['organisation'] ?? config('app.name'),
                'url' => url('/'),
                'logo' => url('/brand/logo-600.png'),
                'address' => $address ?: null,
                'contactPoint' => $points ?: null,
                'sameAs' => array_values(array_filter($details['social'] ?? [])) ?: null,
            ]),
        ];
    }

    public function store(Request $request, IntakeService $intake): RedirectResponse
    {
        if ($intake->honeypotTripped($request->all())) {
            return back()->with('contact_sent', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'min:4', 'max:200'],
            'message' => ['required', 'string', 'min:20', 'max:3000'],
            'consent' => ['accepted'],
            'website' => ['nullable'],
            'form_rendered_at' => ['nullable'],
        ]);

        $intake->createContactMessage([
            'name' => trim($data['name']),
            'company' => $data['company'] ?? null,
            'email' => strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
        ], filled($data['consent'] ?? null));

        Mail::to(config('mail.from.address'))->send(new ContactMessageMail($data));

        return back()->with('contact_sent', true);
    }
}
