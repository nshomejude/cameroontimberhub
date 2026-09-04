<?php

namespace App\Http\Controllers;

use App\Models\TimberLot;
use App\Models\User;
use App\Services\ComplianceEvidencePackService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates and downloads the "CTH Compliance Evidence Pack" PDF
 * (implementation blueprint §83) for a single TimberLot.
 *
 * Deliberately NOT public, unlike the Timber Passport: this is framed by
 * the blueprint as an internal/controlled document "for a shipment", so
 * access is limited to the lot's owning company or staff with the
 * `compliance.export` permission — following the same
 * auth-then-authorise shape as DocumentDownloadController.
 */
class CompliancePackController extends Controller
{
    public function download(Request $request, TimberLot $timberLot, ComplianceEvidencePackService $packs): Response
    {
        abort_unless($this->authorized($request->user(), $timberLot), 403);

        $timberLot->loadMissing(['company', 'species', 'product']);

        $data = $packs->buildFor($timberLot, $request->query('destination_country'));

        $pdf = Pdf::loadView('pdf.compliance-pack', $data);

        return $pdf->download('compliance-pack-'.$timberLot->lot_number.'.pdf');
    }

    protected function authorized(?User $user, TimberLot $timberLot): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->can('compliance.export')) {
            return true;
        }

        return $user->companies()->whereKey($timberLot->company_id)->exists();
    }
}
