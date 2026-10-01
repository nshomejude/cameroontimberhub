<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Actions\Company\ArchiveCompany;
use App\Actions\Company\SubmitCompanyForReview;
use App\Domain\Catalog\Commands\PublishProductCommand;
use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\Agent\AgentPrincipal;
use App\Support\Bus\CommandBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff moderation of Agent Ingestion Gateway submissions (admin Companies
 * resource → "Agent submissions" tab). Lifecycle:
 *
 *   agent creates (draft, needs_review, hidden)
 *     → approve(): needs_review cleared, company enters the EXISTING
 *       verification flow (SubmitCompanyForReview: draft → pending + a
 *       VerificationRequest), where staff verify it as for any supplier
 *     → (after verification) publishProducts(): reviewed draft products → active
 *   or reject(): archived (company + its agent products), never shown.
 *
 * attachOwner() is the "claim" path: a real supplier user becomes the owner,
 * after which agents can no longer modify the company or add products.
 */
class ModerateAgentSubmission
{
    public function __construct(
        private readonly SubmitCompanyForReview $submit,
        private readonly ArchiveCompany $archive,
        private readonly CommandBus $bus,
    ) {}

    public function approve(Company $company, User $actor): Company
    {
        $this->assertAgentSourced($company);

        return DB::transaction(function () use ($company, $actor): Company {
            $company->forceFill(['needs_review' => false])->save();

            if (in_array($company->status, [CompanyStatus::Draft, CompanyStatus::Rejected], true)) {
                $this->submit->execute($company, $actor);
            }

            $this->log($company, $actor, 'agent_submission_approved');

            return $company->refresh();
        });
    }

    /** Publish the company's agent-sourced draft products. Only once the company is verified. */
    public function publishProducts(Company $company, User $actor): int
    {
        $this->assertAgentSourced($company);

        if ($company->status !== CompanyStatus::Verified) {
            throw ValidationException::withMessages([
                'company' => 'Verify the company before publishing its agent-submitted products.',
            ]);
        }

        $products = $company->products()
            ->where('status', ProductStatus::Draft->value)
            ->where('source', 'like', AgentPrincipal::SOURCE_PREFIX.'%')
            ->get();

        foreach ($products as $product) {
            /** @var Product $product */
            $this->bus->dispatch(new PublishProductCommand(
                ['status' => ProductStatus::Active->value, 'needs_review' => false],
                $product->getKey(),
            ));
        }

        $this->log($company, $actor, 'agent_products_published', ['count' => $products->count()]);

        return $products->count();
    }

    public function reject(Company $company, User $actor, ?string $reason = null): Company
    {
        $this->assertAgentSourced($company);

        return DB::transaction(function () use ($company, $actor, $reason): Company {
            $company->products()
                ->where('source', 'like', AgentPrincipal::SOURCE_PREFIX.'%')
                ->where('status', '!=', ProductStatus::Archived->value)
                ->update(['status' => ProductStatus::Archived->value, 'needs_review' => false]);

            $company->forceFill(['needs_review' => false])->save();
            $this->archive->execute($company, $actor);

            $this->log($company, $actor, 'agent_submission_rejected', ['reason' => $reason]);

            return $company->refresh();
        });
    }

    /** Claim: make an existing (non-agent) user the company's owner. */
    public function attachOwner(Company $company, string $email, User $actor): User
    {
        $this->assertAgentSourced($company);

        $owner = User::query()->where('email', $email)->first();

        if ($owner === null || $owner->hasRole(AgentPrincipal::ROLE)) {
            throw ValidationException::withMessages(['email' => 'No eligible user account with that email.']);
        }

        DB::transaction(function () use ($company, $owner, $actor): void {
            $company->users()->syncWithoutDetaching([
                $owner->getKey() => ['role' => CompanyUserRole::Owner->value, 'is_primary' => true],
            ]);
            $company->forceFill(['created_by' => $owner->getKey()])->save();

            $this->log($company, $actor, 'agent_submission_claimed', ['owner_id' => $owner->getKey()]);
        });

        return $owner;
    }

    private function assertAgentSourced(Company $company): void
    {
        if (! AgentPrincipal::isAgentSource($company->source)) {
            throw ValidationException::withMessages(['company' => 'This company was not submitted by an agent.']);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function log(Company $company, User $actor, string $event, array $extra = []): void
    {
        activity('agent-ingestion')
            ->performedOn($company)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($extra + ['source' => $company->source, 'external_id' => $company->external_id])
            ->log(str_replace('_', ' ', $event));
    }
}
