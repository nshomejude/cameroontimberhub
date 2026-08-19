<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that a named account clicked accept next to a specific set of terms.
 *
 * Read the migration docblock before extending this: it is deliberately not an
 * electronic signature, and no method here should ever be given a name that
 * implies otherwise.
 */
class ContractAcceptance extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * Canonical hash of a terms array.
     *
     * Keys are sorted recursively and the whole thing is JSON-encoded with
     * sorted keys and no escaping, so the same terms always hash the same way
     * regardless of the order the array happened to be built in. sha256 because
     * it is what PHP gives us without a package and it is enough to detect a
     * later edit — it is an integrity check, not a signature.
     */
    public static function hashTerms(array $terms): string
    {
        return hash('sha256', self::canonicalise($terms));
    }

    /** The exact bytes that were hashed — exposed so a check can be re-run. */
    public static function canonicalise(array $terms): string
    {
        $sort = function (array $value) use (&$sort): array {
            ksort($value);

            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return json_encode($sort($terms), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** True when the stored terms still hash to the stored hash. */
    public function matchesRecordedTerms(): bool
    {
        return hash_equals($this->terms_hash, self::hashTerms((array) $this->terms));
    }

    /** Short form for display — the full 64 chars do not fit a chat card. */
    public function shortHash(): string
    {
        return substr($this->terms_hash, 0, 16);
    }
}
