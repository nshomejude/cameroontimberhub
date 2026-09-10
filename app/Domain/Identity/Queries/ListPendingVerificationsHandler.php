<?php

namespace App\Domain\Identity\Queries;

use App\Models\VerificationRequest;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * Byte-identical to the closure PendingVerificationsWidget::table() passed
 * to `->query()` inline: the `open()` scope, the two constrained eager
 * loads, `latest()`. Returned unexecuted so Filament keeps chaining.
 */
final class ListPendingVerificationsHandler implements HandlesQuery
{
    public function handle(Query $query): Builder
    {
        /** @var ListPendingVerificationsQuery $query */
        return VerificationRequest::open()
            ->with('company:id,legal_name,trade_name,slug,status')
            ->with('assignedTo:id,name')
            ->latest();
    }
}
