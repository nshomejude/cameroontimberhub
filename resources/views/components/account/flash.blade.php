{{-- Session status/error banner for account pages (same styling as trade-assurance). --}}
@if (session('status'))
    <div role="status" class="mb-4 rounded-xl border border-forest-200 bg-forest-50 px-4 py-3 text-[0.95rem] text-forest-700">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[0.95rem] text-red-700">
        {{ session('error') }}
    </div>
@endif
