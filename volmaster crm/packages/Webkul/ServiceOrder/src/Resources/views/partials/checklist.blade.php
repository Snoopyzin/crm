<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('service_order::app.checklist.title')
    </p>

    <div class="flex flex-col gap-2">
        @forelse ($serviceOrder->checklistItems as $checklistItem)
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('admin.service_orders.checklist.toggle', [$serviceOrder->id, $checklistItem->id]) }}">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="{{ $checklistItem->concluido ? 'text-green-600' : 'text-gray-400' }}">
                        {{ $checklistItem->concluido ? '☑' : '☐' }}
                    </button>
                </form>

                <span class="text-sm {{ $checklistItem->concluido ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-white' }}">
                    {{ $checklistItem->descricao }}
                </span>

                <form method="POST" action="{{ route('admin.service_orders.checklist.destroy', [$serviceOrder->id, $checklistItem->id]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs text-red-600 hover:underline">&times;</button>
                </form>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">-</p>
        @endforelse
    </div>

    <form method="POST" action="{{ route('admin.service_orders.checklist.store', $serviceOrder->id) }}" class="mt-3 flex items-center gap-2">
        @csrf
        <input
            type="text"
            name="descricao"
            placeholder="{{ trans('service_order::app.checklist.add-placeholder') }}"
            class="w-full max-w-sm rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            required
        />
        <button type="submit" class="secondary-button">
            @lang('service_order::app.checklist.add-btn')
        </button>
    </form>
</div>
