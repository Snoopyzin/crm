@php
    $isEdit = isset($mechanic) && $mechanic;
@endphp

<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('mechanic::app.form.general')
    </p>

    {{-- Código sequencial (1, 2, 3...): automático, na ordem de cadastro, e não se edita. --}}
    @if (\Webkul\ServiceOrder\Services\CodigoSequencial::disponivel('mechanics'))
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            Código do mecânico:
            @if ($isEdit && $mechanic->codigo)
                <strong>Nº {{ $mechanic->codigo }}</strong>
            @else
                <span class="text-gray-500 dark:text-gray-400">gerado automaticamente ao salvar (1, 2, 3... na ordem de cadastro).</span>
            @endif
        </p>
    @endif

    <div class="grid grid-cols-2 gap-x-4 max-sm:grid-cols-1">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('mechanic::app.form.nome')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="nome"
                name="nome"
                rules="required"
                :value="old('nome') ?? ($isEdit ? $mechanic->nome : '')"
                :label="trans('mechanic::app.form.nome')"
            />

            <x-admin::form.control-group.error control-name="nome" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('mechanic::app.form.especialidade')
            </x-admin::form.control-group.label>

            @php
                $especialidadeOptions = ['motor', 'transmissao', 'eletrica', 'diagnostico', 'suspensao', 'freios', 'hidraulica', 'arrefecimento'];
                $especialidadeValue = old('especialidade') ?? ($isEdit ? $mechanic->especialidade : 'motor');
            @endphp

            <x-admin::form.control-group.control
                type="select"
                id="especialidade"
                name="especialidade"
                rules="required"
                :value="$especialidadeValue"
                :label="trans('mechanic::app.form.especialidade')"
            >
                @foreach ($especialidadeOptions as $option)
                    <option value="{{ $option }}" @selected($especialidadeValue === $option)>
                        {{ trans('mechanic::app.form.especialidade-options.'.$option) }}
                    </option>
                @endforeach
            </x-admin::form.control-group.control>

            <x-admin::form.control-group.error control-name="especialidade" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>
                @lang('mechanic::app.form.years_of_experience')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="years_of_experience"
                name="years_of_experience"
                :value="old('years_of_experience') ?? ($isEdit ? $mechanic->years_of_experience : '')"
                :label="trans('mechanic::app.form.years_of_experience')"
            />

            <x-admin::form.control-group.error control-name="years_of_experience" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>
                @lang('mechanic::app.form.avaliacao')
            </x-admin::form.control-group.label>

            <x-admin::form.control-group.control
                type="text"
                id="avaliacao"
                name="avaliacao"
                :value="old('avaliacao') ?? ($isEdit ? $mechanic->avaliacao : 0)"
                :label="trans('mechanic::app.form.avaliacao')"
            />

            <x-admin::form.control-group.error control-name="avaliacao" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                @lang('mechanic::app.form.status')
            </x-admin::form.control-group.label>

            @php
                $statusValue = old('status') ?? ($isEdit ? $mechanic->status : 'Ativo');
                $isLegendLocked = $isEdit && $mechanic->status === 'Lenda';
            @endphp

            <x-admin::form.control-group.control
                type="select"
                id="status"
                name="status"
                rules="required"
                :value="$statusValue"
                :label="trans('mechanic::app.form.status')"
                :disabled="$isLegendLocked"
            >
                <option value="Ativo" @selected($statusValue === 'Ativo')>{{ trans('mechanic::app.form.status-options.ativo') }}</option>
                <option value="Lenda" @selected($statusValue === 'Lenda')>{{ trans('mechanic::app.form.status-options.lenda') }}</option>
            </x-admin::form.control-group.control>

            @if ($isLegendLocked)
                <input type="hidden" name="status" value="Lenda" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @lang('mechanic::app.errors.legend-status-locked')
                </p>
            @endif

            <x-admin::form.control-group.error control-name="status" />
        </x-admin::form.control-group>
    </div>
</div>
