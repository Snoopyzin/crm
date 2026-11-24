<x-admin::layouts>
    <x-slot:title>
        {{ $mechanic->nome }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="mechanics.view" :entity="$mechanic" />

                <div class="text-xl font-bold dark:text-white">
                    {{ $mechanic->nome }}
                    @if ($mechanic->status === 'Lenda')
                        <span class="ml-1 rounded bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-yellow-700">⭐ @lang('mechanic::app.form.status-options.lenda')</span>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('mechanics.edit'))
                    <a href="{{ route('admin.mechanics.edit', $mechanic->id) }}" class="primary-button">
                        @lang('mechanic::app.index.datagrid.edit')
                    </a>
                @endif
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid grid-cols-3 gap-4 max-sm:grid-cols-1">
                @if ($mechanic->codigo)
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Código</p>
                        <p class="text-base font-medium dark:text-white">Nº {{ $mechanic->codigo }}</p>
                    </div>
                @endif

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('mechanic::app.form.especialidade')</p>
                    <p class="text-base font-medium dark:text-white">{{ trans('mechanic::app.form.especialidade-options.'.$mechanic->especialidade) }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('mechanic::app.form.years_of_experience')</p>
                    <p class="text-base font-medium dark:text-white">{{ $mechanic->years_of_experience ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('mechanic::app.form.avaliacao')</p>
                    <p class="text-base font-medium dark:text-white">{{ number_format($mechanic->avaliacao, 1) }} / 5</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('mechanic::app.form.status')</p>
                    <p class="text-base font-medium dark:text-white">{{ trans('mechanic::app.form.status-options.'.strtolower($mechanic->status)) }}</p>
                </div>
            </div>
        </div>
    </div>
</x-admin::layouts>
