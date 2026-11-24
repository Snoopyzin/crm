<x-admin::layouts>
    <x-slot:title>
        #{{ $serviceRequest->id }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="service_requests.view" :entity="$serviceRequest" />

                <div class="text-xl font-bold dark:text-white">
                    #{{ $serviceRequest->id }} &middot; {{ $serviceRequest->person->name }}
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if ($serviceRequest->status !== \Webkul\Portal\Models\ServiceRequest::STATUS_CONVERTIDA && $serviceRequest->status !== \Webkul\Portal\Models\ServiceRequest::STATUS_RECUSADA)
                    <form method="POST" action="{{ route('admin.service_requests.convert', $serviceRequest->id) }}">
                        @csrf
                        <button type="submit" class="primary-button">
                            @lang('portal::app.admin.service-requests.view.convert')
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.service_requests.reject', $serviceRequest->id) }}">
                        @csrf
                        <button type="submit" class="transparent-button">
                            @lang('portal::app.admin.service-requests.view.reject')
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('portal::app.admin.service-requests.index.cliente')</p>
                    <p class="text-base font-medium dark:text-white">{{ $serviceRequest->person->name }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('portal::app.admin.service-requests.index.veiculo')</p>
                    <p class="text-base font-medium dark:text-white">
                        @if ($serviceRequest->vehicle)
                            {{ $serviceRequest->vehicle->placa }} &mdash; {{ $serviceRequest->vehicle->marca }} {{ $serviceRequest->vehicle->modelo }}
                        @else
                            {{ $serviceRequest->veiculo_descricao_livre ?? '-' }}
                        @endif
                    </p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('portal::app.admin.service-requests.index.data-preferencia')</p>
                    <p class="text-base font-medium dark:text-white">{{ $serviceRequest->data_preferencia?->format('d/m/Y') ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('portal::app.admin.service-requests.index.status')</p>
                    <p class="text-base font-medium dark:text-white">{{ $serviceRequest->status }}</p>
                </div>

                <div class="col-span-2">
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('portal::app.admin.service-requests.index.descricao')</p>
                    <p class="text-base font-medium dark:text-white">{{ $serviceRequest->descricao }}</p>
                </div>
            </div>
        </div>

        @if ($serviceRequest->status === \Webkul\Portal\Models\ServiceRequest::STATUS_EM_ANALISE)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">
                    @lang('portal::app.admin.service-requests.view.link-order')
                </p>

                <form method="POST" action="{{ route('admin.service_requests.link_order', $serviceRequest->id) }}" class="flex items-end gap-2">
                    @csrf

                    <div class="flex flex-col gap-1">
                        <label class="text-xs text-gray-500">@lang('portal::app.admin.service-requests.view.service-order-id')</label>
                        <input type="number" name="service_order_id" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900" required />
                    </div>

                    <button type="submit" class="primary-button">
                        @lang('portal::app.admin.service-requests.view.link-order')
                    </button>
                </form>
            </div>
        @endif

        @if ($serviceRequest->serviceOrder)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <a class="text-brandColor hover:underline" href="{{ route('admin.service_orders.view', $serviceRequest->service_order_id) }}">
                    #{{ $serviceRequest->service_order_id }} &middot; {{ $serviceRequest->serviceOrder->subject }}
                </a>
            </div>
        @endif
    </div>
</x-admin::layouts>
