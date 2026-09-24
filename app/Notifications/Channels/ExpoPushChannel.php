<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a notification's `title`/`body`/`data` to every device token the
 * notifiable user has registered, via Expo's push API.
 *
 * One POST per notify() call, batching every one of the user's tokens into
 * a single array-bodied request — Expo's `/--/api/v2/push/send` endpoint
 * accepts an array of message objects in one call and returns one "ticket"
 * per message in the same order, so a single request is both cheaper and
 * simpler than one request per token (per Expo's push API docs: batching is
 * the documented, recommended shape for multiple recipients).
 *
 * Never throws: a push failure must not fail the request/job that triggered
 * the parent notification. Failures are logged; a `DeviceNotRegistered`
 * ticket prunes the dead token so it stops being retried on every future
 * notification.
 */
class ExpoPushChannel
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $tokens = $notifiable->deviceTokens()->pluck('expo_push_token')->all();

        if ($tokens === []) {
            return;
        }

        $data = method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : [];

        $messages = array_map(fn (string $token) => [
            'to' => $token,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'data' => [
                'screen' => $data['screen'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notification_id' => $notification->id ?? null,
            ],
            'sound' => 'default',
            'channelId' => 'default',
        ], $tokens);

        try {
            $response = Http::acceptJson()->asJson()->post(self::ENDPOINT, $messages);

            if ($response->failed()) {
                Log::channel('errors')->warning('Expo push request failed.', [
                    'status' => $response->status(),
                    'user_id' => $notifiable->getKey(),
                ]);

                return;
            }

            $tickets = $response->json('data') ?? [];

            foreach ($tickets as $index => $ticket) {
                $errorCode = $ticket['details']['error'] ?? null;

                if (($ticket['status'] ?? null) === 'error' && $errorCode === 'DeviceNotRegistered') {
                    $deadToken = $tokens[$index] ?? null;

                    if ($deadToken !== null) {
                        DeviceToken::where('expo_push_token', $deadToken)->delete();
                    }
                } elseif (($ticket['status'] ?? null) === 'error') {
                    Log::channel('errors')->warning('Expo push ticket error.', [
                        'error' => $errorCode,
                        'user_id' => $notifiable->getKey(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::channel('errors')->error('Expo push send threw.', [
                'exception' => $e->getMessage(),
                'user_id' => $notifiable->getKey(),
            ]);
        }
    }
}
