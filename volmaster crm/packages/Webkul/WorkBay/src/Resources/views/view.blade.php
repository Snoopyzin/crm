<x-admin::layouts>
    <x-slot:title>
        {{ $workBay->name }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="work_bays.view" :entity="$workBay" />

                <div class="text-xl font-bold dark:text-white">
                    {{ $workBay->name }}
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('work_bays.edit'))
                    <a href="{{ route('admin.work_bays.edit', $workBay->id) }}" class="primary-button">
                        @lang('workbay::app.index.datagrid.edit')
                    </a>
                @endif
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('workbay::app.form.capacity')</p>
                    <p class="text-base font-medium dark:text-white">{{ $workBay->capacity }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('workbay::app.form.status')</p>
                    <p class="text-base font-medium dark:text-white">{{ $workBay->status }}</p>
                </div>
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                @lang('workbay::app.view.assignments')
            </p>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <th class="pb-2">OS</th>
                        <th class="pb-2">@lang('workbay::app.view.starts-at')</th>
                        <th class="pb-2">@lang('workbay::app.view.ends-at')</th>
                        <th class="pb-2">@lang('workbay::app.index.datagrid.status')</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workBay->assignments as $assignment)
                        <tr class="border-b border-gray-100 dark:border-gray-900">
                            <td class="py-2">
                                @if ($assignment->serviceOrder)
                                    <a class="text-brandColor hover:underline" href="{{ route('admin.service_orders.view', $assignment->quote_id) }}">
                                        #{{ $assignment->quote_id }} &middot; {{ $assignment->serviceOrder->subject }}
                                    </a>
                                @else
                                    #{{ $assignment->quote_id }}
                                @endif
                            </td>
                            <td class="py-2">{{ $assignment->starts_at->format('d/m/Y H:i') }}</td>
                            <td class="py-2">{{ $assignment->ends_at?->format('d/m/Y H:i') ?? '-' }}</td>
                            <td class="py-2">{{ $assignment->status }}</td>
                            <td class="py-2">
                                <form method="POST" action="{{ route('admin.work_bays.assignments.destroy', [$workBay->id, $assignment->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:underline">&times;</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-2 text-gray-500">@lang('workbay::app.view.no-assignments')</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">
                    @lang('workbay::app.view.assign-service-order')
                </p>

                <form method="POST" action="{{ route('admin.work_bays.assignments.store', $workBay->id) }}" class="flex flex-wrap items-end gap-2">
                    @csrf

                    <div class="flex flex-col gap-1">
                        <label class="text-xs text-gray-500">OS</label>
                        <select name="quote_id" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900">
                            @foreach ($serviceOrders as $serviceOrder)
                                <option value="{{ $serviceOrder->id }}">#{{ $serviceOrder->id }} - {{ $serviceOrder->subject }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs text-gray-500">@lang('workbay::app.view.starts-at')</label>
                        <input type="datetime-local" name="starts_at" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900" required />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs text-gray-500">@lang('workbay::app.view.ends-at')</label>
                        <input type="datetime-local" name="ends_at" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900" />
                    </div>

                    <button type="submit" class="primary-button">
                        @lang('workbay::app.view.assign-service-order')
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-admin::layouts>
