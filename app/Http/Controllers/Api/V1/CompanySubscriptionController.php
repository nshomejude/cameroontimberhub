<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Commerce\Queries\GetCompanySubscriptionQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanyContextPayload;
use App\Services\SupplierApiScope;
use App\Support\Bus\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only `GET company/subscription` — the data the exporter panel's
 * {@see \App\Filament\Exporter\Pages\SubscriptionStatus} page shows (active
 * subscription via GetCompanySubscriptionQuery, the plan it displays), plus
 * the effective plan the app gates features on. Upgrades stay on the web
 * pricing page (`pricing_url`).
 */
class CompanySubscriptionController extends Controller
{
    public function __construct(
        private readonly SupplierApiScope $scope,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->scope->company($request->user());
        abort_if($company === null, 404);

        $subscription = $this->queries->dispatch(new GetCompanySubscriptionQuery($company->getKey()));

        return response()->json(['data' => CompanyContextPayload::subscription($company, $subscription)]);
    }
}
