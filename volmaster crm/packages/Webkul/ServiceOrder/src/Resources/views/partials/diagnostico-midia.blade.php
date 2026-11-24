{{--
    Fotos e vídeos do diagnóstico: lista o que já foi enviado e deixa mandar mais.
    Usado nas duas telas de diagnóstico (a do piloto, no "Fluxo da OS", e a antiga).
--}}
@php
    $diagFotos = $serviceOrder->attachments->where('tipo', \Webkul\ServiceOrder\Models\ServiceOrderAttachment::TIPO_DIAGNOSTICO_FOTO);
    $diagVideos = $serviceOrder->attachments->where('tipo', \Webkul\ServiceOrder\Models\ServiceOrderAttachment::TIPO_DIAGNOSTICO_VIDEO);
    $rotuloMidia = 'text-xs text-gray-500 dark:text-gray-400';
@endphp

<div class="flex flex-col gap-2" id="diagnostico-midia">
    <p class="text-sm font-semibold text-gray-800 dark:text-white">@lang('service_order::app.diagnostico.midia') ({{ $diagFotos->count() + $diagVideos->count() }})</p>

    @if ($diagFotos->isEmpty() && $diagVideos->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.diagnostico.midia-vazia')</p>
    @endif

    @if ($diagFotos->isNotEmpty())
        <div class="flex flex-wrap gap-2">
            @foreach ($diagFotos as $foto)
                <a href="{{ $foto->url() }}" target="_blank" rel="noopener"><img src="{{ $foto->url() }}" alt="Foto do diagnóstico" loading="lazy" class="h-20 w-20 rounded object-cover"></a>
            @endforeach
        </div>
    @endif

    @if ($diagVideos->isNotEmpty())
        <div class="flex flex-wrap gap-3">
            @foreach ($diagVideos as $video)
                <video src="{{ $video->url() }}" controls preload="metadata" class="h-36 w-64 rounded bg-black"></video>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.service_orders.workflow.diagnostico.midia', $serviceOrder->id) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
        @csrf

        <div class="flex flex-col gap-1">
            <label for="diagnostico-fotos" class="{{ $rotuloMidia }}">@lang('service_order::app.diagnostico.fotos')</label>
            <input type="file" id="diagnostico-fotos" name="fotos[]" accept="image/*" multiple class="w-56 text-sm text-gray-700 dark:text-gray-300">
        </div>

        <div class="flex flex-col gap-1">
            <label for="diagnostico-videos" class="{{ $rotuloMidia }}">@lang('service_order::app.diagnostico.videos')</label>
            <input type="file" id="diagnostico-videos" name="videos[]" accept="video/*" multiple class="w-56 text-sm text-gray-700 dark:text-gray-300">
        </div>

        <button type="submit" class="secondary-button">@lang('service_order::app.diagnostico.midia-btn')</button>
    </form>

    @foreach (['fotos', 'videos'] as $campoMidia)
        @error($campoMidia)
            <p class="text-xs italic text-red-600">{{ $message }}</p>
        @enderror
        @foreach ($errors->get($campoMidia.'.*') as $mensagensMidia)
            @foreach ($mensagensMidia as $mensagemMidia)
                <p class="text-xs italic text-red-600">{{ $mensagemMidia }}</p>
            @endforeach
        @endforeach
    @endforeach

    <p class="{{ $rotuloMidia }}">Até 20 fotos (10 MB cada) e 3 vídeos (100 MB cada) por envio.</p>
</div>
