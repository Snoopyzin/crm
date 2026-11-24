<x-admin::layouts>
    <x-slot:title>
        @lang('partinventory::app.create.title')
    </x-slot>

    <x-admin::form :action="route('admin.parts.store')" method="POST">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <x-admin::breadcrumbs name="parts.create" />

                    <div class="text-xl font-bold dark:text-white">
                        @lang('partinventory::app.create.title')
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        @lang('partinventory::app.create.save-btn')
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('partinventory::app.form.general')
                </p>

                <div class="grid grid-cols-2 gap-x-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('partinventory::app.form.codigo')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="codigo"
                            name="codigo"
                            rules="required"
                            :value="old('codigo')"
                            :label="trans('partinventory::app.form.codigo')"
                        />

                        <x-admin::form.control-group.error control-name="codigo" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('partinventory::app.form.nome')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="nome"
                            name="nome"
                            rules="required"
                            :value="old('nome')"
                            :label="trans('partinventory::app.form.nome')"
                        />

                        <x-admin::form.control-group.error control-name="nome" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('partinventory::app.form.ncm')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="ncm"
                            name="ncm"
                            rules="digits:8"
                            placeholder="00000000"
                            :value="old('ncm')"
                            :label="trans('partinventory::app.form.ncm')"
                        />

                        <x-admin::form.control-group.error control-name="ncm" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('partinventory::app.form.cest')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="cest"
                            name="cest"
                            rules="digits:7"
                            placeholder="0000000"
                            :value="old('cest')"
                            :label="trans('partinventory::app.form.cest')"
                        />

                        <x-admin::form.control-group.error control-name="cest" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <div class="flex items-center justify-between">
                            <x-admin::form.control-group.label>
                                @lang('partinventory::app.form.part_category_id')
                            </x-admin::form.control-group.label>

                            <button type="button" onclick="toggleNewCategory()" id="new-category-toggle" class="text-xs font-semibold text-blue-600 hover:underline">
                                @lang('partinventory::app.form.new-category-btn')
                            </button>
                        </div>

                        <select
                            id="part_category_id"
                            name="part_category_id"
                            class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                        >
                            <option value="">@lang('partinventory::app.form.no-category')</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>

                        <input
                            type="text"
                            id="new_category_name"
                            name="new_category_name"
                            placeholder="{{ trans('partinventory::app.form.new-category-placeholder') }}"
                            class="hidden rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                        />

                        <x-admin::form.control-group.error control-name="new_category_name" />
                        <x-admin::form.control-group.error control-name="part_category_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('partinventory::app.form.valor_unitario')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="valor_unitario"
                            name="valor_unitario"
                            data-money
                            rules="required"
                            :value="old('valor_unitario')"
                            :label="trans('partinventory::app.form.valor_unitario')"
                        />

                        <x-admin::form.control-group.error control-name="valor_unitario" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('partinventory::app.form.fornecedor')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="fornecedor"
                            name="fornecedor"
                            :value="old('fornecedor')"
                            :label="trans('partinventory::app.form.fornecedor')"
                        />

                        <x-admin::form.control-group.error control-name="fornecedor" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('partinventory::app.form.quantidade_minima')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="quantidade_minima"
                            name="quantidade_minima"
                            :value="old('quantidade_minima', 0)"
                            :label="trans('partinventory::app.form.quantidade_minima')"
                        />

                        <x-admin::form.control-group.error control-name="quantidade_minima" />
                    </x-admin::form.control-group>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('partinventory::app.form.stock')
                </p>

                <div class="grid grid-cols-2 gap-x-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('partinventory::app.form.warehouse_id')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            id="warehouse_id"
                            name="warehouse_id"
                            rules="required"
                            :value="old('warehouse_id')"
                            :label="trans('partinventory::app.form.warehouse_id')"
                        >
                            <option value="">-</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                            @endforeach
                        </x-admin::form.control-group.control>

                        <x-admin::form.control-group.error control-name="warehouse_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('partinventory::app.form.in_stock')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="in_stock"
                            name="in_stock"
                            :value="old('in_stock', 0)"
                            :label="trans('partinventory::app.form.in_stock')"
                        />

                        <x-admin::form.control-group.error control-name="in_stock" />
                    </x-admin::form.control-group>
                </div>

                @if ($warehouses->isEmpty())
                    <p class="mt-2 text-sm text-red-600">
                        {{ trans('partinventory::app.form.warehouse_id') }}: @lang('admin::app.settings.warehouses.index.title') &mdash;
                        <a href="{{ route('admin.settings.warehouses.create') }}" class="underline">
                            {{ trans('admin::app.settings.warehouses.index.create-btn') }}
                        </a>
                    </p>
                @endif
            </div>
        </div>
    </x-admin::form>

    @push('scripts')
        <script>
            function toggleNewCategory() {
                const select = document.getElementById('part_category_id');
                const input = document.getElementById('new_category_name');
                const toggleBtn = document.getElementById('new-category-toggle');
                const creating = input.classList.contains('hidden');

                select.classList.toggle('hidden', creating);
                input.classList.toggle('hidden', ! creating);

                if (creating) {
                    input.focus();
                    toggleBtn.textContent = '{{ trans('partinventory::app.form.use-existing-category-btn') }}';
                } else {
                    input.value = '';
                    toggleBtn.textContent = '{{ trans('partinventory::app.form.new-category-btn') }}';
                }
            }
        </script>
    @endpush
</x-admin::layouts>
