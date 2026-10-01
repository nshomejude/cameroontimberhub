@props([
    'company',
])

@php
    /**
     * Save / unsave a supplier for a signed-in buyer. Writes the same
     * `favorites` row the mobile app's /api/v1/favorites uses. Rendered only
     * for an account that can reach `/account` (EnsureBuyerAccount's rule:
     * not staff, and either no company or holding the buyer role) — anyone
     * else would just be bounced by the middleware.
     */
    $user = auth()->user();
    $canSave = $user !== null
        && ! $user->isStaff()
        && (! $user->companies()->exists() || $user->hasRole('buyer'));
    $saved = $canSave && \App\Models\Favorite::query()
        ->where('user_id', $user->getKey())
        ->where('favoritable_type', \App\Models\Company::class)
        ->where('favoritable_id', $company->getKey())
        ->exists();
@endphp

@if ($canSave)
    <form method="POST" action="{{ $saved ? route('account.saved.destroy', $company->slug) : route('account.saved.store', $company->slug) }}" class="contents">
        @csrf
        @if ($saved) @method('DELETE') @endif
        <button type="submit" aria-pressed="{{ $saved ? 'true' : 'false' }}" {{ $attributes }}>
            <x-dynamic-component :component="$saved ? 'heroicon-s-bookmark' : 'heroicon-o-bookmark'" class="h-5 w-5" aria-hidden="true" />
            {{ $saved ? __('messages.account_center.unsave_supplier') : __('messages.account_center.save_supplier') }}
        </button>
    </form>
@endif
