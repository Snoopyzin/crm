<x-admin::layouts>
    <x-slot:title>
        {{ $part->nome }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="parts.view" :entity="$part" />

                <div class="text-xl font-bold dark:text-white">
                    {{ $part->codigo }} &middot; {{ $part->nome }}
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('parts.edit'))
                    <a href="{{ route('admin.parts.edit', $part->id) }}" class="primary-button">
                        @lang('partinventory::app.index.datagrid.edit')
                    </a>
                @endif
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid grid-cols-3 gap-4 max-sm:grid-cols-1">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('partinventory::app.form.ncm')</p>
                    <p class="text-base font-medium dark:text-white">{{ $part->ncm ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('partinventory::app.form.cest')</p>
                    <p class="text-base font-medium dark:text-white">{{ $part->cest ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('partinventory::app.form.part_category_id')</p>
                    <p class="text-base font-medium dark:text-white">{{ $part->category->name ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('partinventory::app.form.valor_unitario')</p>
                    <p class="text-base font-medium dark:text-white">{{ core()->formatBasePrice($part->valor_unitario) }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('partinventory::app.form.fornecedor')</p>
                    <p class="text-base font-medium dark:text-white">{{ $part->fornecedor ?? '-' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('partinventory::app.form.quantidade_minima')</p>
                    <p class="text-base font-medium dark:text-white">{{ $part->quantidade_minima }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 max-lg:grid-cols-1">
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('partinventory::app.view.inventories')
                </p>

                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <th class="pb-2">@lang('partinventory::app.form.warehouse_id')</th>
                            <th class="pb-2">In Stock</th>
                            <th class="pb-2">Allocated</th>
                            <th class="pb-2">On Hand</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($part->inventories as $inventory)
                            <tr class="border-b border-gray-100 dark:border-gray-900">
                                <td class="py-2">{{ $inventory->warehouse->name ?? '-' }}</td>
                                <td class="py-2">{{ $inventory->in_stock }}</td>
                                <td class="py-2">{{ $inventory->allocated }}</td>
                                <td class="py-2">{{ $inventory->on_hand }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-2 text-gray-500 dark:text-gray-400">-</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($part->inventories->isNotEmpty())
                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                        <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">
                            @lang('partinventory::app.view.add-stock')
                        </p>

                        <form method="POST" action="{{ route('admin.parts.movements.store', $part->id) }}" class="flex flex-col gap-2">
                            @csrf

                            <select name="part_inventory_id" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                @foreach ($part->inventories as $inventory)
                                    <option value="{{ $inventory->id }}">{{ $inventory->warehouse->name ?? '-' }}</option>
                                @endforeach
                            </select>

                            <select name="type" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                <option value="entrada">{{ trans('partinventory::app.form.movement-type-options.entrada') }}</option>
                                <option value="ajuste">{{ trans('partinventory::app.form.movement-type-options.ajuste') }}</option>
                            </select>

                            <input type="number" name="quantity" placeholder="{{ trans('partinventory::app.form.movement-quantity') }}" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" required />

                            <input type="text" name="reason" placeholder="{{ trans('partinventory::app.form.movement-reason') }}" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />

                            <button type="submit" class="secondary-button">
                                @lang('partinventory::app.view.add-stock')
                            </button>
                        </form>
                    </div>
                @endif
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('partinventory::app.view.movements')
                </p>

                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <th class="pb-2">Type</th>
                            <th class="pb-2">Qty</th>
                            <th class="pb-2">Reason</th>
                            <th class="pb-2">Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $allMovements = $part->inventories->flatMap->movements->sortByDesc('created_at')->take(20);
                        @endphp

                        @forelse ($allMovements as $movement)
                            <tr class="border-b border-gray-100 dark:border-gray-900">
                                <td class="py-2">{{ ucfirst($movement->type) }}</td>
                                <td class="py-2">{{ $movement->quantity }}</td>
                                <td class="py-2">{{ $movement->reason ?? '-' }}</td>
                                <td class="py-2">{{ core()->formatDate($movement->created_at) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-2 text-gray-500 dark:text-gray-400">@lang('partinventory::app.view.no-movements')</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin::layouts>
