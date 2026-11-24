<x-admin::layouts>
    <x-slot:title>
        @lang('partinventory::app.services.create.title')
    </x-slot>

    <x-admin::form :action="route('admin.services.store')" method="POST">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <x-admin::breadcrumbs name="services.create" />

                    <div class="text-xl font-bold dark:text-white">
                        @lang('partinventory::app.services.create.title')
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        @lang('partinventory::app.services.create.save-btn')
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
                            @lang('partinventory::app.services.form.nome')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="nome"
                            name="nome"
                            rules="required"
                            :value="old('nome')"
                            :label="trans('partinventory::app.services.form.nome')"
                        />

                        <x-admin::form.control-group.error control-name="nome" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('partinventory::app.services.form.preco_padrao')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="preco_padrao"
                            name="preco_padrao"
                            data-money
                            rules="required"
                            :value="old('preco_padrao')"
                            :label="trans('partinventory::app.services.form.preco_padrao')"
                        />

                        <x-admin::form.control-group.error control-name="preco_padrao" />
                    </x-admin::form.control-group>

                    @if (\Illuminate\Support\Facades\Schema::hasColumn('services', 'tempo_padrao_horas'))
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Tempo padrão (horas)
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="text"
                                id="tempo_padrao_horas"
                                name="tempo_padrao_horas"
                                :value="old('tempo_padrao_horas')"
                                label="Tempo padrão (horas)"
                            />

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Opcional. Ao lançar este serviço na OS, as horas vêm daqui e viram o tempo padrão do cronômetro do técnico.</p>

                            <x-admin::form.control-group.error control-name="tempo_padrao_horas" />
                        </x-admin::form.control-group>
                    @endif

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
                        <x-admin::form.control-group.label>
                            @lang('partinventory::app.services.form.descricao')
                        </x-admin::form.control-group.label>

                        <textarea
                            id="descricao"
                            name="descricao"
                            rows="3"
                            class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                        >{{ old('descricao') }}</textarea>

                        <x-admin::form.control-group.error control-name="descricao" />
                    </x-admin::form.control-group>
                </div>
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
