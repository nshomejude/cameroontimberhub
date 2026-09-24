<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public contact details for the mobile "Contact us" screen — the essentials
 * of config/contact.php (the same source the web /contact page renders).
 */
class ContactController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $c = config('contact');
        $address = $c['address'] ?? [];

        return response()->json(['data' => [
            'organisation' => $c['organisation'] ?? null,
            'phones' => array_values($c['phones'] ?? []),
            'whatsapp' => $c['whatsapp'] ?: null,
            'emails' => array_values(array_map('strval', $c['emails'] ?? [])),
            'hours' => array_values(array_map(fn (array $h) => [
                'days' => $h['days'] ?? '',
                'time' => $h['time'] ?? '',
            ], $c['hours'] ?? [])),
            'hours_note' => $c['hours_note'] ?? null,
            'address' => implode(', ', $address['lines'] ?? []) ?: null,
            'website' => $c['website'] ?? null,
        ]]);
    }
}
