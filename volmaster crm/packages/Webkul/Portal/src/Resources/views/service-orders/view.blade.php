@extends('portal::layouts.master')

@section('title', '#'.$serviceOrder->id.' - '.$serviceOrder->subject)

@section('content')
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <h1 style="margin: 0;">#{{ $serviceOrder->id }} &mdash; {{ $serviceOrder->subject }}</h1>
        <x-portal::status-badge :status="$serviceOrder->workshop_status" />
    </div>

    <div class="card">
        <div class="grid">
            <div>
                <div class="stat-label">@lang('portal::app.service-orders.view.vehicle')</div>
                @if ($serviceOrder->vehicle)
                    @if ($serviceOrder->vehicle) {{ $serviceOrder->vehicle->marca }} {{ $serviceOrder->vehicle->modelo }} ({{ $serviceOrder->vehicle->placa }}) @else Serviço sem veículo @endif
                @else
                    -
                @endif
            </div>
            <div>
                <div class="stat-label">@lang('portal::app.service-orders.view.mechanic')</div>
                {{ $serviceOrder->mechanic->nome ?? '-' }}
            </div>
            <div>
                <div class="stat-label">@lang('portal::app.service-orders.view.data-previsao')</div>
                {{ $serviceOrder->previsaoTexto() ?? '-' }}
            </div>
            <div>
                <div class="stat-label">@lang('portal::app.service-orders.view.total')</div>
                <strong>{{ core()->formatBasePrice($serviceOrder->grand_total) }}</strong>
            </div>
        </div>

        @if ($serviceOrder->description)
            <p style="margin-top: 16px; color: var(--muted);">{{ $serviceOrder->description }}</p>
        @endif
    </div>

    <div class="card">
        <h3>@lang('portal::app.service-orders.view.items')</h3>

        @if ($serviceOrder->items->isEmpty())
            <p class="empty-state">@lang('portal::app.service-orders.view.no-items')</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>@lang('portal::app.service-orders.view.item-name')</th>
                        <th>@lang('portal::app.service-orders.view.item-type')</th>
                        <th>@lang('portal::app.service-orders.view.item-total')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($serviceOrder->items as $item)
                        <tr>
                            <td>{{ $item->name }}</td>
                            <td>{{ $item->tipo }}</td>
                            <td>{{ core()->formatBasePrice($item->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($serviceOrder->workshop_status === \Webkul\ServiceOrder\Models\ServiceOrder::STATUS_ABERTA)
        <div class="card">
            <h3>@lang('portal::app.service-orders.view.decision-title')</h3>
            <p style="color: var(--muted); font-size: 14px;">@lang('portal::app.service-orders.view.decision-info')</p>

            <div style="display: flex; gap: 12px; margin-top: 16px;">
                <form class="inline" method="POST" action="{{ route('portal.service_orders.approve', $serviceOrder->id) }}">
                    @csrf
                    <button type="submit" class="btn">@lang('portal::app.service-orders.view.approve')</button>
                </form>

                <form class="inline" method="POST" action="{{ route('portal.service_orders.reject', $serviceOrder->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-danger">@lang('portal::app.service-orders.view.reject')</button>
                </form>
            </div>
        </div>
    @endif
@endsection
