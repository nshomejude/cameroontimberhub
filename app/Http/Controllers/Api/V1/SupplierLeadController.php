<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Lead\UpdateLeadStatus;
use App\Enums\LeadStatus;
use App\Enums\RfqType;
use App\Exceptions\Api\CompanyVerificationRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The supplier's lead pipeline — API counterpart of the exporter panel's
 * {@see \App\Filament\Exporter\Resources\Leads\LeadResource}: list (filter
 * by status / source / RFQ type), view, and update status + private notes +
 * estimated value. No create/delete, exactly like the web resource.
 *
 * Scoping mirrors `LeadResource::getEloquentQuery()` — leads of ANY company
 * the caller is a member of (`Company::dashboardOwned`); anything else 404s.
 * Status changes go through {@see UpdateLeadStatus} (LeadFlowService), which
 * — like the web form — allows moving to any LeadStatus and stamps
 * `last_activity_at`.
 */
class SupplierLeadController extends Controller
{
    public function __construct(private readonly UpdateLeadStatus $updateStatus) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LeadStatus::class)],
            'source' => ['nullable', 'string', 'in:rfq,inquiry,manual'],
            'rfq_type' => ['nullable', Rule::enum(RfqType::class)],
        ]);

        $leads = $this->leads($request->user())
            ->with('rfq')
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['source'] ?? null, fn (Builder $q, string $s) => $q->where('source', $s))
            ->when($filters['rfq_type'] ?? null, fn (Builder $q, string $t) => $q->whereHas('rfq', fn (Builder $r) => $r->where('type', $t)))
            ->orderByDesc('created_at')
            ->paginate(15);

        return LeadResource::collection($leads);
    }

    public function show(Request $request, int|string $lead): LeadResource
    {
        return new LeadResource($this->lead($request->user(), $lead)->load('rfq'));
    }

    public function update(Request $request, int|string $lead): LeadResource
    {
        $record = $this->lead($request->user(), $lead);

        // Leads of a company pending verification are read-only.
        CompanyVerificationRequiredException::unless($record->company);

        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::enum(LeadStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'value_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
        ]);

        DB::transaction(function () use ($record, $data) {
            if (array_key_exists('status', $data)) {
                $this->updateStatus->execute($record, LeadStatus::from($data['status']));
            }

            $fields = array_intersect_key($data, array_flip(['notes', 'value_amount']));

            if ($fields !== []) {
                // Same as EditLead::mutateFormDataBeforeSave().
                $record->update([...$fields, 'last_activity_at' => now()]);
            }
        });

        return new LeadResource($record->fresh()->load('rfq'));
    }

    private function leads(User $user): Builder
    {
        return Lead::query()->whereHas('company', fn (Builder $c) => $c->dashboardOwned($user));
    }

    private function lead(User $user, int|string $id): Lead
    {
        return $this->leads($user)->whereKey((int) $id)->firstOrFail();
    }
}
