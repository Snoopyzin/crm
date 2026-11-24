<x-admin::layouts>
    <x-slot:title>
        @lang('service_order::app.create.title')
    </x-slot>

    <x-admin::form :action="route('admin.service_orders.store')" method="POST" enctype="multipart/form-data">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <x-admin::breadcrumbs name="service_orders.create" />

                    <div class="text-xl font-bold dark:text-white">
                        @lang('service_order::app.create.title')
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        @lang('service_order::app.create.save-btn')
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('service_order::app.form.general')
                </p>

                <div class="grid grid-cols-2 gap-x-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('service_order::app.form.person')
                        </x-admin::form.control-group.label>

                        {{-- Pesquisa por nome, código, CPF/CNPJ, telefone ou placa; etiqueta de crédito e pop-up de cadastro vencido. --}}
                        @include('service_order::partials.cliente-busca', ['clienteInicial' => $clienteInicial ?? null])

                        <x-admin::form.control-group.error control-name="person_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.form.vehicle') <span class="text-xs font-normal text-gray-500">(opcional)</span>
                        </x-admin::form.control-group.label>

                        <select
                            id="vehicle_id"
                            name="vehicle_id"
                            class="custom-select w-full rounded border border-gray-300 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        >
                            <option value="">@lang('service_order::app.form.select-vehicle-hint')</option>
                        </select>

                        <x-admin::form.control-group.error control-name="vehicle_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.form.mechanic')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            id="mechanic_id"
                            name="mechanic_id"
                            :value="old('mechanic_id')"
                            :label="trans('service_order::app.form.mechanic')"
                        >
                            <option value="">-</option>
                            @foreach ($mechanics as $mechanic)
                                <option value="{{ $mechanic->id }}">{{ $mechanic->nome }}</option>
                            @endforeach
                        </x-admin::form.control-group.control>

                        <x-admin::form.control-group.error control-name="mechanic_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.form.data_previsao')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="date"
                            id="data_previsao"
                            name="data_previsao"
                            :value="old('data_previsao')"
                            :label="trans('service_order::app.form.data_previsao')"
                        />

                        <x-admin::form.control-group.error control-name="data_previsao" />

                        @include('service_order::partials.hora-previsao')
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="col-span-2">
                        <x-admin::form.control-group.label class="required">
                            @lang('service_order::app.form.servico')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="servico"
                            name="servico"
                            rules="required"
                            :value="old('servico')"
                            :label="trans('service_order::app.form.servico')"
                        />

                        <x-admin::form.control-group.error control-name="servico" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="col-span-2">
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.form.relato_motorista')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            id="relato_motorista"
                            name="relato_motorista"
                            :value="old('relato_motorista')"
                            :label="trans('service_order::app.form.relato_motorista')"
                        />

                        <x-admin::form.control-group.error control-name="relato_motorista" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="col-span-2">
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.form.defeito_relatado')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            id="defeito_relatado"
                            name="defeito_relatado"
                            :value="old('defeito_relatado')"
                            :label="trans('service_order::app.form.defeito_relatado')"
                        />

                        <x-admin::form.control-group.error control-name="defeito_relatado" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="col-span-2">
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.form.description')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            id="description"
                            name="description"
                            :value="old('description')"
                            :label="trans('service_order::app.form.description')"
                        />

                        <x-admin::form.control-group.error control-name="description" />
                    </x-admin::form.control-group>
                </div>
            </div>

            @include('service_order::partials.extras-form')
        </div>
    </x-admin::form>

    @push('scripts')
        <script>
            function loadVehicles(personId) {
                const vehicleSelect = document.getElementById('vehicle_id');

                vehicleSelect.innerHTML = '<option value="">Sem veículo (serviço só de software)</option>';

                if (! personId) {
                    return;
                }

                fetch('{{ url('admin/service-orders/vehicles-by-person') }}/' + personId)
                    .then((response) => response.json())
                    .then((vehicles) => {
                        vehicles.forEach((vehicle) => {
                            const option = document.createElement('option');
                            option.value = vehicle.id;
                            option.textContent = vehicle.placa + ' - ' + vehicle.marca + ' ' + vehicle.modelo;
                            vehicleSelect.appendChild(option);
                        });
                    });
            }

            document.addEventListener('change', function (event) {
                if (event.target && event.target.id === 'person_id') {
                    loadVehicles(event.target.value);
                }
            });
        </script>
    @endpush
</x-admin::layouts>
