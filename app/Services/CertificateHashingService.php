<?php

namespace App\Services;

/**
 * Produces ONE deterministic JSON string for a certificate's data payload
 * (docs/CERTIFICATE_SPEC.md Ring 1, Layer 3: "the actual source of truth,
 * not the PDF"), and the SHA-256 hash of it (Layer 4).
 *
 * Determinism rules, all load-bearing for signature verification later
 * (CertificateSigningService signs this hash, not the raw array):
 *   - Associative array keys are sorted recursively (ksort, recursive).
 *   - Sequential (list) arrays keep their given order -- order is meaningful
 *     for lists (e.g. an ordered evidence array), only *key* order for maps
 *     is normalized.
 *   - Floats are normalized to a fixed string representation so 120.50 and
 *     120.5 canonicalize identically -- avoids a differently-typed numeric
 *     value for the same logical quantity becoming a FALSE negative on hash
 *     comparison.
 *   - JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE: stable,
 *     human-comparable output; no dependence on PHP's default slash-escaping.
 */
class CertificateHashingService
{
    public function canonicalize(array $data): string
    {
        $normalized = $this->normalize($data);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function hash(array $data): string
    {
        return hash('sha256', $this->canonicalize($data));
    }

    private function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            // Fixed 6-decimal representation, trailing zeros trimmed, so
            // 120.5 and 120.500000 collapse to the same canonical string.
            // 6 decimals comfortably covers this spec's own EUDR ">=6
            // decimal digits" coordinate precision requirement.
            $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

            return $formatted === '' || $formatted === '-' ? '0' : $formatted;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($v) => $this->normalize($v), $value);
            }

            ksort($value);
            $out = [];
            foreach ($value as $key => $v) {
                $out[$key] = $this->normalize($v);
            }

            return $out;
        }

        return $value;
    }
}
