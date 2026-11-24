{{--
    "Anotações e anexos" da OS: o "+" que deixa juntar qualquer coisa à ordem de serviço
    (texto, fotos, vídeos e outros arquivos). Aparece em todas as etapas.
--}}
@if (\Webkul\ServiceOrder\Services\ExtrasOs::disponivel())
    @php
        $extras = $serviceOrder->extras()->with('autor')->get();
        $servicoExtras = app(\Webkul\ServiceOrder\Services\ExtrasOs::class);
        $comErroExtra = collect($errors->keys())->contains(fn ($chave) => str_starts_with($chave, 'extra_'));
        $tamanho = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : number_format(max($bytes, 1) / 1024, 0, ',', '.').' KB';
    @endphp

    <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="anotacoes-anexos">
        <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('service_order::app.extras.title') ({{ $extras->count() }})</p>

        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.extras.dica')</p>

        {{-- Botão com texto claro (antes era só um "+" no canto, que ninguém entendia). --}}
        <button
            type="button"
            id="extras-mais"
            aria-controls="extras-form"
            aria-expanded="{{ $comErroExtra ? 'true' : 'false' }}"
            data-aberto="@lang('service_order::app.extras.fechar')"
            data-fechado="+ @lang('service_order::app.extras.adicionar')"
            style="display:inline-flex;align-items:center;margin-top:10px;padding:8px 16px;border-radius:8px;border:0;background:#2563eb;color:#ffffff;font-size:14px;font-weight:600;cursor:pointer"
            onclick="var f = document.getElementById('extras-form'); f.hidden = ! f.hidden; this.setAttribute('aria-expanded', String(! f.hidden)); this.textContent = f.hidden ? this.dataset.fechado : this.dataset.aberto;"
        >{{ $comErroExtra ? trans('service_order::app.extras.fechar') : '+ '.trans('service_order::app.extras.adicionar') }}</button>

        <form
            id="extras-form"
            method="POST"
            action="{{ route('admin.service_orders.extras.store', $serviceOrder->id) }}"
            enctype="multipart/form-data"
            class="mt-3 flex flex-col gap-3 rounded-md border border-gray-200 p-3 dark:border-gray-800"
            @unless ($comErroExtra) hidden @endunless
        >
            @csrf

            @include('service_order::partials.extras-campos')

            <div><button type="submit" class="primary-button">@lang('service_order::app.extras.salvar-btn')</button></div>
        </form>

        @forelse ($extras as $extra)
            <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-800">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        @if ($extra->titulo)
                            <p class="text-base font-semibold dark:text-white">{{ $extra->titulo }}</p>
                        @endif
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $extra->autor?->name ?? '-' }} &middot; {{ core()->formatDate($extra->created_at) }}</p>
                    </div>

                    @if ($servicoExtras->podeRemover($extra))
                        <form method="POST" action="{{ route('admin.service_orders.extras.destroy', [$serviceOrder->id, $extra->id]) }}" onsubmit="return confirm('Remover esta anotação e os arquivos dela?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-red-600 hover:underline">@lang('service_order::app.extras.remover')</button>
                        </form>
                    @endif
                </div>

                @if ($extra->texto)
                    <p class="mt-1 whitespace-pre-line text-sm dark:text-white">{{ $extra->texto }}</p>
                @endif

                @php $arquivosExtra = collect($extra->arquivosComUrl()); @endphp

                @if ($arquivosExtra->where('tipo', 'foto')->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($arquivosExtra->where('tipo', 'foto') as $foto)
                            <a href="{{ $foto['url'] }}" target="_blank" rel="noopener" title="{{ $foto['nome'] }}"><img src="{{ $foto['url'] }}" alt="{{ $foto['nome'] }}" loading="lazy" class="h-20 w-20 rounded object-cover"></a>
                        @endforeach
                    </div>
                @endif

                @if ($arquivosExtra->where('tipo', 'video')->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach ($arquivosExtra->where('tipo', 'video') as $video)
                            <video src="{{ $video['url'] }}" controls preload="metadata" class="h-36 w-64 rounded bg-black"></video>
                        @endforeach
                    </div>
                @endif

                @if ($arquivosExtra->where('tipo', 'arquivo')->isNotEmpty())
                    <ul class="mt-2 flex flex-col gap-1 text-sm">
                        @foreach ($arquivosExtra->where('tipo', 'arquivo') as $arquivo)
                            <li><a href="{{ $arquivo['url'] }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline">{{ $arquivo['nome'] }}</a> <span class="text-xs text-gray-500">({{ $tamanho($arquivo['tamanho']) }})</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.extras.vazio')</p>
        @endforelse
    </div>
@endif
