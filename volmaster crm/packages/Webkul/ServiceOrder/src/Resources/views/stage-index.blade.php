<x-admin::layouts>
    <x-slot:title>
        {{ trans('service_order::app.stages.'.$stage) }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="text-xl font-bold dark:text-white">
                {{ trans('service_order::app.stages.'.$stage) }}
            </div>

            <div class="flex items-center gap-x-2.5">
                @if ($stage === \Webkul\ServiceOrder\Models\ServiceOrder::STAGE_CADASTRO && bouncer()->hasPermission('service_orders.create'))
                    <a href="{{ route('admin.service_orders.cadastro.create') }}" class="primary-button">
                        @lang('service_order::app.index.create-btn')
                    </a>
                @elseif ($stage === \Webkul\ServiceOrder\Models\ServiceOrder::STAGE_ORDEM_SERVICO && bouncer()->hasPermission('service_orders.create'))
                    <a href="{{ route('admin.service_orders.create') }}" class="primary-button">
                        @lang('service_order::app.index.create-btn')
                    </a>
                @endif
            </div>
        </div>

        <div class="box-shadow flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-800 dark:bg-gray-900">
            @foreach ($stages as $flowStage)
                <span class="rounded-full px-3 py-1 {{ $flowStage === $stage ? 'bg-blue-600 font-semibold text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">
                    {{ trans('service_order::app.stages.'.$flowStage) }}
                </span>
            @endforeach
        </div>

        {{-- Indicadores da etapa (piloto ligado): cartões coloridos pela meta, o clique abre as OS do número. --}}
        @if (\Webkul\ServiceOrder\Services\StageGate::active() && bouncer()->hasPermission('indicadores_mentoria'))
            @include('service_order::components.stage-indicators', ['stage' => $stage])
        @endif

        <x-admin::datagrid :src="route('admin.service_orders.stage.index', $stage)">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
