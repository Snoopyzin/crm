{{--
    Checklist de entrada do veículo (preenchido no Novo Cadastro): km, combustível,
    chassi, descrição, itens/avarias, fotos e vídeos. Só leitura; aparece se houver
    qualquer dado (com o piloto ligado ou não).
--}}
@php
    $checklistFotos = $serviceOrder->attachments->where('tipo', \Webkul\ServiceOrder\Models\ServiceOrderAttachment::TIPO_TERMO_FOTO);
    $checklistVideos = $serviceOrder->attachments->where('tipo', \Webkul\ServiceOrder\Models\ServiceOrderAttachment::TIPO_TERMO_VIDEO);
    $checklistChassi = $serviceOrder->vehicle?->chassi;

    $temChecklist = $serviceOrder->km_entrada || $serviceOrder->combustivel_entrada || $serviceOrder->descricao_entrada
        || $serviceOrder->itens_entrada || $checklistChassi || $checklistFotos->isNotEmpty() || $checklistVideos->isNotEmpty();
@endphp

@if ($temChecklist)
    <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="checklist-entrada">
        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">Checklist de entrada do veículo</p>

        <div class="grid grid-cols-3 gap-4 max-sm:grid-cols-1">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Km atual (hodômetro)</p>
                <p class="text-base font-medium dark:text-white">{{ $serviceOrder->km_entrada ? number_format((int) $serviceOrder->km_entrada, 0, ',', '.').' km' : '-' }}</p>
            </div>

            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Quantidade de combustível</p>
                <p class="text-base font-medium dark:text-white">{{ $serviceOrder->combustivel_entrada ?: '-' }}</p>
            </div>

            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Chassi</p>
                <p class="text-base font-medium dark:text-white">{{ $checklistChassi ?: '-' }}</p>
            </div>
        </div>

        @if ($serviceOrder->descricao_entrada)
            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">Descrição do veículo</p>
                <p class="whitespace-pre-line text-base dark:text-white">{{ $serviceOrder->descricao_entrada }}</p>
            </div>
        @endif

        @if ($serviceOrder->itens_entrada)
            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">Itens deixados no veículo / avarias</p>
                <p class="whitespace-pre-line text-base dark:text-white">{{ $serviceOrder->itens_entrada }}</p>
            </div>
        @endif

        @if ($checklistFotos->isNotEmpty())
            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">Fotos ({{ $checklistFotos->count() }})</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($checklistFotos as $foto)
                        <a href="{{ $foto->url() }}" target="_blank" rel="noopener"><img src="{{ $foto->url() }}" alt="Foto do veículo" loading="lazy" class="h-20 w-20 rounded object-cover"></a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($checklistVideos->isNotEmpty())
            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">Vídeos ({{ $checklistVideos->count() }})</p>
                <div class="flex flex-wrap gap-3">
                    @foreach ($checklistVideos as $video)
                        <video src="{{ $video->url() }}" controls preload="metadata" class="h-36 w-64 rounded bg-black"></video>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
