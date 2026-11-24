{{-- One tenant's latest "Nota Fiscal" request, with approve/reject for pending ones. --}}
@if (! $fiscalRequest)
    <span class="text-xs text-gray-400">Não enviou</span>
@else
    @php
        $data = $fiscalRequest->payload;

        $labels = [
            'pending' => ['Aguardando aprovação', 'color:#ca8a04'],
            'approved' => ['Ativa', 'color:#16a34a'],
            'rejected' => ['Recusado', 'color:#dc2626'],
            'failed' => ['Falhou na Focus', 'color:#dc2626'],
        ];

        [$label, $style] = $labels[$fiscalRequest->status] ?? [$fiscalRequest->status, ''];
    @endphp

    <p class="text-xs font-semibold" style="{{ $style }}">{{ $label }}</p>

    <p class="mt-1 text-xs">
        {{ $data['razao_social'] ?? '-' }}<br>
        CNPJ {{ $data['cnpj'] ?? '-' }} &middot; IM {{ $data['inscricao_municipal'] ?? '-' }}
        @if (! empty($data['inscricao_estadual']))
            &middot; IE {{ $data['inscricao_estadual'] }}
        @endif
        <br>
        {{ $data['municipio'] ?? '' }}/{{ $data['uf'] ?? '' }} &middot; {{ $data['email'] ?? '' }}
    </p>

    @if ($fiscalRequest->error && $fiscalRequest->status !== 'approved')
        <p class="mt-1 max-w-xs text-xs" style="color:#dc2626">{{ $fiscalRequest->error }}</p>
    @endif

    @if ($fiscalRequest->status === 'pending')
        <div class="mt-2 flex flex-wrap gap-2">
            <form
                method="POST"
                action="{{ route('admin.tenants.fiscal_requests.approve', $fiscalRequest->id) }}"
                onsubmit="return confirm('Cadastrar essa empresa na Focus NFe? Isso conta como mais um CNPJ no plano.');"
            >
                @csrf

                <button type="submit" class="primary-button">Aprovar</button>
            </form>

            <form
                method="POST"
                action="{{ route('admin.tenants.fiscal_requests.reject', $fiscalRequest->id) }}"
                onsubmit="var m = prompt('Motivo da recusa (a oficina vai ver):'); if (m === null) return false; this.motivo.value = m; return true;"
            >
                @csrf

                <input type="hidden" name="motivo">

                <button type="submit" class="secondary-button">Recusar</button>
            </form>
        </div>
    @endif
@endif
