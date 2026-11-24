@php
    $trackingUrl = \Illuminate\Support\Facades\URL::signedRoute('service_orders.tracking.show', ['id' => $serviceOrder->id]);
@endphp

<p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">
    @lang('service_order::app.tracking.title')
</p>

<div class="flex items-center gap-2">
    <input
        type="text"
        readonly
        value="{{ $trackingUrl }}"
        id="tracking-link-input"
        class="w-full max-w-lg rounded-md border border-gray-300 bg-gray-50 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
        onclick="this.select()"
    />

    <button
        type="button"
        class="secondary-button"
        onclick="navigator.clipboard.writeText(document.getElementById('tracking-link-input').value); this.textContent = '{{ trans('service_order::app.tracking.copied') }}'; setTimeout(() => this.textContent = '{{ trans('service_order::app.tracking.copy-btn') }}', 2000);"
    >
        @lang('service_order::app.tracking.copy-btn')
    </button>

    <a href="{{ $trackingUrl }}" target="_blank" class="text-sm text-blue-600 hover:underline">
        @lang('service_order::app.tracking.preview')
    </a>
</div>

<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
    {{ trans('service_order::app.tracking.hint') }}
</p>
