<?php

namespace App\Services;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Models\Company;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\DisputeMessage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opens and drives a formal Dispute against an Order (blueprint §64).
 *
 * Every entry point here re-derives who the acting user actually is against
 * the ORDER's own relationships -- never from a company id supplied by the
 * caller -- exactly as OrderLifecycleService does for order actions.
 */
class DisputeService
{
    public const DISK = 'documents';

    /** 15 MB, matching OrderDocumentService. */
    public const MAX_BYTES = 15 * 1024 * 1024;

    /** @var list<string> */
    public const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /**
     * Open a new dispute against an order. Only a party to the order (the
     * buyer, or a member of the supplier company) may do this.
     */
    public function open(Order $order, User $actor, DisputeCategory $category, string $description): Dispute
    {
        if (! $this->isOrderParty($order, $actor)) {
            throw new RuntimeException('You are not a party to this order.');
        }

        $description = trim($description);

        if ($description === '') {
            throw new RuntimeException('Please describe the issue.');
        }

        $actorCompany = $this->companyFor($order, $actor);
        [$respondentUserId, $respondentCompanyId] = $this->respondentFor($order, $actor, $actorCompany);

        return Dispute::create([
            'order_id' => $order->getKey(),
            'category' => $category->value,
            'status' => DisputeStatus::Opened->value,
            'description' => Str::limit($description, 4000, ''),
            'raised_by_user_id' => $actor->getKey(),
            'raised_by_company_id' => $actorCompany?->getKey(),
            'respondent_user_id' => $respondentUserId,
            'respondent_company_id' => $respondentCompanyId,
        ]);
    }

    /** Attach a piece of evidence and advance the lifecycle. */
    public function submitEvidence(
        Dispute $dispute,
        User $actor,
        string $description,
        ?UploadedFile $file = null,
    ): DisputeEvidence {
        $description = trim($description);

        if ($description === '') {
            throw new RuntimeException('Please describe the evidence being submitted.');
        }

        $data = [
            'submitted_by_user_id' => $actor->getKey(),
            'submitted_by_company_id' => $this->companyFor($dispute->order, $actor)?->getKey(),
            'description' => Str::limit($description, 2000, ''),
        ];

        if ($file !== null) {
            $data = array_merge($data, $this->storeFile($dispute, $file));
        }

        $evidence = $dispute->evidence()->create($data);

        // Model-level transition performs the party check and status guard.
        $dispute->submitEvidence($actor);

        return $evidence;
    }

    /** Log a threaded reply and advance the lifecycle. */
    public function reply(Dispute $dispute, User $actor, string $body): DisputeMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Please enter a message.');
        }

        $message = $dispute->messages()->create([
            'user_id' => $actor->getKey(),
            'company_id' => $this->companyFor($dispute->order, $actor)?->getKey(),
            'body' => Str::limit($body, 4000, ''),
            'created_at' => now(),
        ]);

        $dispute->respondentReply($actor);

        return $message;
    }

    /* ---------------------------------------------------------- helpers */

    public function isOrderParty(Order $order, User $user): bool
    {
        if ((int) $order->user_id === (int) $user->getKey()) {
            return true;
        }

        return $order->company_id !== null
            && $user->companies()->whereKey($order->company_id)->exists();
    }

    /** The company $user is acting as on this order, if any (the supplier side, typically). */
    private function companyFor(Order $order, User $user): ?Company
    {
        if ($order->company_id !== null && $user->companies()->whereKey($order->company_id)->exists()) {
            return $order->relationLoaded('company') ? $order->company : $order->company()->first();
        }

        return null;
    }

    /** @return array{0: ?int, 1: ?int} [respondent_user_id, respondent_company_id] */
    private function respondentFor(Order $order, User $actor, ?Company $actorCompany): array
    {
        // Actor is the supplier (acting as the order's company) -> respondent is the buyer user.
        if ($actorCompany !== null && (int) $actorCompany->getKey() === (int) $order->company_id) {
            return [$order->user_id, null];
        }

        // Actor is the buyer -> respondent is the supplier company.
        return [null, $order->company_id];
    }

    /** @return array<string, mixed> */
    private function storeFile(Dispute $dispute, UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException('That file could not be read. Please try uploading it again.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('Evidence files must be 15 MB or smaller.');
        }

        $mime = (string) $file->getMimeType();
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! in_array($mime, self::ALLOWED_MIMES, true) || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Only PDF, JPG, PNG and WEBP files can be attached as evidence.');
        }

        $path = sprintf('disputes/%d/%s.%s', $dispute->getKey(), Str::uuid()->toString(), $extension);

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            Storage::disk(self::DISK)->put($path, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));

        return [
            'disk' => self::DISK,
            'storage_path' => $path,
            'original_filename' => Str::limit(preg_replace('/[^\w \-.()]+/u', '_', $name) ?: 'evidence', 250, ''),
            'mime_type' => $mime ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
        ];
    }
}
