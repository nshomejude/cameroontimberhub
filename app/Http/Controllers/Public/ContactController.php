<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\IntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(): View
    {
        $page = Page::where('slug', 'contact')->where('is_published', true)->firstOrFail();

        return view('public.pages.contact', ['page' => $page]);
    }

    public function store(Request $request, IntakeService $intake): RedirectResponse
    {
        if ($intake->honeypotTripped($request->all())) {
            return back()->with('contact_sent', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180'],
            'subject' => ['required', 'string', 'min:4', 'max:200'],
            'message' => ['required', 'string', 'min:20', 'max:3000'],
            'consent' => ['accepted'],
            'website' => ['nullable'],
            'form_rendered_at' => ['nullable'],
        ]);

        Mail::raw(
            implode("\n\n", [
                "Name: {$data['name']}",
                "Email: {$data['email']}",
                "Subject: {$data['subject']}",
                "Message:\n{$data['message']}",
            ]),
            function ($m) use ($data) {
                $m->to(config('mail.from.address'))
                    ->subject('[CTH Contact] '.$data['subject'])
                    ->replyTo($data['email'], $data['name']);
            }
        );

        return back()->with('contact_sent', true);
    }
}
