<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('service_order::app.execucao.title')
    </p>

    <div class="mb-3 flex items-center gap-6">
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.execucao.timer-status')</p>
            <p class="text-base font-medium dark:text-white">
                {{ $serviceOrder->timer_status ?? trans('service_order::app.execucao.timer-not-started') }}
            </p>
        </div>

        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.execucao.elapsed')</p>
            <p
                class="font-mono text-base font-medium dark:text-white"
                id="timer-elapsed"
                data-elapsed-seconds="{{ $serviceOrder->elapsed_seconds }}"
                data-running="{{ $serviceOrder->timer_status === 'Em Andamento' ? '1' : '0' }}"
            >
                --:--:--
            </p>
        </div>
    </div>

    @if ($serviceOrder->pausa_justificativa)
        <p class="mb-3 text-sm text-yellow-700 dark:text-yellow-400">
            @lang('service_order::app.execucao.pausa-atual') {{ $serviceOrder->pausa_justificativa }}
        </p>
    @endif

    <form method="POST" action="{{ route('admin.service_orders.execucao.tarefa.update', $serviceOrder->id) }}" class="mb-3 flex items-end gap-2">
        @csrf
        @method('PUT')

        <div class="flex flex-1 flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.execucao.tarefa-atual')</label>
            <input
                type="text"
                name="execucao_tarefa_atual"
                value="{{ $serviceOrder->execucao_tarefa_atual }}"
                placeholder="{{ trans('service_order::app.execucao.tarefa-atual-placeholder') }}"
                class="w-full max-w-md rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            />
        </div>

        <button type="submit" class="secondary-button">
            @lang('service_order::app.execucao.tarefa-atual-save-btn')
        </button>
    </form>

    <div class="flex items-center gap-2">
        @if (! $serviceOrder->timer_status)
            <form method="POST" action="{{ route('admin.service_orders.timer.start', $serviceOrder->id) }}">
                @csrf
                <button type="submit" class="primary-button">@lang('service_order::app.execucao.start-btn')</button>
            </form>
        @elseif ($serviceOrder->timer_status === 'Em Andamento')
            <form method="POST" action="{{ route('admin.service_orders.timer.pause', $serviceOrder->id) }}" class="flex items-center gap-2">
                @csrf
                <input type="text" name="justificativa" required placeholder="{{ trans('service_order::app.execucao.justificativa-placeholder') }}" class="w-64 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900" />
                <button type="submit" class="secondary-button">@lang('service_order::app.execucao.pause-btn')</button>
            </form>

            <form method="POST" action="{{ route('admin.service_orders.timer.finish', $serviceOrder->id) }}">
                @csrf
                <button type="submit" class="primary-button">@lang('service_order::app.execucao.finish-btn')</button>
            </form>
        @elseif ($serviceOrder->timer_status === 'Pausado')
            <form method="POST" action="{{ route('admin.service_orders.timer.resume', $serviceOrder->id) }}">
                @csrf
                <button type="submit" class="primary-button">@lang('service_order::app.execucao.resume-btn')</button>
            </form>
        @endif
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const el = document.getElementById('timer-elapsed');

                if (! el) {
                    return;
                }

                const baseSeconds = parseInt(el.dataset.elapsedSeconds, 10) || 0;
                const running = el.dataset.running === '1';
                const loadedAt = Date.now();

                function format(totalSeconds) {
                    const h = Math.floor(totalSeconds / 3600);
                    const m = Math.floor((totalSeconds % 3600) / 60);
                    const s = Math.floor(totalSeconds % 60);

                    return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':');
                }

                function tick() {
                    const extra = running ? Math.floor((Date.now() - loadedAt) / 1000) : 0;

                    el.textContent = format(baseSeconds + extra);
                }

                tick();

                if (running) {
                    setInterval(tick, 1000);
                }
            });
        </script>
    @endpush
@endonce
