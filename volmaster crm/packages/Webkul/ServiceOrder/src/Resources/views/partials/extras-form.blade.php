{{--
    O "+" dentro dos formulários de criar e editar a OS: abre os campos de anotação e anexos
    (fotos, vídeos e outros arquivos). Opcional: sem nada preenchido, nada é gravado.
--}}
@if (\Webkul\ServiceOrder\Services\ExtrasOs::disponivel())
    @php
        $abrirExtras = old('extra_titulo') || old('extra_texto') || collect($errors->keys())->contains(fn ($chave) => str_starts_with($chave, 'extra_'));
    @endphp

    <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="anotacoes-anexos-form">
        <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('service_order::app.extras.opcional')</p>

        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.extras.dica')</p>

        {{-- Botão com texto claro (antes era só um "+" no canto, que ninguém entendia). --}}
        <button
            type="button"
            id="extras-mais-form"
            aria-controls="extras-campos-form"
            aria-expanded="{{ $abrirExtras ? 'true' : 'false' }}"
            data-aberto="@lang('service_order::app.extras.fechar')"
            data-fechado="+ @lang('service_order::app.extras.adicionar')"
            style="display:inline-flex;align-items:center;margin-top:10px;padding:8px 16px;border-radius:8px;border:0;background:#2563eb;color:#ffffff;font-size:14px;font-weight:600;cursor:pointer"
            onclick="var c = document.getElementById('extras-campos-form'); c.hidden = ! c.hidden; this.setAttribute('aria-expanded', String(! c.hidden)); this.textContent = c.hidden ? this.dataset.fechado : this.dataset.aberto;"
        >{{ $abrirExtras ? trans('service_order::app.extras.fechar') : '+ '.trans('service_order::app.extras.adicionar') }}</button>

        <div id="extras-campos-form" class="mt-3" @unless ($abrirExtras) hidden @endunless>
            @include('service_order::partials.extras-campos')
        </div>
    </div>
@endif
