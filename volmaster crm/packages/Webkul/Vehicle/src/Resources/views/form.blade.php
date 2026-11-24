@php
    $isEdit = isset($vehicle) && $vehicle;
@endphp

<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('vehicle::app.form.general')
    </p>

    <div class="grid grid-cols-2 gap-x-4 max-sm:grid-cols-1">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('vehicle::app.form.person')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="select"
                id="person_id"
                name="person_id"
                rules="required"
                :value="old('person_id') ?? ($isEdit ? $vehicle->person_id : '')"
                :label="trans('vehicle::app.form.person')"
            >
                <option value="">-</option>
                @foreach ($persons as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                @endforeach
            </x-admin::form.control-group.control>

            <x-admin::form.control-group.error control-name="person_id" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('vehicle::app.form.placa')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="placa"
                name="placa"
                rules="required"
                :value="old('placa') ?? ($isEdit ? $vehicle->placa : '')"
                :label="trans('vehicle::app.form.placa')"
                placeholder="ABC-1234"
            />

            <x-admin::form.control-group.error control-name="placa" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('vehicle::app.form.marca')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="marca"
                name="marca"
                rules="required"
                :value="old('marca') ?? ($isEdit ? $vehicle->marca : '')"
                :label="trans('vehicle::app.form.marca')"
                placeholder="Volvo"
            />

            <x-admin::form.control-group.error control-name="marca" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('vehicle::app.form.modelo')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="modelo"
                name="modelo"
                rules="required"
                :value="old('modelo') ?? ($isEdit ? $vehicle->modelo : '')"
                :label="trans('vehicle::app.form.modelo')"
                placeholder="FH 540"
            />

            <x-admin::form.control-group.error control-name="modelo" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>
                @lang('vehicle::app.form.ano')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="ano"
                name="ano"
                :value="old('ano') ?? ($isEdit ? $vehicle->ano : '')"
                :label="trans('vehicle::app.form.ano')"
                placeholder="2024"
            />

            <x-admin::form.control-group.error control-name="ano" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>
                @lang('vehicle::app.form.chassi')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="chassi"
                name="chassi"
                :value="old('chassi') ?? ($isEdit ? $vehicle->chassi : '')"
                :label="trans('vehicle::app.form.chassi')"
            />

            <x-admin::form.control-group.error control-name="chassi" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>
                @lang('vehicle::app.form.km')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="km"
                name="km"
                :value="old('km') ?? ($isEdit ? $vehicle->km : 0)"
                :label="trans('vehicle::app.form.km')"
            />

            <x-admin::form.control-group.error control-name="km" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>
                @lang('vehicle::app.form.cor')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="cor"
                name="cor"
                :value="old('cor') ?? ($isEdit ? $vehicle->cor : '')"
                :label="trans('vehicle::app.form.cor')"
            />

            <x-admin::form.control-group.error control-name="cor" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>
                Frota
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="frota"
                name="frota"
                placeholder="Nº da frota do cliente (opcional)"
                :value="old('frota') ?? ($isEdit ? $vehicle->frota : '')"
                label="Frota"
            />

            <x-admin::form.control-group.error control-name="frota" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('vehicle::app.form.tipo')
            </x-admin::form.control-group.label>

            @php
                $tipoValue = old('tipo') ?? ($isEdit ? $vehicle->tipo : 'caminhoes');
            @endphp

            <x-admin::form.control-group.control
                type="select"
                id="tipo"
                name="tipo"
                rules="required"
                :value="$tipoValue"
                :label="trans('vehicle::app.form.tipo')"
            >
                @foreach (['carros', 'motos', 'caminhoes'] as $tipoOption)
                    <option value="{{ $tipoOption }}" @selected($tipoValue === $tipoOption)>
                        {{ trans('vehicle::app.form.tipo-options.'.$tipoOption) }}
                    </option>
                @endforeach
            </x-admin::form.control-group.control>

            <x-admin::form.control-group.error control-name="tipo" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>
                @lang('vehicle::app.form.combustivel')
            </x-admin::form.control-group.label>

            @php
                $combustivelValue = old('combustivel') ?? ($isEdit ? $vehicle->combustivel : '');
            @endphp

            <x-admin::form.control-group.control
                type="select"
                id="combustivel"
                name="combustivel"
                :value="$combustivelValue"
                :label="trans('vehicle::app.form.combustivel')"
            >
                <option value="">-</option>
                @foreach (['Flex', 'Gasolina', 'Etanol', 'Diesel', 'Elétrico', 'Híbrido', 'GNV'] as $fuelOption)
                    <option value="{{ $fuelOption }}" @selected($combustivelValue === $fuelOption)>
                        {{ $fuelOption }}
                    </option>
                @endforeach
            </x-admin::form.control-group.control>

            <x-admin::form.control-group.error control-name="combustivel" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('vehicle::app.form.status')
            </x-admin::form.control-group.label>

            @php
                $statusValue = old('status') ?? ($isEdit ? $vehicle->status : 'Ativo');
            @endphp

            <x-admin::form.control-group.control
                type="select"
                id="status"
                name="status"
                rules="required"
                :value="$statusValue"
                :label="trans('vehicle::app.form.status')"
            >
                <option value="Ativo" @selected($statusValue === 'Ativo')>{{ trans('vehicle::app.form.status-options.ativo') }}</option>
                <option value="Inativo" @selected($statusValue === 'Inativo')>{{ trans('vehicle::app.form.status-options.inativo') }}</option>
            </x-admin::form.control-group.control>

            <x-admin::form.control-group.error control-name="status" />
        </x-admin::form.control-group>
    </div>
</div>
