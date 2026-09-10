<?php

namespace App\Console\Commands;

use App\Mail\ErrorDigestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Production-readiness plan Task A2 (fallback sink): summarises the dedicated
 * `errors` log channel and emails a plain-text digest to the ops mailbox.
 *
 * Dependency-free: a regex line-parse of the `daily` driver's dated files
 * (storage/logs/errors-YYYY-MM-DD.log). Never fails the scheduler — an unset
 * ops address or an empty/absent log is logged and returned as success.
 */
class ErrorDigestCommand extends Command
{
    protected $signature = 'ops:error-digest {--since=24h : Look-back window, e.g. 24h, 48h, 7d}';

    protected $description = 'Email a daily digest (count + top offenders) of the errors log channel';

    /** Matches a Laravel monolog line head: [2026-09-10 07:00:00] env.ERROR: message */
    private const LINE_RE = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+\S+\.(\w+):\s+(.*)$/';

    public function handle(): int
    {
        $address = config('mail.ops_address');

        if (empty($address)) {
            Log::info('ops:error-digest skipped — mail.ops_address is not configured.');

            return self::SUCCESS;
        }

        $since = $this->resolveSince((string) $this->option('since'));
        [$total, $topOffenders] = $this->scan($since);

        if ($total === 0) {
            Log::info('ops:error-digest — no errors in the look-back window; nothing to send.');

            return self::SUCCESS;
        }

        $body = $this->renderBody($total, $topOffenders, $since);

        Mail::to($address)->send(new ErrorDigestMail($total, $body));

        $this->info("Error digest sent to {$address}: {$total} error(s).");

        return self::SUCCESS;
    }

    private function resolveSince(string $option): Carbon
    {
        if (preg_match('/^(\d+)\s*([hd])$/i', trim($option), $m)) {
            return $m[2] === 'd' || $m[2] === 'D'
                ? now()->subDays((int) $m[1])
                : now()->subHours((int) $m[1]);
        }

        return now()->subDay();
    }

    /**
     * @return array{0: int, 1: array<string, int>}
     */
    private function scan(Carbon $since): array
    {
        // Resolve the `errors` daily-channel base path, then walk its dated
        // files: "/path/errors.log" -> "/path/errors-YYYY-MM-DD.log".
        $base = config('logging.channels.errors.path', storage_path('logs/errors.log'));
        $dir = dirname($base);
        $stem = pathinfo($base, PATHINFO_FILENAME);
        $total = 0;
        $buckets = [];

        for ($date = $since->copy()->startOfDay(); $date->lte(now()); $date->addDay()) {
            $file = $dir.DIRECTORY_SEPARATOR.$stem.'-'.$date->format('Y-m-d').'.log';

            if (! is_file($file)) {
                continue;
            }

            foreach (preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [] as $line) {
                if (! preg_match(self::LINE_RE, $line, $m)) {
                    continue;
                }

                if (Carbon::parse($m[1])->lt($since)) {
                    continue;
                }

                $total++;
                $key = $this->normalise($m[3]);
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            }
        }

        arsort($buckets);

        return [$total, array_slice($buckets, 0, 5, true)];
    }

    /** Strip ids/hashes/numbers/quoted values so like errors group together. */
    private function normalise(string $message): string
    {
        $message = preg_replace('/\{.*$/', '', $message) ?? $message; // drop trailing context json
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<uuid>', $message) ?? $message;
        $message = preg_replace('/0x[0-9a-f]+/i', '<addr>', $message) ?? $message;
        $message = preg_replace('/\b\d+\b/', '<n>', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return Str::limit(trim($message), 160, '');
    }

    /**
     * @param  array<string, int>  $topOffenders
     */
    private function renderBody(int $total, array $topOffenders, Carbon $since): string
    {
        $lines = [
            'Error digest for '.config('app.name'),
            'Window: since '.$since->toDateTimeString().' ('.$since->diffForHumans(now(), true).')',
            '',
            "Total errors: {$total}",
            '',
            'Top offenders:',
        ];

        $rank = 1;
        foreach ($topOffenders as $message => $count) {
            $lines[] = "  {$rank}. [{$count}x] {$message}";
            $rank++;
        }

        $lines[] = '';
        $lines[] = 'Source: storage/logs/errors-*.log';

        return implode("\n", $lines);
    }
}
