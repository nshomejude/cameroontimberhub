<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Models\CarbonProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the public "Carbon Projects" directory is worth linking to from the
 * site nav/footer. While carbon signup is dormant (timber.signup.carbon_enabled
 * false) and nothing is listed, the links are hidden so launch visitors never
 * land on an empty directory. The route itself stays reachable.
 */
final class CarbonDirectory
{
    public const CACHE_KEY = 'carbon_directory.active_count';

    public static function linksVisible(): bool
    {
        return (bool) config('timber.signup.carbon_enabled', false) || self::activeCount() > 0;
    }

    /** Listed projects, same filter as CarbonProjectsController::index (cached 10 min). */
    public static function activeCount(): int
    {
        return (int) Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => CarbonProject::query()
            ->where('status', ProductStatus::Active->value)
            ->whereHas('company', fn (Builder $q) => $q
                ->publiclyVisible()
                ->where('type', OrganisationType::CarbonDeveloper->value))
            ->count());
    }
}
