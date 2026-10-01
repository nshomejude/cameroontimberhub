<?php

namespace App\Http\Controllers\Public;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Services\IntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class InquiryController extends Controller
{
    public function store(Request $request, Company $company, IntakeService $intake): RedirectResponse
    {
        // Inquiries are only accepted on verified, publicly-visible companies.
        abort_unless($company->status === CompanyStatus::Verified, 404);

        if ($intake->honeypotTripped($request->all())) {
            return back()->with('inquiry_sent', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
            'message' => ['required', 'string', 'min:20', 'max:3000'],
            'consent' => ['accepted'],
            'website' => ['nullable'],
            'form_rendered_at' => ['nullable'],
        ]);

        $intake->createInquiry($company, [
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'],
        ], filled($data['consent'] ?? null));

        return back()->with('inquiry_sent', true);
    }

    public function verify(Request $request, CompanyInquiry $inquiry, IntakeService $intake): View|Response
    {
        // Signature checked here (not via `signed` middleware) so an expired
        // link gets a recovery page — still a 403 — rather than a bare error.
        if (! $request->hasValidSignature() || $request->query('h') !== sha1($inquiry->email)) {
            return response()->view('public.rfq.link-invalid', [
                'kind' => 'inquiry',
                'company' => $inquiry->company,
            ], 403);
        }

        $intake->verifyInquiry($inquiry);

        return view('public.inquiry.verified', ['inquiry' => $inquiry]);
    }
}
