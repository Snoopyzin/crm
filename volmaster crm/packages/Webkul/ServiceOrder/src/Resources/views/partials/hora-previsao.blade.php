{{--
    Horário opcional da previsão de conclusão (veículo que entra e sai no mesmo dia).
    Só aparece quando o banco já tem a coluna; sem horário, o prazo vale até o fim do dia.
--}}
@if (\Webkul\ServiceOrder\Services\PrevisaoOs::disponivel())
    <div class="mt-2">
        <label for="hora_previsao" class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-300">
            @lang('service_order::app.form.hora_previsao')
        </label>

        <input
            type="time"
            id="hora_previsao"
            name="hora_previsao"
            step="60"
            value="{{ old('hora_previsao', \Webkul\ServiceOrder\Services\PrevisaoOs::hora($valor ?? null)) }}"
            class="w-40 rounded border border-gray-300 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
        >

        @error('hora_previsao')
            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
        @enderror

        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.form.hora_previsao_dica')</p>
    </div>
@endif
