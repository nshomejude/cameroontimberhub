{{--
    Panel brand lockup.

    Two grounds, one component. Inside the panel the logo cell sits on the dark
    forest column that continues the sidebar, so it uses the same icon + white
    wordmark lockup as the buyer dashboard's sidebar. On the login screen
    (`.fi-simple-layout`) the same slot sits on white, so the full-colour
    `logo-600.png` wordmark is shown there instead — the colour swap is done in
    `resources/css/filament/theme.css`, not with a second Filament callback.
--}}
<span class="cth-brand">
    <span class="cth-brand-lockup">
        <img src="{{ asset('brand/icon-96.png') }}" alt="" class="cth-brand-icon">
        <span class="cth-brand-text">
            <span class="cth-brand-name">Cameroon<br>Timber Hub</span>
            <span class="cth-brand-tagline">Connect &middot; Trade &middot; Grow</span>
        </span>
    </span>

    <img src="{{ asset('brand/logo-600.png') }}"
         alt="{{ filament()->getBrandName() }}"
         class="cth-brand-wordmark">
</span>
