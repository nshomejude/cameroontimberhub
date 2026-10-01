<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CapacityResource;
use App\Models\Capacity;
use App\Models\Company;
use App\Models\User;
use App\Services\SupplierApiScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Declared capacities of the caller's company — API counterpart of the
 * exporter panel's {@see \App\Filament\Exporter\Resources\Capacities\CapacityResource}
 * (open to every company type, like the web resource).
 *
 * Scoping mirrors `CapacityResource::getEloquentQuery()`: the polymorphic
 * `owner_type = Company / owner_id = caller's first company`
 * ({@see SupplierApiScope::company()}); another owner's id 404s. Fields and
 * rules mirror `CapacityForm` (free-text capability, quantity >= 0.01, unit,
 * period in day/week/month/quarter/year).
 */
class SupplierCapacityController extends Controller
{
    public const PERIODS = ['day', 'week', 'month', 'quarter', 'year'];

    public function __construct(private readonly SupplierApiScope $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CapacityResource::collection(
            $this->capacities($request->user())->orderByDesc('created_at')->paginate(15),
        );
    }

    public function store(Request $request): CapacityResource
    {
        $company = $this->company($request->user());
        $data = $request->validate($this->rules(false));

        $capacity = Capacity::create([
            ...$data,
            'owner_type' => Company::class,
            'owner_id' => $company->getKey(),
        ]);

        return new CapacityResource($capacity);
    }

    public function show(Request $request, int|string $capacity): CapacityResource
    {
        return new CapacityResource($this->capacity($request->user(), $capacity));
    }

    public function update(Request $request, int|string $capacity): CapacityResource
    {
        $record = $this->capacity($request->user(), $capacity);
        $record->update($request->validate($this->rules(true)));

        return new CapacityResource($record->fresh());
    }

    public function destroy(Request $request, int|string $capacity): Response
    {
        $this->capacity($request->user(), $capacity)->delete();

        return response()->noContent();
    }

    /** @return array<string, list<string>> */
    private function rules(bool $partial): array
    {
        $req = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'capability' => [...$req, 'string', 'max:150'],
            'quantity' => [...$req, 'numeric', 'min:0.01', 'max:9999999999'],
            'unit' => [...$req, 'string', 'max:30'],
            'period' => [...$req, 'string', 'in:'.implode(',', self::PERIODS)],
        ];
    }

    private function company(User $user): Company
    {
        $company = $this->scope->company($user);
        abort_if($company === null, 404);

        return $company;
    }

    private function capacities(User $user): Builder
    {
        return Capacity::query()
            ->where('owner_type', Company::class)
            ->where('owner_id', $this->company($user)->getKey());
    }

    private function capacity(User $user, int|string $id): Capacity
    {
        return $this->capacities($user)->whereKey((int) $id)->firstOrFail();
    }
}
