<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public const PAGES = [
        [
            'slug' => 'about',
            'title' => 'About Cameroon Timber Hub',
            'h1' => 'Connecting the world to verified Cameroon timber',
            'template' => 'static',
            'meta_description' => 'Cameroon Timber Hub is a verified B2B timber trade platform connecting international buyers with document-reviewed Cameroonian timber exporters.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'Cameroon Timber Hub was built to solve a single problem: international timber buyers have no reliable way to identify trustworthy Cameroonian exporters, and exporters have no credible channel to reach serious buyers.'],
                    ['type' => 'heading', 'content' => 'What we do'],
                    ['type' => 'paragraph', 'content' => 'We review documents submitted by timber companies — business registrations, export permits, phytosanitary certificates, and legality evidence — and issue verification badges to companies whose submissions meet our review criteria.'],
                    ['type' => 'paragraph', 'content' => 'Verified companies appear in our searchable directory, receive buyer inquiries and RFQ leads, and display a verification badge with its issue date and expiry. All wording is legally precise: we review documents — we do not guarantee companies.'],
                    ['type' => 'heading', 'content' => 'Our verification standard'],
                    ['type' => 'list', 'items' => [
                        'Business registration documents reviewed by our compliance team',
                        'Export permits and phytosanitary certificates on file',
                        'Badges include issue date, valid-until date, and a due-diligence reminder',
                        'Expired or withdrawn evidence results in badge revocation',
                    ]],
                    ['type' => 'paragraph', 'content' => 'Documents are reviewed by Cameroon Timber Hub based on information submitted by companies. Buyers should conduct final due diligence before any transaction.'],
                ],
            ],
        ],
        [
            'slug' => 'contact',
            'title' => 'Contact Cameroon Timber Hub',
            'h1' => 'Get in touch',
            'template' => 'static',
            'meta_description' => 'Contact the Cameroon Timber Hub team with questions about verification, listings, or buyer inquiries.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'Use the form below to reach our team. For buyer inquiries about a specific company, use the company\'s profile page instead.'],
                ],
            ],
        ],
        [
            'slug' => 'verification',
            'title' => 'How verification works',
            'h1' => 'How we verify timber exporters',
            'template' => 'static',
            'meta_description' => 'Learn how Cameroon Timber Hub reviews documents and issues verification badges to Cameroonian timber exporters.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'Cameroon Timber Hub operates a manual document review process. Exporters submit their registration, export, and compliance documents through their dashboard. Our team reviews each submission and issues a time-bound badge when documents meet the review criteria.'],
                    ['type' => 'heading', 'content' => 'The review process'],
                    ['type' => 'list', 'items' => [
                        'Exporter registers and creates a company profile',
                        'Documents uploaded: business registration, export permits, legality certificates',
                        'Our compliance team reviews each document',
                        'Approved submissions receive a verification badge with an expiry date',
                        'Documents are re-reviewed at expiry — badge lapses if not renewed',
                    ]],
                    ['type' => 'heading', 'content' => 'What verification means'],
                    ['type' => 'paragraph', 'content' => 'A verification badge means we have reviewed the documents submitted by the company and found them consistent with the stated credentials at the time of review. It does not constitute a guarantee of the company\'s products, financial standing, or future conduct.'],
                    ['type' => 'paragraph', 'content' => 'Documents are reviewed by Cameroon Timber Hub based on information submitted by companies. Buyers should conduct final due diligence before any transaction.'],
                    ['type' => 'heading', 'content' => 'Are you an exporter?'],
                    ['type' => 'paragraph', 'content' => 'Register your company, upload your documents, and submit for review. The process typically takes 3–5 business days.'],
                ],
            ],
        ],
        [
            'slug' => 'list-your-company',
            'title' => 'List your company on Cameroon Timber Hub',
            'h1' => 'Reach verified international buyers',
            'template' => 'landing',
            'meta_description' => 'Get your Cameroonian timber company in front of international buyers. Free listing, verified badge, and RFQ leads available.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'heading', 'content' => 'Why list on Cameroon Timber Hub?'],
                    ['type' => 'list', 'items' => [
                        'Free basic listing — no card required',
                        'Verified badge shows buyers your documents have been reviewed',
                        'Receive RFQ leads from serious international buyers',
                        'Featured placement for maximum visibility',
                        'Profile includes species, export markets, contacts, and gallery',
                    ]],
                    ['type' => 'heading', 'content' => 'How it works'],
                    ['type' => 'list', 'items' => [
                        'Register a free account',
                        'Create your company profile and add species you export',
                        'Upload your business registration and export documents',
                        'Our team reviews and issues your verification badge',
                        'Buyers start finding and contacting you',
                    ]],
                ],
            ],
        ],
        [
            'slug' => 'timber-exporters-cameroon',
            'title' => 'Timber exporters from Cameroon — verified directory',
            'h1' => 'Verified timber exporters from Cameroon',
            'template' => 'landing',
            'meta_description' => 'Browse verified Cameroonian timber exporters. All listed companies have passed document review. Search by species, region, and export market.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'Every company listed here has submitted their business registration, export permits, and compliance documents to Cameroon Timber Hub for review. Badges show the review date and expiry — buyers should still conduct final due diligence before any transaction.'],
                ],
            ],
        ],
        [
            'slug' => 'cameroon-timber-suppliers',
            'title' => 'Cameroon timber suppliers — verified B2B directory',
            'h1' => 'Verified timber suppliers from Cameroon',
            'template' => 'landing',
            'canonical_url' => null, // set at runtime via APP_URL + /timber-exporters-cameroon
            'meta_description' => 'Find document-reviewed Cameroon timber suppliers for sapele, iroko, okoume, and more. B2B directory with direct contact and RFQ submission.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'Connect directly with verified Cameroonian timber suppliers. All companies have passed document review. Search by species, region, or export market to find the right supplier for your requirements.'],
                ],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PAGES as $page) {
            Page::updateOrCreate(
                ['slug' => $page['slug']],
                $page,
            );
        }
    }
}
