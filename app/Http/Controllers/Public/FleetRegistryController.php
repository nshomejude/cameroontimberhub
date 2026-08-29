<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Company-facing fleet & driver registry (gap-plan item 1.5.12). Lists the
 * signed-in user's own company vehicles/drivers with document expiry status,
 * reusing the shared polymorphic Document store (see
 * Vehicle::documents()/Driver::documents(), both from HasDocuments) rather
 * than a bespoke expiry system.
 */
class FleetRegistryController extends Controller
{
    public function index(Request $request): View
    {
        $company = $request->user()->companies()->first();

        abort_if($company === null, 404);

        $vehicles = $company->vehicles()->with('documents')->orderBy('registration_number')->get();
        $drivers = $company->drivers()->with('documents')->orderBy('name')->get();

        return view('fleet.index', compact('company', 'vehicles', 'drivers'));
    }
}
