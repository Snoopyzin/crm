<x-admin::layouts>
    <x-slot:title>
        {{ $vehicle->placa }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="vehicles.view" :entity="$vehicle" />

                <div class="text-xl font-bold dark:text-white">
                    {{ $vehicle->placa }} &middot; {{ $vehicle->marca }} {{ $vehicle->modelo }}
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('vehicles.edit'))
                    <a href="{{ route('admin.vehicles.edit', $vehicle->id) }}" class="primary-button">
                        @lang('vehicle::app.index.datagrid.edit')
                    </a>
                @endif
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid grid-cols-3 gap-4 max-sm:grid-cols-1">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.person')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->person->name ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.placa')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->placa }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.marca')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->marca }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.modelo')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->modelo }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.ano')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->ano ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.chassi')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->chassi ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.km')</p>
                    <p class="text-base font-medium dark:text-white">{{ number_format($vehicle->km, 0, ',', '.') }} km</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.cor')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->cor ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.tipo')</p>
                    <p class="text-base font-medium dark:text-white">{{ trans('vehicle::app.form.tipo-options.'.$vehicle->tipo) }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.combustivel')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->combustivel ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('vehicle::app.form.status')</p>
                    <p class="text-base font-medium dark:text-white">{{ $vehicle->status }}</p>
                </div>
            </div>
        </div>
    </div>
</x-admin::layouts>
