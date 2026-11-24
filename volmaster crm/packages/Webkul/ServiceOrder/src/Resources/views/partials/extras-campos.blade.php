{{--
    Campos do "+" da OS (anotação e anexos): título, texto, fotos, vídeos e outros arquivos.
    Usado dentro de um formulário que já tem enctype="multipart/form-data".
--}}
@php
    $campoExtra = 'w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotuloExtra = 'text-xs text-gray-500 dark:text-gray-400';
    $erroExtra = fn (string $campo) => collect($errors->keys())->filter(fn ($chave) => $chave === $campo || str_starts_with($chave, $campo.'.'))->flatMap(fn ($chave) => $errors->get($chave))->unique()->all();
@endphp

<div class="grid grid-cols-2 gap-x-4 gap-y-3 max-sm:grid-cols-1">
    <div class="flex flex-col gap-1">
        <label for="extra_titulo" class="{{ $rotuloExtra }}">@lang('service_order::app.extras.titulo')</label>
        <input type="text" id="extra_titulo" name="extra_titulo" maxlength="150" value="{{ old('extra_titulo') }}" placeholder="@lang('service_order::app.extras.titulo-placeholder')" class="{{ $campoExtra }}">
        @foreach ($erroExtra('extra_titulo') as $mensagem)
            <p class="text-xs italic text-red-600">{{ $mensagem }}</p>
        @endforeach
    </div>

    <div class="flex flex-col gap-1 col-span-2 max-sm:col-span-1">
        <label for="extra_texto" class="{{ $rotuloExtra }}">@lang('service_order::app.extras.texto')</label>
        <textarea id="extra_texto" name="extra_texto" rows="3" maxlength="5000" placeholder="@lang('service_order::app.extras.texto-placeholder')" class="{{ $campoExtra }}">{{ old('extra_texto') }}</textarea>
        @foreach ($erroExtra('extra_texto') as $mensagem)
            <p class="text-xs italic text-red-600">{{ $mensagem }}</p>
        @endforeach
    </div>

    <label class="col-span-2 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300 max-sm:col-span-1">
        <input type="checkbox" name="extra_visivel_cliente" value="1" class="mt-1" @checked(old('extra_visivel_cliente', ! session()->hasOldInput()))>
        <span><b>Mostrar ao cliente</b> no link de acompanhamento. Desmarque para anotações internas.</span>
    </label>

    <div class="flex flex-col gap-1">
        <label for="extra_fotos" class="{{ $rotuloExtra }}">@lang('service_order::app.extras.fotos')</label>
        <input type="file" id="extra_fotos" name="extra_fotos[]" accept="image/*" multiple class="w-full text-sm text-gray-700 dark:text-gray-300">
        <p class="{{ $rotuloExtra }}">Até 20 fotos, 10 MB cada (JPG, PNG, WEBP ou HEIC).</p>
        @foreach ($erroExtra('extra_fotos') as $mensagem)
            <p class="text-xs italic text-red-600">{{ $mensagem }}</p>
        @endforeach
    </div>

    <div class="flex flex-col gap-1">
        <label for="extra_videos" class="{{ $rotuloExtra }}">@lang('service_order::app.extras.videos')</label>
        <input type="file" id="extra_videos" name="extra_videos[]" accept="video/*" multiple class="w-full text-sm text-gray-700 dark:text-gray-300">
        <p class="{{ $rotuloExtra }}">Até 5 vídeos, 100 MB cada (MP4, MOV, WEBM, 3GP).</p>
        @foreach ($erroExtra('extra_videos') as $mensagem)
            <p class="text-xs italic text-red-600">{{ $mensagem }}</p>
        @endforeach
    </div>

    <div class="flex flex-col gap-1 col-span-2 max-sm:col-span-1">
        <label for="extra_arquivos" class="{{ $rotuloExtra }}">@lang('service_order::app.extras.arquivos')</label>
        <input type="file" id="extra_arquivos" name="extra_arquivos[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.odt,.ods,.mp3,.m4a,.wav,.ogg,audio/*" multiple class="w-full text-sm text-gray-700 dark:text-gray-300">
        <p class="{{ $rotuloExtra }}">Até 10 arquivos, 20 MB cada (PDF, Word, Excel, CSV, TXT e áudio).</p>
        @foreach ($erroExtra('extra_arquivos') as $mensagem)
            <p class="text-xs italic text-red-600">{{ $mensagem }}</p>
        @endforeach
    </div>
</div>
