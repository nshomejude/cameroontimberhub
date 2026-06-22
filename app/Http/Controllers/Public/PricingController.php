<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\View\View;

class PricingController extends Controller
{
    public function index(): View
    {
        return view('public.pricing', [
            'plans' => Plan::active()->get(),
        ]);
    }
}
