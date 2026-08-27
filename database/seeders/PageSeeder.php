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
            'h1' => 'Building Africa’s timber trade gateway to the world',
            'template' => 'about',
            'meta_description' => 'Cameroon Timber Hub is a verified B2B timber trade platform connecting international buyers with document-reviewed Cameroonian timber exporters.',
            'is_published' => true,
            'data' => [
                'eyebrow' => 'About Cameroon Timber Hub',
                'intro' => 'Cameroon Timber Hub is a digital marketplace connecting document-reviewed timber suppliers with international buyers. We promote transparency, compliance and sustainable trade while opening up the potential of Africa’s forest resources.',
                'pillars' => [
                    ['icon' => 'shield-check', 'title' => 'Trusted & Verified', 'text' => 'Supplier documents are reviewed before a company is listed as verified, for a safer marketplace.'],
                    ['icon' => 'sparkles', 'title' => 'Sustainable Trade', 'text' => 'We promote legal, responsible and traceable forestry for future generations.'],
                    ['icon' => 'globe-alt', 'title' => 'Global Reach', 'text' => 'Connecting Cameroonian and African timber suppliers with buyers worldwide.'],
                    ['icon' => 'users', 'title' => 'Growth & Impact', 'text' => 'Empowering local businesses, creating jobs and driving economic growth.'],
                ],
                'mission' => 'To become the leading B2B digital platform for the timber industry in Africa, enabling seamless connections, fair opportunities and sustainable growth for every stakeholder in the timber value chain.',
                'vision' => 'A world where African timber is recognised for its quality and sustainability, powering global markets and transforming the communities it comes from.',
                'why_title' => 'Why Cameroon?',
                'why_intro' => 'Cameroon has rich and diverse forest resources and is strategically located for access to global markets.',
                'why_points' => [
                    'Over 22 million hectares of forest cover',
                    'Diverse hardwood species of global commercial value',
                    'Favourable trade agreements and export incentives',
                    'A skilled workforce and long-standing forestry expertise',
                    'A national commitment to legality and traceability',
                ],
                'story_eyebrow' => 'Our story',
                'story_title' => 'Why we built Cameroon Timber Hub',
                'cta_title' => 'Be part of Africa’s timber success story',
                'cta_text' => 'Join the verified buyers and suppliers building a transparent and sustainable timber trade ecosystem.',
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
            'h1' => 'We’re here to connect, support & grow together',
            'template' => 'static',
            'meta_description' => 'Contact the Cameroon Timber Hub team with questions about verification, listings, or buyer inquiries.',
            'is_published' => true,
            'data' => [
                'eyebrow' => 'Contact us',
                'intro' => 'Have a question, a partnership proposal, or need assistance? Our team is ready to help you succeed in the global timber trade.',
                'hq_note' => 'The economic capital of Cameroon, and our home.',
                'details_blurb' => 'We are here to answer your questions and explore opportunities to work together.',
                'pillars' => [
                    ['icon' => 'lifebuoy', 'title' => 'Dedicated Support', 'text' => 'Our team is always ready to help.'],
                    ['icon' => 'clock', 'title' => 'Fast Response', 'text' => 'We respond within 24 business hours.'],
                    ['icon' => 'shield-check', 'title' => 'Trusted Partner', 'text' => 'Your success is our priority.'],
                    ['icon' => 'globe-alt', 'title' => 'Global Network', 'text' => 'Connecting verified buyers and suppliers worldwide.'],
                ],
                'cta_title' => 'Let’s build a sustainable future together',
                'cta_text' => 'Whether you are a supplier, buyer, investor or partner, we would like to hear from you and create value in the African timber industry.',
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
            'slug' => 'how-it-works',
            'title' => 'How Cameroon Timber Hub works',
            'h1' => 'How Cameroon Timber Hub works',
            'template' => 'static',
            'meta_description' => 'How buyers find verified Cameroonian timber suppliers, request quotes, and how exporters get listed and verified on Cameroon Timber Hub.',
            'is_published' => true,
            'data' => [
                'eyebrow' => 'How it works',
                'intro' => 'A straightforward path for buyers to find document-reviewed suppliers, and for suppliers to reach serious international buyers.',
                'pillars' => [
                    ['icon' => 'magnifying-glass', 'title' => 'Search & Compare', 'text' => 'Browse verified suppliers and products by species, region, and export market.'],
                    ['icon' => 'document-text', 'title' => 'Request a Quote', 'text' => 'Submit an RFQ once — it routes to matching suppliers so you don’t contact each one separately.'],
                    ['icon' => 'shield-check', 'title' => 'Trade with Confidence', 'text' => 'Every verified badge means the supplier’s documents have been reviewed by our team.'],
                    ['icon' => 'chat-bubble-left-right', 'title' => 'Connect Directly', 'text' => 'Message suppliers directly once you’ve found a match — no middleman markup.'],
                ],
                'blocks' => [
                    ['type' => 'heading', 'content' => 'For buyers'],
                    ['type' => 'list', 'items' => [
                        'Search the supplier directory or product marketplace by species, grade, or export market',
                        'Review a supplier\'s profile, verification status, and product catalogue',
                        'Submit a Request for Quote (RFQ) once — it is routed to matching verified suppliers',
                        'Compare responses and contact suppliers directly to negotiate and close the deal',
                    ]],
                    ['type' => 'heading', 'content' => 'For suppliers and exporters'],
                    ['type' => 'list', 'items' => [
                        'Register a free account and create your company profile',
                        'Add the species and products you supply, with photos and specifications',
                        'Upload your business registration, export permits, and legality documents for review',
                        'Once verified, your listing carries a badge and you start receiving RFQ leads',
                    ]],
                    ['type' => 'paragraph', 'content' => 'Verification means our team has reviewed the documents a company submitted and found them consistent with the stated credentials at the time of review. It is not a guarantee of a company\'s products, financial standing, or future conduct — buyers should always conduct their own final due diligence before any transaction.'],
                ],
            ],
        ],
        [
            'slug' => 'terms',
            'title' => 'Terms of Service',
            'h1' => 'Terms of Service',
            'template' => 'legal',
            'meta_description' => 'The terms and conditions governing use of the Cameroon Timber Hub platform by buyers, suppliers, and exporters.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'These Terms of Service ("Terms") govern access to and use of Cameroon Timber Hub (the "Platform"), operated to connect timber buyers with Cameroonian timber suppliers and exporters. By creating an account or using the Platform, you agree to these Terms.'],
                    ['type' => 'heading', 'content' => '1. The Platform'],
                    ['type' => 'paragraph', 'content' => 'Cameroon Timber Hub is a marketplace and directory. We facilitate discovery and introductions between buyers and suppliers — we are not a party to any transaction, sale, shipment, or contract agreed between users, and we do not take title to any goods listed.'],
                    ['type' => 'heading', 'content' => '2. Accounts'],
                    ['type' => 'list', 'items' => [
                        'You must provide accurate information when registering a company or buyer account',
                        'You are responsible for activity that occurs under your account and credentials',
                        'We may suspend or terminate accounts that provide false information, violate these Terms, or misuse the Platform',
                    ]],
                    ['type' => 'heading', 'content' => '3. Verification badges'],
                    ['type' => 'paragraph', 'content' => 'A verification badge indicates that our team has reviewed documents submitted by a supplier and found them consistent with the stated credentials at the time of review. It is not a guarantee, endorsement, or warranty of the supplier\'s products, financial standing, legal compliance, or future conduct. Buyers are responsible for conducting their own due diligence before entering into any transaction.'],
                    ['type' => 'heading', 'content' => '4. Listings and content'],
                    ['type' => 'paragraph', 'content' => 'Suppliers are responsible for the accuracy of their listings, product information, pricing, and documents. We may remove listings or content that we reasonably believe to be false, misleading, or in violation of applicable law.'],
                    ['type' => 'heading', 'content' => '5. No liability for transactions'],
                    ['type' => 'paragraph', 'content' => 'Cameroon Timber Hub is not responsible for the performance of any agreement between a buyer and a supplier, including quality, delivery, payment, customs, or dispute resolution. Any transaction entered into between users is solely between those users.'],
                    ['type' => 'heading', 'content' => '6. Acceptable use'],
                    ['type' => 'list', 'items' => [
                        'Do not use the Platform for illegal timber trade, fraud, or to circumvent export/import regulations',
                        'Do not scrape, resell, or misuse Platform data without permission',
                        'Do not impersonate another company or individual',
                    ]],
                    ['type' => 'heading', 'content' => '7. Changes to these Terms'],
                    ['type' => 'paragraph', 'content' => 'We may update these Terms from time to time. Continued use of the Platform after changes take effect constitutes acceptance of the revised Terms.'],
                    ['type' => 'heading', 'content' => '8. Contact'],
                    ['type' => 'paragraph', 'content' => 'Questions about these Terms can be sent through our Contact page.'],
                ],
            ],
        ],
        [
            'slug' => 'privacy',
            'title' => 'Privacy Policy',
            'h1' => 'Privacy Policy',
            'template' => 'legal',
            'meta_description' => 'How Cameroon Timber Hub collects, uses, and protects the personal and company data of buyers and suppliers on the platform.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'This Privacy Policy explains what information Cameroon Timber Hub collects, how we use it, and the choices you have. It applies to visitors, registered buyers, and registered suppliers/exporters.'],
                    ['type' => 'heading', 'content' => '1. Information we collect'],
                    ['type' => 'list', 'items' => [
                        'Account information: name, email, phone number, company name and details',
                        'Company verification documents: business registration, export permits, legality certificates',
                        'Usage data: pages visited, RFQs submitted, inquiries sent, and similar interaction data',
                        'Technical data: IP address and browser/user-agent, collected for security, fraud prevention, and consent records',
                    ]],
                    ['type' => 'heading', 'content' => '2. How we use information'],
                    ['type' => 'list', 'items' => [
                        'To operate the marketplace: matching RFQs to suppliers, displaying listings, and enabling messages between buyers and suppliers',
                        'To review and verify supplier documents',
                        'To communicate with you about your account, inquiries, and RFQs',
                        'To protect the Platform against fraud, abuse, and unauthorised access',
                    ]],
                    ['type' => 'heading', 'content' => '3. Sharing your information'],
                    ['type' => 'paragraph', 'content' => 'When you submit a Request for Quote or an inquiry, relevant details are shared with the supplier(s) it is routed to, so they can respond to you. We do not sell personal data to third parties. We may share information with service providers who help us operate the Platform (e.g. hosting, email delivery), bound by confidentiality obligations, or where required by law.'],
                    ['type' => 'heading', 'content' => '4. Consent'],
                    ['type' => 'paragraph', 'content' => 'Where we ask for your consent before sharing your inquiry or RFQ details with suppliers, we record that consent — including when it was given and, if applicable, when it was revoked — so both you and we have a reliable record.'],
                    ['type' => 'heading', 'content' => '5. Data retention'],
                    ['type' => 'paragraph', 'content' => 'We retain account and transaction data for as long as your account is active and as needed to comply with legal, accounting, or reporting obligations. Verification documents are retained for the duration of the review relationship and as required by applicable regulation.'],
                    ['type' => 'heading', 'content' => '6. Your choices'],
                    ['type' => 'list', 'items' => [
                        'You can request access to, correction of, or deletion of your personal data by contacting us',
                        'You can withdraw consent for future data sharing at any time; this does not affect processing already carried out',
                    ]],
                    ['type' => 'heading', 'content' => '7. Contact'],
                    ['type' => 'paragraph', 'content' => 'Questions about this Privacy Policy or your data can be sent through our Contact page.'],
                ],
            ],
        ],
        [
            'slug' => 'cookies',
            'title' => 'Cookies Policy',
            'h1' => 'Cookies Policy',
            'template' => 'legal',
            'meta_description' => 'How Cameroon Timber Hub uses cookies and similar technologies.',
            'is_published' => true,
            'data' => [
                'blocks' => [
                    ['type' => 'paragraph', 'content' => 'Cameroon Timber Hub uses cookies and similar technologies to keep you signed in, remember your preferences, and understand how the Platform is used so we can improve it.'],
                    ['type' => 'heading', 'content' => 'Types of cookies we use'],
                    ['type' => 'list', 'items' => [
                        'Essential cookies — required for login sessions, forms, and core site functionality; the Platform cannot function correctly without these',
                        'Preference cookies — remember settings such as your chosen theme',
                        'Analytics cookies — help us understand aggregate usage so we can improve the Platform',
                    ]],
                    ['type' => 'heading', 'content' => 'Managing cookies'],
                    ['type' => 'paragraph', 'content' => 'Most browsers let you block or delete cookies through their settings. Blocking essential cookies may prevent parts of the Platform, such as signing in, from working correctly.'],
                    ['type' => 'heading', 'content' => 'Changes to this policy'],
                    ['type' => 'paragraph', 'content' => 'We may update this Cookies Policy from time to time. Check back periodically for changes.'],
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
