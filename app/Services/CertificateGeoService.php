<?php

namespace App\Services;

use App\Exceptions\InvalidGeospatialDataException;

/**
 * Geospatial integrity (docs/CERTIFICATE_SPEC.md Ring 2, Layer 13): a
 * canonical GeoJSON Point or Polygon, hashed and bound to a certificate.
 *
 * No spatial-engine composer package exists in this codebase, so validation
 * here is plain PHP: coordinate precision, ring closure, minimum vertex
 * count, and a real O(n^2) segment-intersection self-intersection check --
 * correct and sufficient for the small polygons (a single forest plot
 * boundary) this certificate binds, not a general GIS engine. If plots with
 * hundreds of vertices or true multi-polygons become a real requirement,
 * that is the trigger to add a maintained geometry library rather than
 * growing this by hand.
 *
 * COORDINATES ARE STRINGS, NOT FLOATS, and this is load-bearing rather than
 * stylistic. EUDR requires coordinates at six or more decimal places, and a
 * PHP/JSON float physically cannot carry that: the perfectly legitimate
 * 6-decimal value 11.520000 decodes to the float 11.52, which is
 * indistinguishable from a 2-decimal village-centroid guess. Any
 * decimal-counting check over floats therefore either rejects valid
 * coordinates or accepts coarse ones -- there is no threshold that does
 * neither. Requiring the decimal string representation is the only way to
 * enforce the rule truthfully, so a float coordinate is refused outright
 * with an explanation rather than silently under-checked. Strings also make
 * the canonical hash exactly reproducible, with no float-formatting
 * dependence.
 */
class CertificateGeoService
{
    private const MIN_DECIMAL_PRECISION = 6;

    public function hashAndValidate(array $geoJson): string
    {
        $this->validate($geoJson);

        return hash('sha256', $this->canonicalize($geoJson));
    }

    public function validate(array $geoJson): void
    {
        $type = $geoJson['type'] ?? null;

        match ($type) {
            'Point' => $this->validatePoint($geoJson['coordinates'] ?? null),
            'Polygon' => $this->validatePolygon($geoJson['coordinates'] ?? null),
            default => throw new InvalidGeospatialDataException("Unsupported GeoJSON type [{$type}]. Only Point and Polygon are accepted."),
        };
    }

    private function validatePoint(mixed $coordinates): void
    {
        $this->assertPosition($coordinates);
    }

    private function validatePolygon(mixed $rings): void
    {
        if (! is_array($rings) || $rings === []) {
            throw new InvalidGeospatialDataException('Polygon must have at least one ring.');
        }

        $ring = $rings[0] ?? null;

        if (! is_array($ring) || $ring === []) {
            throw new InvalidGeospatialDataException('Polygon ring must be an array of positions.');
        }

        // Closure is checked before vertex count so a truncated ring reports
        // the thing that is actually wrong with it rather than its length.
        if ($ring[0] !== $ring[count($ring) - 1]) {
            throw new InvalidGeospatialDataException('Polygon ring must be closed: first and last positions must match.');
        }

        if (count($ring) < 4) {
            throw new InvalidGeospatialDataException('Polygon ring must have at least 4 positions (3 distinct vertices plus the closing point).');
        }

        foreach ($ring as $position) {
            $this->assertPosition($position);
        }

        $this->assertSimple($ring);
    }

    private function assertPosition(mixed $position): void
    {
        if (! is_array($position) || count($position) !== 2) {
            throw new InvalidGeospatialDataException('Every position must be exactly [longitude, latitude].');
        }

        foreach ($position as $coordinate) {
            $this->assertPrecision($coordinate);
        }
    }

    private function assertPrecision(mixed $coordinate): void
    {
        if (is_float($coordinate) || is_int($coordinate)) {
            throw new InvalidGeospatialDataException(
                'Coordinate must be given as a decimal string (e.g. "11.520000"), not a number: '
                .'a float cannot preserve trailing zeros, so the required '
                .self::MIN_DECIMAL_PRECISION.'-decimal precision could not be verified.'
            );
        }

        if (! is_string($coordinate) || ! preg_match('/^-?\d+\.(\d+)$/', $coordinate, $matches)) {
            throw new InvalidGeospatialDataException('Coordinate must be a decimal string such as "11.520000".');
        }

        if (strlen($matches[1]) < self::MIN_DECIMAL_PRECISION) {
            throw new InvalidGeospatialDataException(
                "Coordinate {$coordinate} has fewer than ".self::MIN_DECIMAL_PRECISION
                .' decimal digits of precision, required by EUDR.'
            );
        }
    }

    /** O(n^2) pairwise non-adjacent segment intersection check -- fine for a plot-boundary-sized ring. */
    private function assertSimple(array $ring): void
    {
        $edges = count($ring) - 1; // last position duplicates the first (closed ring)

        for ($i = 0; $i < $edges; $i++) {
            for ($j = $i + 1; $j < $edges; $j++) {
                // Skip edges that share an endpoint (adjacent edges always "touch"),
                // including the wrap-around pair of the first and last edge.
                if ($j === $i + 1 || ($i === 0 && $j === $edges - 1)) {
                    continue;
                }

                if ($this->segmentsIntersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    throw new InvalidGeospatialDataException('Polygon ring is self-intersecting.');
                }
            }
        }
    }

    private function segmentsIntersect(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d1 = $this->direction($p3, $p4, $p1);
        $d2 = $this->direction($p3, $p4, $p2);
        $d3 = $this->direction($p1, $p2, $p3);
        $d4 = $this->direction($p1, $p2, $p4);

        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
            && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    private function direction(array $a, array $b, array $c): float
    {
        return ((float) $c[0] - (float) $a[0]) * ((float) $b[1] - (float) $a[1])
            - ((float) $b[0] - (float) $a[0]) * ((float) $c[1] - (float) $a[1]);
    }

    private function canonicalize(array $geoJson): string
    {
        ksort($geoJson);

        return json_encode($geoJson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
