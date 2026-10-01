<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommissionDepositResource;
use App\Http\Resources\Api\V1\CommissionStatementResource;
use App\Models\CommissionStatement;
use App\Models\Company;
use App\Models\User;
use App\Services\Commission\CommissionCollectionService;
use App\Services\SupplierApiScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Marketplace-commission statements of the caller's company — API
 * counterpart of the exporter panel's Commission page
 * ({@see \App\Filament\Exporter\Resources\CommissionStatements\CommissionStatementResource}).
 * Owner decision 2026-10-01: commission is paid by manual MTN MoMo / Orange
 * Money / bank deposit and REPORTED here for finance to verify.
 *
 * Scoped to the caller's first company ({@see SupplierApiScope::company()});
 * another company's statement number 404s. Open to pending-verification
 * companies too (they owe commission like anyone else). Writes go through
 * {@see CommissionCollectionService::report()} — the same path as the web form.
 */
class SupplierCommissionController extends Controller
{
    public function __construct(
        private readonly SupplierApiScope $scope,
        private readonly CommissionCollectionService $collection,
    ) {}

    /** GET /supplier/commission/statements — newest period first; ?status= filters. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'string', 'in:issued,partially_paid,paid,overdue,void,open']]);

        $status = $request->query('status');

        $statements = $this->statements($request->user())
            ->when($status === 'open', fn (Builder $q) => $q->open())
            ->when($status !== null && $status !== 'open', fn (Builder $q) => $q->where('status', $status))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate(15);

        return CommissionStatementResource::collection($statements);
    }

    /** GET /supplier/commission/statements/{number} — lines, deposits, payment instructions. */
    public function show(Request $request, string $number): CommissionStatementResource
    {
        return new CommissionStatementResource($this->statement($request->user(), $number)->load(['lines', 'deposits']));
    }

    /** POST /supplier/commission/statements/{number}/deposits (multipart; `proof` optional). */
    public function storeDeposit(Request $request, string $number): JsonResponse
    {
        $statement = $this->statement($request->user(), $number);

        $deposit = $this->collection->report(
            $statement,
            $request->user(),
            $request->only(['method', 'amount', 'currency', 'transaction_reference', 'paid_on', 'notes']),
            $request->file('proof'),
        );

        return response()->json(['data' => new CommissionDepositResource($deposit->fresh())], 201);
    }

    /** GET /supplier/commission/summary — outstanding per currency + overdue / quoting-blocked flags. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->collection->summary($this->company($request->user()))]);
    }

    private function company(User $user): Company
    {
        $company = $this->scope->company($user);
        abort_if($company === null, 404);

        return $company;
    }

    private function statements(User $user): Builder
    {
        return CommissionStatement::query()->forCompany($this->company($user));
    }

    private function statement(User $user, string $number): CommissionStatement
    {
        return $this->statements($user)->where('statement_number', $number)->firstOrFail();
    }
}
