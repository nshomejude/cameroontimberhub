<x-layouts.app title="Waybill {{ $shipment->waybill_number }}" description="Digital waybill for a TimberHub shipment." noindex>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
        <h1 class="text-2xl font-semibold text-ink dark:text-[#e4ddcf]">Waybill {{ $shipment->waybill_number }}</h1>

        <img src="{{ $qrDataUri }}" alt="Waybill QR code" class="my-6 h-40 w-40">

        <dl class="grid grid-cols-2 gap-2 text-sm text-ink-soft dark:text-[#8f887b]">
            <dt>Origin</dt>
            <dd>{{ $shipment->origin ?? '—' }}</dd>
            <dt>Destination</dt>
            <dd>{{ $shipment->destination ?? '—' }}</dd>
        </dl>

        <h2 class="mt-8 font-medium text-ink dark:text-[#e4ddcf]">Cargo (product IDs)</h2>
        <ul class="list-inside list-disc text-ink-soft dark:text-[#8f887b]">
            @forelse ($cargoProductIds as $productId)
                <li>{{ $productId }}</li>
            @empty
                <li>No linked catalogue products on this booking.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
