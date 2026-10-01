<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Enums\SupplierType;
use App\Http\Requests\Api\V1\StoreSupplierProductRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Support\Agent\AgentPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The single write path of the Agent Ingestion Gateway
 * (docs/api/AGENT_INGESTION.md). Controllers (single + batch) only shape
 * HTTP; every rule lives here:
 *
 *  - Everything an agent writes is hidden: companies are created `draft`
 *    with `needs_review = true` (Company::publiclyVisible() additionally needs
 *    verified status + an active badge), products are always `draft`.
 *  - Idempotent upsert by (source, external_id) — `source` is derived from
 *    the agent key (`agent:{slug}`), never from the payload.
 *  - Status / verification / featuring fields are prohibited.
 *  - Duplicate detection against existing companies; an agent may never
 *    write into a human-owned (non agent-sourced, or claimed) company.
 *  - Every write is activity-logged with the agent user as causer.
 *
 * Each method returns an outcome array instead of throwing so the batch
 * endpoint can report per-item results:
 *   ['result' => created|updated|duplicate|rejected, 'status' => int,
 *    'company'|'product' => model|null, 'existing_id' => ?int,
 *    'code' => ?string, 'message' => ?string, 'errors' => ?array]
 */
class AgentIngestionService
{
    /** Free-mail domains never used as a duplicate signal. */
    private const GENERIC_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.com', 'yahoo.fr', 'hotmail.com', 'hotmail.fr', 'outlook.com',
        'live.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com',
    ];

    /** Payload keys an agent may never set. */
    private const PROHIBITED_FIELDS = [
        'status', 'verified_at', 'verification_expires_at', 'verification_tier', 'is_featured',
        'plan_id', 'created_by', 'source', 'ingested_by_token_id', 'needs_review', 'slug',
        'company_id', 'is_best_seller', 'rating',
    ];

    /**
     * Organisation types an agent may set. Carbon types are excluded: carbon
     * developers are onboarded by humans only (feature-flagged module).
     *
     * @return list<string>
     */
    public static function agentOrganisationTypeValues(): array
    {
        return array_values(array_diff(OrganisationType::values(), [OrganisationType::CarbonDeveloper->value]));
    }

    /** @return array<string, mixed> */
    public function supplierRules(): array
    {
        return array_merge($this->prohibitedRules(), [
            'external_id' => ['required', 'string', 'max:191'],
            'legal_name' => ['required_without:name', 'nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'supplier_type' => ['nullable', Rule::in(SupplierType::values())],
            'type' => ['nullable', Rule::in(self::agentOrganisationTypeValues())],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'species' => ['nullable', 'array', 'max:50'],
            'species.*' => ['required'],
            'export_markets' => ['nullable', 'array', 'max:100'],
            'export_markets.*' => ['string', 'size:2'],
            'contacts' => ['nullable', 'array', 'max:10'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.role' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:32'],
            'contacts.*.whatsapp' => ['nullable', 'string', 'max:32'],
            ...$this->provenanceRules(),
        ]);
    }

    /**
     * The supplier product rule set (StoreSupplierProductRequest, the same
     * one the supplier API/web form use) minus `status`, plus provenance.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function productRules(array $data): array
    {
        $base = (new StoreSupplierProductRequest)->merge(['product_type' => $data['product_type'] ?? null])->rules();
        unset($base['status']);

        return array_merge($base, $this->prohibitedRules(), [
            'external_id' => ['required', 'string', 'max:191'],
            ...$this->provenanceRules(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function upsertSupplier(array $input, AgentContext $ctx, bool $dryRun = false): array
    {
        $input = $this->resolveSpeciesInput($input);
        $validator = Validator::make($input, $this->supplierRules());

        if ($validator->fails()) {
            return $this->rejected($validator->errors()->toArray());
        }

        $data = $validator->validated();

        $existing = Company::withTrashed()
            ->where('source', $ctx->source)
            ->where('external_id', $data['external_id'])
            ->first();

        if ($existing !== null) {
            if ($blocked = $this->companyWriteBlock($existing)) {
                return $blocked;
            }

            if ($dryRun) {
                return ['result' => 'updated', 'status' => 200, 'company' => $existing, 'dry_run' => true];
            }

            $company = DB::transaction(fn () => $this->writeCompany($existing, $data, $ctx));
            $this->log($company, 'agent_supplier_updated', $ctx);

            return ['result' => 'updated', 'status' => 200, 'company' => $company];
        }

        if ($duplicate = $this->findDuplicate($data)) {
            // Report the real reason: owned/claimed (supplier_owned), in review
            // or verified (supplier_locked), or rejected/archived (supplier_closed).
            if ($blocked = $this->companyWriteBlock($duplicate)) {
                return $blocked;
            }

            return [
                'result' => 'duplicate', 'status' => 200, 'company' => $duplicate, 'existing_id' => $duplicate->getKey(),
                'message' => 'A matching agent-sourced supplier already exists; attach products to existing_id.',
            ];
        }

        if ($dryRun) {
            return ['result' => 'created', 'status' => 201, 'company' => null, 'dry_run' => true];
        }

        $company = DB::transaction(fn () => $this->writeCompany(new Company, $data, $ctx));
        $this->log($company, 'agent_supplier_created', $ctx);

        return ['result' => 'created', 'status' => 201, 'company' => $company];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function upsertProduct(?Company $company, array $input, AgentContext $ctx, bool $dryRun = false): array
    {
        if ($company !== null && ($blocked = $this->companyProductBlock($company))) {
            return $blocked;
        }

        $input = $this->resolveSpeciesInput($input, single: true);
        $validator = Validator::make($input, $this->productRules($input));

        if ($validator->fails()) {
            return $this->rejected($validator->errors()->toArray());
        }

        $data = $validator->validated();

        if ($company === null) {
            // Dry run of a not-yet-created supplier: validation is all we can do.
            return ['result' => 'created', 'status' => 201, 'product' => null, 'dry_run' => true];
        }

        $existing = Product::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('source', $ctx->source)
            ->where('external_id', $data['external_id'])
            ->first();

        if ($existing !== null && $existing->status === ProductStatus::Active) {
            return [
                'result' => 'rejected', 'status' => 409, 'code' => 'product_locked',
                'message' => 'This product has been published by staff and can no longer be changed by an agent.',
                'existing_id' => $existing->getKey(),
            ];
        }

        $result = $existing ? 'updated' : 'created';

        if ($dryRun) {
            return ['result' => $result, 'status' => $existing ? 200 : 201, 'product' => $existing, 'dry_run' => true];
        }

        $product = DB::transaction(fn () => $this->writeProduct($existing ?? new Product, $company, $data, $ctx));
        $this->log($product, 'agent_product_'.$result, $ctx, ['company_id' => $company->getKey()]);

        return ['result' => $result, 'status' => $existing ? 200 : 201, 'product' => $product];
    }

    /**
     * Why an agent may not attach products to this company, or null.
     *
     * @return array<string, mixed>|null
     */
    public function companyProductBlock(Company $company): ?array
    {
        if (! AgentPrincipal::isAgentSource($company->source) || $company->users()->exists()) {
            return [
                'result' => 'rejected', 'status' => 409, 'code' => 'supplier_owned',
                'message' => 'Agents may only add products to agent-sourced, unclaimed suppliers.',
                'existing_id' => $company->getKey(),
            ];
        }

        if (in_array($company->status, [CompanyStatus::Archived, CompanyStatus::Rejected, CompanyStatus::Suspended], true) || $company->trashed()) {
            return [
                'result' => 'rejected', 'status' => 409, 'code' => 'supplier_closed',
                'message' => 'This supplier was rejected or archived by staff.',
                'existing_id' => $company->getKey(),
            ];
        }

        return null;
    }

    /**
     * Why an agent may not UPDATE this company's profile, or null. Claimed,
     * human-created, staff-verified or closed companies are frozen.
     *
     * @return array<string, mixed>|null
     */
    private function companyWriteBlock(Company $company): ?array
    {
        if ($block = $this->companyProductBlock($company)) {
            return $block;
        }

        if ($company->status !== CompanyStatus::Draft) {
            return [
                'result' => 'rejected', 'status' => 409, 'code' => 'supplier_locked',
                'message' => 'This supplier is in staff review or verified; its profile can no longer be changed by an agent.',
                'existing_id' => $company->getKey(),
            ];
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    private function findDuplicate(array $data): ?Company
    {
        $query = Company::query();

        if (filled($data['registration_number'] ?? null)) {
            $match = (clone $query)->whereRaw('lower(trim(registration_number)) = ?', [Str::lower(trim($data['registration_number']))])->first();
            if ($match) {
                return $match;
            }
        }

        $name = $this->normalizeName((string) ($data['legal_name'] ?? $data['name'] ?? ''));
        if ($name !== '' && filled($data['city'] ?? null)) {
            $city = Str::lower(Str::ascii(trim($data['city'])));
            $match = (clone $query)
                ->whereRaw('(lower(trim(city)) = ? OR lower(trim(city)) = ?)', [$city, Str::lower(trim($data['city']))])
                ->get(['id', 'legal_name', 'trade_name', 'city', 'source', 'status', 'deleted_at'])
                ->first(fn (Company $c) => $this->normalizeName((string) $c->legal_name) === $name
                    || ($c->trade_name && $this->normalizeName((string) $c->trade_name) === $name));
            if ($match) {
                return Company::find($match->getKey());
            }
        }

        foreach ($this->domainsOf($data) as $domain) {
            $match = (clone $query)
                ->where(fn ($q) => $q->whereRaw('lower(email) like ?', ['%@'.$domain])
                    ->orWhereRaw('lower(website_url) ~ ?', ['^(https?://)?(www\.)?'.preg_quote($domain).'(/|:|$)']))
                ->first();
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function domainsOf(array $data): array
    {
        $domains = [];

        if (filled($data['email'] ?? null) && str_contains($data['email'], '@')) {
            $domains[] = Str::lower(Str::after($data['email'], '@'));
        }

        if (filled($data['website_url'] ?? null)) {
            $host = parse_url($data['website_url'], PHP_URL_HOST);
            if (is_string($host)) {
                $domains[] = Str::lower(preg_replace('/^www\./i', '', $host));
            }
        }

        return array_values(array_unique(array_filter(
            $domains,
            fn (string $d) => $d !== '' && ! in_array($d, self::GENERIC_EMAIL_DOMAINS, true),
        )));
    }

    private function normalizeName(string $name): string
    {
        $n = Str::lower(Str::ascii($name));
        $n = preg_replace('/\b(sarl|sa|sas|ltd|limited|plc|llc|inc|ets|etablissements?|group|groupe|company|co|cie)\b\.?/', ' ', $n);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', (string) $n));
    }

    /** @param  array<string, mixed>  $data */
    private function writeCompany(Company $company, array $data, AgentContext $ctx): Company
    {
        $isNew = ! $company->exists;

        $attributes = collect($data)->only([
            'legal_name', 'trade_name', 'description', 'supplier_type', 'type', 'email', 'phone',
            'website_url', 'address_line', 'city', 'region', 'country_code', 'latitude', 'longitude',
            'registration_number', 'source_url',
        ])->all();

        $attributes['legal_name'] = $data['legal_name'] ?? $data['name'];
        $attributes['country_code'] = strtoupper($data['country_code'] ?? 'CM');

        $company->forceFill($attributes + [
            'source' => $ctx->source,
            'external_id' => $data['external_id'],
            'ingested_by_token_id' => $ctx->tokenId,
            'ingested_at' => now(),
            'ingestion_meta' => $this->ingestionMeta($data, $company->ingestion_meta),
            'needs_review' => true,
        ]);

        if ($isNew) {
            $company->forceFill([
                'status' => CompanyStatus::Draft,
                'created_by' => $ctx->user->getKey(),
            ]);
        }

        $company->save();

        if (array_key_exists('species', $data) && is_array($data['species'])) {
            $company->species()->sync($data['species']);
        }

        if (array_key_exists('export_markets', $data) && is_array($data['export_markets'])) {
            $company->exportMarkets()->delete();
            foreach (array_unique(array_map('strtoupper', $data['export_markets'])) as $code) {
                $company->exportMarkets()->create(['country_code' => $code]);
            }
        }

        if (array_key_exists('contacts', $data) && is_array($data['contacts'])) {
            $company->contacts()->delete();
            foreach (array_values($data['contacts']) as $i => $contact) {
                $company->contacts()->create([
                    'name' => $contact['name'],
                    'title' => $contact['role'] ?? null,
                    'email' => $contact['email'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'whatsapp' => $contact['whatsapp'] ?? null,
                    'is_public' => false,
                    'sort_order' => $i,
                ]);
            }
        }

        return $company->refresh();
    }

    /** @param  array<string, mixed>  $data */
    private function writeProduct(Product $product, Company $company, array $data, AgentContext $ctx): Product
    {
        $attributes = collect($data)->except([
            'external_id', 'source_url', 'evidence', 'confidence', 'notes', 'image_url',
            ...self::PROHIBITED_FIELDS,
        ])->all();

        $product->forceFill($attributes + [
            'company_id' => $company->getKey(),
            'status' => ProductStatus::Draft,
            'source' => $ctx->source,
            'external_id' => $data['external_id'],
            'source_url' => $data['source_url'] ?? null,
            'ingested_by_token_id' => $ctx->tokenId,
            'ingested_at' => now(),
            'ingestion_meta' => $this->ingestionMeta($data, $product->ingestion_meta),
            'needs_review' => true,
        ]);

        if ($product->trashed()) {
            $product->deleted_at = null;
        }

        $product->save();

        return $product->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function ingestionMeta(array $data, ?array $previous): array
    {
        return array_filter(array_merge($previous ?? [], [
            'evidence' => $data['evidence'] ?? ($previous['evidence'] ?? null),
            'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : ($previous['confidence'] ?? null),
            'notes' => $data['notes'] ?? ($previous['notes'] ?? null),
            'image_url' => $data['image_url'] ?? ($previous['image_url'] ?? null),
        ]), fn ($v) => $v !== null);
    }

    /**
     * Accepts species as ids or slugs. For suppliers `species` is a list;
     * for products `species_id` may also be a slug (or `species` a slug).
     * Unknown values are left as-is so validation reports them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function resolveSpeciesInput(array $input, bool $single = false): array
    {
        if ($single) {
            $value = $input['species_id'] ?? $input['species'] ?? null;
            unset($input['species']);

            if (is_string($value) && ! ctype_digit($value)) {
                $value = Species::query()->where('slug', $value)->value('id') ?? -1;
            }

            if ($value !== null) {
                $input['species_id'] = is_numeric($value) ? (int) $value : $value;
            }

            return $input;
        }

        if (isset($input['species']) && is_array($input['species'])) {
            $ids = [];
            $unknown = [];
            foreach ($input['species'] as $s) {
                $id = is_numeric($s)
                    ? Species::query()->whereKey((int) $s)->value('id')
                    : Species::query()->where('slug', (string) $s)->value('id');
                $id ? $ids[] = (int) $id : $unknown[] = $s;
            }

            if ($unknown !== []) {
                $input['_unknown_species'] = $unknown;
            }
            $input['species'] = array_values(array_unique($ids));
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function prohibitedRules(): array
    {
        return array_fill_keys(self::PROHIBITED_FIELDS, ['prohibited'])
            + ['_unknown_species' => ['prohibited']];
    }

    /** @return array<string, mixed> */
    private function provenanceRules(): array
    {
        return [
            'source_url' => ['nullable', 'url', 'max:2048'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'evidence' => ['nullable'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, mixed>
     */
    private function rejected(array $errors): array
    {
        if (isset($errors['_unknown_species'])) {
            $errors['species'] = ['One or more species ids/slugs are unknown. See GET /api/v1/agent/reference.'];
            unset($errors['_unknown_species']);
        }

        return [
            'result' => 'rejected', 'status' => 422, 'code' => 'validation_failed',
            'message' => 'The given data was invalid.', 'errors' => $errors,
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private function log(Company|Product $subject, string $event, AgentContext $ctx, array $extra = []): void
    {
        activity('agent-ingestion')
            ->performedOn($subject)
            ->causedBy($ctx->user)
            ->event($event)
            ->withProperties($ctx->logProperties($extra + ['external_id' => $subject->external_id]))
            ->log(str_replace('_', ' ', $event));
    }

    /** Record an upload against the subject in the activity log. */
    public function logUpload(Company|Product $subject, AgentContext $ctx, string $path): void
    {
        $this->log($subject, $subject instanceof Company ? 'agent_supplier_logo_uploaded' : 'agent_product_image_uploaded', $ctx, ['path' => $path]);
    }
}
