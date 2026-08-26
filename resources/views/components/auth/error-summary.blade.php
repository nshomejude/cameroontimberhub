{{--
    Accessible error summary. Announced via role="alert", focused on load, and
    every entry links to the offending control so keyboard and screen-reader
    users land on the first invalid field.
--}}
@if ($errors->any())
    <div role="alert" tabindex="-1" data-auth-error-summary
         class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[1.0625rem] text-red-800">
        <p class="flex items-center gap-2 font-semibold">
            <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ $errors->count() === 1 ? 'There is a problem with your submission' : 'There are '.$errors->count().' problems with your submission' }}
        </p>
        <ul class="mt-2 space-y-1 ps-6 list-disc">
            @foreach ($errors->keys() as $key)
                <li>
                    <a class="underline underline-offset-2 hover:no-underline"
                       href="#auth-{{ trim(preg_replace('/[^a-z0-9]+/i', '-', $key), '-') }}">{{ $errors->first($key) }}</a>
                </li>
            @endforeach
        </ul>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var summary = document.querySelector('[data-auth-error-summary]');
            var firstInvalid = document.querySelector('[aria-invalid="true"]');
            (firstInvalid || summary) && (firstInvalid || summary).focus({ preventScroll: false });
        });
    </script>
@endif
