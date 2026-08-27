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
                    ['type' => 'paragraph', 'content' => 'These Terms of Service ("Terms") govern access to and use of Cameroon Timber Hub (the "Platform"), operated to connect international timber buyers with Cameroonian timber suppliers and exporters. By creating an account, submitting a listing, or otherwise using the Platform, you agree to be bound by these Terms. If you are agreeing on behalf of a company, you confirm you have authority to bind that company.'],

                    ['type' => 'heading', 'content' => '1. What the Platform is'],
                    ['type' => 'paragraph', 'content' => 'Cameroon Timber Hub is a marketplace and directory. We facilitate discovery, document review, and introductions between buyers and suppliers of timber and timber products.'],
                    ['type' => 'list', 'items' => [
                        'We are not a party to any sale, shipment, purchase order, or contract agreed between a buyer and a supplier',
                        'We do not take title to, handle, inspect, or ship any goods listed on the Platform',
                        'We do not process payments between buyers and suppliers; any payment terms are agreed directly between the parties',
                        'Listings, prices, availability, and specifications are supplied by suppliers and are not independently verified by us unless explicitly stated',
                    ]],

                    ['type' => 'heading', 'content' => '2. Eligibility and accounts'],
                    ['type' => 'subheading', 'content' => 'Who can register'],
                    ['type' => 'paragraph', 'content' => 'You must be at least 18 years old and have the legal capacity to enter binding agreements to register an account, whether as a buyer or as a supplier/exporter representative.'],
                    ['type' => 'subheading', 'content' => 'Account responsibilities'],
                    ['type' => 'list', 'items' => [
                        'You must provide accurate, current information when registering a company or buyer account, and keep it up to date',
                        'You are responsible for all activity that occurs under your account and credentials, and for keeping your password confidential',
                        'You must notify us promptly if you suspect unauthorised use of your account',
                        'We may suspend or terminate accounts that provide false information, violate these Terms, or misuse the Platform, with or without notice depending on severity',
                    ]],

                    ['type' => 'heading', 'content' => '3. Verification badges'],
                    ['type' => 'paragraph', 'content' => 'A verification badge indicates that our team has reviewed documents submitted by a supplier — such as business registration, export permits, and legality/compliance certificates — and found them consistent with the stated credentials at the time of review.'],
                    ['type' => 'list', 'items' => [
                        'A badge is not a guarantee, endorsement, or warranty of the supplier\'s products, financial standing, legal compliance, or future conduct',
                        'A badge reflects a point-in-time review; documents can expire, and badges are revoked or lapse if not renewed',
                        'Buyers are responsible for conducting their own due diligence before entering into any transaction, verified badge or not',
                    ]],

                    ['type' => 'heading', 'content' => '4. Listings, RFQs, and content'],
                    ['type' => 'paragraph', 'content' => 'Suppliers are solely responsible for the accuracy of their listings, product information, pricing, species claims, and uploaded documents. Buyers are responsible for the accuracy of information submitted in Requests for Quote (RFQs) and inquiries.'],
                    ['type' => 'list', 'items' => [
                        'We may remove, edit, or decline to publish listings or content we reasonably believe to be false, misleading, infringing, or in violation of applicable law',
                        'We may route an RFQ to multiple matching suppliers at once; submitting an RFQ does not create an exclusive arrangement with any one supplier',
                        'Content you submit (text, photos, documents) must be your own or used with permission, and must not infringe any third party\'s rights',
                    ]],

                    ['type' => 'heading', 'content' => '5. No liability for transactions between users'],
                    ['type' => 'paragraph', 'content' => 'Cameroon Timber Hub is not responsible for the performance of any agreement between a buyer and a supplier, including product quality, quantity, delivery timing, customs clearance, payment, or dispute resolution. Any transaction entered into between users is solely between those users, at their own risk.'],
                    ['type' => 'paragraph', 'content' => 'To the maximum extent permitted by applicable law, Cameroon Timber Hub disclaims liability for any loss or damage arising from a transaction, communication, or dealing between users of the Platform.'],

                    ['type' => 'heading', 'content' => '6. Acceptable use'],
                    ['type' => 'paragraph', 'content' => 'You agree not to:'],
                    ['type' => 'list', 'items' => [
                        'Use the Platform for illegal timber trade, fraud, or to circumvent export/import regulations or sanctions',
                        'Scrape, harvest, resell, or otherwise misuse Platform data without our prior written permission',
                        'Impersonate another company, individual, or misrepresent your affiliation with any entity',
                        'Upload malicious code, attempt to disrupt the Platform, or bypass its security or access controls',
                        'Post content that is unlawful, defamatory, or infringes another party\'s intellectual property',
                    ]],

                    ['type' => 'heading', 'content' => '7. Intellectual property'],
                    ['type' => 'paragraph', 'content' => 'The Platform\'s software, design, and branding are owned by Cameroon Timber Hub or its licensors. Content you submit (listings, documents, photos) remains yours, but you grant us a licence to display and use it on the Platform for the purpose of operating the marketplace.'],

                    ['type' => 'heading', 'content' => '8. Termination'],
                    ['type' => 'paragraph', 'content' => 'You may close your account at any time by contacting us. We may suspend or terminate access to the Platform for any account that breaches these Terms, poses a risk to other users, or on reasonable notice for any other reason.'],

                    ['type' => 'heading', 'content' => '9. Changes to these Terms'],
                    ['type' => 'paragraph', 'content' => 'We may update these Terms from time to time to reflect changes to the Platform or applicable law. The "Last updated" date at the top of this page reflects the most recent revision. Continued use of the Platform after changes take effect constitutes acceptance of the revised Terms.'],

                    ['type' => 'heading', 'content' => '10. Governing law'],
                    ['type' => 'paragraph', 'content' => 'These Terms are governed by the laws of the Republic of Cameroon, without regard to conflict-of-law principles, unless otherwise required by applicable law in your jurisdiction.'],

                    ['type' => 'heading', 'content' => '11. Contact'],
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
                    ['type' => 'paragraph', 'content' => 'This Privacy Policy explains what information Cameroon Timber Hub collects, how we use it, who we share it with, and the choices you have. It applies to visitors, registered buyers, and registered suppliers/exporters using the Platform.'],

                    ['type' => 'heading', 'content' => '1. Information we collect'],
                    ['type' => 'subheading', 'content' => 'Information you give us'],
                    ['type' => 'list', 'items' => [
                        'Account information: name, email, phone number, company name and business details',
                        'Company verification documents: business registration, export permits, phytosanitary and legality certificates',
                        'Listing content: product descriptions, species, pricing, and photographs you upload',
                        'RFQ and inquiry details: quantities, specifications, delivery preferences, and messages you send to suppliers',
                    ]],
                    ['type' => 'subheading', 'content' => 'Information collected automatically'],
                    ['type' => 'list', 'items' => [
                        'Usage data: pages visited, searches performed, RFQs submitted, and similar interaction data',
                        'Technical data: IP address, browser and device type, collected for security, fraud prevention, and to maintain consent and evidence records',
                    ]],

                    ['type' => 'heading', 'content' => '2. How we use your information'],
                    ['type' => 'list', 'items' => [
                        'To operate the marketplace: matching RFQs to suppliers, displaying listings, and enabling messages between buyers and suppliers',
                        'To review and verify supplier documents and issue or revoke verification badges',
                        'To communicate with you about your account, inquiries, RFQs, and platform updates',
                        'To protect the Platform and its users against fraud, abuse, and unauthorised access',
                        'To comply with legal, accounting, and regulatory obligations',
                    ]],

                    ['type' => 'heading', 'content' => '3. Sharing your information'],
                    ['type' => 'paragraph', 'content' => 'When you submit a Request for Quote or an inquiry, the relevant contact and requirement details are shared with the supplier(s) it is routed to, so they can respond to you directly. This is the core function of the marketplace — without this sharing, suppliers could not respond to your request.'],
                    ['type' => 'list', 'items' => [
                        'We do not sell your personal data to third parties',
                        'We may share information with service providers who help us operate the Platform (for example, hosting and email delivery), bound by confidentiality obligations',
                        'We may disclose information where required by law, regulation, or a valid legal process',
                    ]],

                    ['type' => 'heading', 'content' => '4. Consent'],
                    ['type' => 'paragraph', 'content' => 'Where we ask for your consent before sharing your inquiry or RFQ details with suppliers, we record that consent as a structured entry — including when it was given, its scope, and, if applicable, when it was revoked — so both you and we have a reliable, inspectable record rather than an assumption.'],
                    ['type' => 'paragraph', 'content' => 'You can withdraw consent for future sharing at any time by contacting us; this does not affect sharing that already took place before the withdrawal.'],

                    ['type' => 'heading', 'content' => '5. Data retention'],
                    ['type' => 'paragraph', 'content' => 'We retain account and transaction data for as long as your account is active and as needed to comply with legal, accounting, or reporting obligations. Verification documents are retained for the duration of the review relationship and for the period required by applicable regulation. When data is no longer needed for these purposes, we take reasonable steps to delete or anonymise it.'],

                    ['type' => 'heading', 'content' => '6. Data security'],
                    ['type' => 'paragraph', 'content' => 'We use reasonable technical and organisational measures to protect your information, including access controls on verification documents and encrypted connections to the Platform. No method of transmission or storage is completely secure, and we cannot guarantee absolute security.'],

                    ['type' => 'heading', 'content' => '7. Your choices and rights'],
                    ['type' => 'list', 'items' => [
                        'You can request access to, correction of, or deletion of your personal data by contacting us',
                        'You can update most account information directly from your dashboard',
                        'You can withdraw consent for future data sharing at any time; this does not affect processing already carried out',
                        'You can close your account at any time; some information may be retained where required by law',
                    ]],

                    ['type' => 'heading', 'content' => '8. International transfers'],
                    ['type' => 'paragraph', 'content' => 'Because the Platform connects buyers and suppliers across borders, information you submit (such as an RFQ) may be seen by a supplier located in a different country from you. We take reasonable steps to ensure information is handled consistently with this Policy wherever it is processed.'],

                    ['type' => 'heading', 'content' => '9. Contact'],
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
                    ['type' => 'paragraph', 'content' => 'Cameroon Timber Hub uses cookies and similar technologies (such as local storage) to keep you signed in, remember your preferences, and understand how the Platform is used so we can improve it. This policy explains what these technologies are, which ones we use, and how you can control them.'],

                    ['type' => 'heading', 'content' => 'What cookies are'],
                    ['type' => 'paragraph', 'content' => 'Cookies are small text files placed on your device when you visit a website. They allow the site to recognise your device and remember information about your visit, such as your preferences and whether you are signed in.'],

                    ['type' => 'heading', 'content' => 'Types of cookies we use'],
                    ['type' => 'subheading', 'content' => 'Essential cookies'],
                    ['type' => 'paragraph', 'content' => 'Required for login sessions, form submission (including CSRF protection), and core site functionality. The Platform cannot function correctly without these, and they cannot be switched off through our systems.'],
                    ['type' => 'subheading', 'content' => 'Preference cookies'],
                    ['type' => 'paragraph', 'content' => 'Remember settings such as your chosen light/dark theme, so you don\'t have to reset it on every visit.'],
                    ['type' => 'subheading', 'content' => 'Analytics cookies'],
                    ['type' => 'paragraph', 'content' => 'Help us understand aggregate usage — which pages are visited, how the marketplace is used — so we can find and fix problems and improve the Platform. We use this data in aggregate and do not use it to build advertising profiles.'],

                    ['type' => 'heading', 'content' => 'Cookies set by other services'],
                    ['type' => 'paragraph', 'content' => 'Some pages may embed or link to third-party content (for example, a map or an external verification tool). Those third parties may set their own cookies, governed by their own privacy and cookie policies, which we do not control.'],

                    ['type' => 'heading', 'content' => 'Managing cookies'],
                    ['type' => 'paragraph', 'content' => 'Most browsers let you view, block, or delete cookies through their settings. Blocking essential cookies may prevent parts of the Platform — such as signing in or submitting an RFQ — from working correctly.'],

                    ['type' => 'heading', 'content' => 'Changes to this policy'],
                    ['type' => 'paragraph', 'content' => 'We may update this Cookies Policy from time to time to reflect changes in the technologies we use or applicable law. The "Last updated" date at the top of this page reflects the most recent revision.'],

                    ['type' => 'heading', 'content' => 'Contact'],
                    ['type' => 'paragraph', 'content' => 'Questions about this Cookies Policy can be sent through our Contact page.'],
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
