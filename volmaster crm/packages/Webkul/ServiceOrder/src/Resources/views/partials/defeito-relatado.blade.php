{{--
    Aba "Defeito relatado pelo motorista" (reunião de 19/11/2026): o que o motorista contou e o defeito
    em poucas palavras. Fica na OS desde o Cadastro e pode ser completado a qualquer momento, sem
    abrir a tela de edição. Estilo direto no HTML nos pontos visuais (o CSS compilado do servidor
    só tem as classes que já existiam).
--}}
@php
    $podeEditarDefeito = bouncer()->hasPermission('service_orders.edit');
    $campoDefeito = 'w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotuloDefeito = 'text-xs text-gray-500 dark:text-gray-400';
@endphp

<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="defeito-relatado">
    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">@lang('service_order::app.defeito.title')</p>
    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.defeito.dica')</p>

    @if ($podeEditarDefeito)
        <form method="POST" action="{{ route('admin.service_orders.defeito.update', $serviceOrder->id) }}" class="flex flex-col gap-3">
            @csrf
            @method('PUT')

            <div class="flex flex-col gap-1">
                <label for="defeito-relato" class="{{ $rotuloDefeito }}">@lang('service_order::app.form.relato_motorista')</label>
                <textarea id="defeito-relato" name="relato_motorista" rows="3" maxlength="2000" placeholder="O que o motorista contou: quando começou, em que situação acontece, o que ele sentiu..." class="{{ $campoDefeito }}">{{ old('relato_motorista', $serviceOrder->relato_motorista) }}</textarea>
                @error('relato_motorista')
                    <p class="text-xs italic text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="defeito-resumo" class="{{ $rotuloDefeito }}">@lang('service_order::app.form.defeito_relatado')</label>
                <textarea id="defeito-resumo" name="defeito_relatado" rows="2" maxlength="2000" placeholder="O defeito em poucas palavras. Ex.: engasgando em subida, luz da injeção acesa..." class="{{ $campoDefeito }}">{{ old('defeito_relatado', $serviceOrder->defeito_relatado) }}</textarea>
                @error('defeito_relatado')
                    <p class="text-xs italic text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div><button type="submit" class="primary-button">@lang('service_order::app.defeito.salvar-btn')</button></div>
        </form>
    @else
        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
            <div>
                <p class="{{ $rotuloDefeito }}">@lang('service_order::app.form.relato_motorista')</p>
                <p class="whitespace-pre-line text-sm dark:text-white">{{ $serviceOrder->relato_motorista ?: '-' }}</p>
            </div>

            <div>
                <p class="{{ $rotuloDefeito }}">@lang('service_order::app.form.defeito_relatado')</p>
                <p class="whitespace-pre-line text-sm dark:text-white">{{ $serviceOrder->defeito_relatado ?: '-' }}</p>
            </div>
        </div>
    @endif

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        Fotos e vídeos do defeito entram em <a href="#anotacoes-anexos" style="color:#2563eb;text-decoration:underline">Anotações e anexos</a> (botão azul "+ Adicionar anotação ou anexo") e no checklist de entrada do Cadastro do cliente.
    </p>
</div>
