@props(['product'])

@php
    $species = $product->species;

    $rows = collect([
        ['Species', $species?->common_name
            ? $species->common_name.($species->scientific_name ? ' ('.$species->scientific_name.')' : '')
            : null],
        ['Moisture Content', $product->moisture_content],
        ['Available Thickness', $product->thickness_mm !== null
            ? rtrim(rtrim(number_format((float) $product->thickness_mm, 2), '0'), '.').'mm'
            : null],
        ['Available Width', $product->widthLabel()],
        ['Available Length', $product->lengthLabel()],
        ['Grade', $product->grade],
        ['Origin', $product->origin],
        ['Certification', $product->certification],
        ['End Use', $species && is_array($species->typical_uses) ? implode(', ', $species->typical_uses) : null],
    ])->filter(fn ($r) => filled($r[1]))->values();

    // The mobile table is deliberately the short commercial summary from the
    // mockup. The full species/technical sheet (density, durability, packaging,
    // delivery…) stays on the desktop "Product Details" tab rather than being
    // repeated here in a duplicated, hard-to-scan form.
@endphp

@if ($rows->isNotEmpty())
    <section class="mt-6 px-4" aria-labelledby="m-details-heading">
        <h2 id="m-details-heading" class="text-[1.25rem] font-bold text-ink">Product Details</h2>

        <div class="mt-3 overflow-hidden rounded-xl border border-sand-200">
            <table class="w-full text-left text-[0.875rem]">
                <caption class="sr-only">Product details for {{ $product->name }}</caption>
                <tbody>
                    @foreach ($rows as $i => [$label, $value])
                        <tr @class(['bg-sand-100' => $i % 2 === 1])>
                            <th scope="row" class="w-[42%] px-3 py-2.5 align-top font-normal text-ink-soft">{{ $label }}</th>
                            <td class="px-3 py-2.5 align-top font-medium text-ink">{{ $value }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
