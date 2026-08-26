<?php

namespace App\Services;

use App\Enums\OrderDocumentKind;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Storage and retrieval of order documents (proof of delivery, shipping papers).
 *
 * Everything here exists so the mockup's document list can be REAL rather than
 * five decorative rows. The rules, in order of importance:
 *
 *  1. Files go to the private `documents` disk. That root is outside the public
 *     web root and the disk is declared `visibility: private`, so there is no
 *     guessable URL — the only way out is download(), behind the controller's
 *     participation check.
 *  2. The stored path is generated, never taken from the upload. A filename of
 *     `../../.env` therefore cannot escape anywhere; the user's own filename is
 *     kept only as a display label and re-applied at download time.
 *  3. MIME type and size are validated against an allow-list here as well as in
 *     the request rules, so a service-layer caller (a seeder, a console
 *     command, a test) cannot bypass them.
 */
class OrderDocumentService
{
    /** 15 MB. Large enough for a scanned bill of lading, small enough to bound. */
    public const MAX_BYTES = 15 * 1024 * 1024;

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const DISK = 'documents';

    /**
     * Store one uploaded file against an order.
     *
     * The caller is responsible for having established that $uploader is
     * allowed to do this — OrderLifecycleService does it, and it is the only
     * caller in the application.
     */
    public function store(
        Order $order,
        UploadedFile $file,
        OrderDocumentKind $kind,
        ?User $uploader = null,
        ?string $label = null,
    ): OrderDocument {
        $this->assertAcceptable($file);

        $extension = mb_strtolower($file->getClientOriginalExtension() ?: 'bin');

        // Generated path. Nothing from the client reaches the filesystem.
        $path = sprintf(
            'orders/%d/%s.%s',
            $order->getKey(),
            Str::uuid()->toString(),
            $extension,
        );

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            Storage::disk(self::DISK)->put($path, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $order->documents()->create([
            'company_id' => $order->company_id,
            'uploaded_by_user_id' => $uploader?->getKey(),
            'kind' => $kind->value,
            'label' => $label ? Str::limit(trim($label), 155, '') : null,
            'disk' => self::DISK,
            'storage_path' => $path,
            // Sanitised for display only; it is never used as a path segment.
            'original_filename' => Str::limit($this->safeFilename($file), 250, ''),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
        ]);
    }

    /** Stream the file back under its original name. */
    public function download(OrderDocument $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->storage_path)) {
            abort(404);
        }

        return $disk->download($document->storage_path, $document->original_filename);
    }

    /** Remove the row and the bytes together. */
    public function delete(OrderDocument $document): void
    {
        Storage::disk($document->disk)->delete($document->storage_path);

        $document->delete();
    }

    /* ---------------------------------------------------------- validation */

    private function assertAcceptable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('That file could not be read. Please try uploading it again.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('Documents must be 15 MB or smaller.');
        }

        $mime = (string) $file->getMimeType();
        $extension = mb_strtolower($file->getClientOriginalExtension());

        // Both must pass. A PDF renamed to .png fails on the extension, and a
        // script renamed to .pdf fails on the sniffed MIME type.
        if (! in_array($mime, self::ALLOWED_MIMES, true) || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Only PDF, JPG, PNG and WEBP documents can be attached to an order.');
        }
    }

    /** Strip anything path-like out of the display name. */
    private function safeFilename(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));

        return preg_replace('/[^\w \-.()]+/u', '_', $name) ?: 'document';
    }
}
