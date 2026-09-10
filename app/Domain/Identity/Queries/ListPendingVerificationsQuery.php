<?php

namespace App\Domain\Identity\Queries;

use App\Support\Bus\Query;

/**
 * The admin verification-review queue — open verification requests with
 * their company + assignee eager-loaded, newest first. Backs
 * Filament\Widgets\PendingVerificationsWidget.
 *
 * The handler returns an unexecuted Builder: the widget's TableWidget
 * chains its own column search / sort / pagination onto it. The actual
 * "which requests are open" condition lives in
 * `VerificationRequest::open()`, delegated to unchanged.
 */
final class ListPendingVerificationsQuery implements Query
{
}
