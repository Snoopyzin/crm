@extends('portal::layouts.master')

@section('title', $vehicle->placa)

@section('content')
    <h1>{{ $vehicle->marca }} {{ $vehicle->modelo }} &mdash; {{ $vehicle->placa }}</h1>

    <div class="card">
        <div class="grid">
            <div>
                <div class="stat-label">@lang('portal::app.vehicles.index.ano')</div>
                {{ $vehicle->ano ?? '-' }}
            </div>
            <div>
                <div class="stat-label">@lang('portal::app.vehicles.view.cor')</div>
                {{ $vehicle->cor ?? '-' }}
            </div>
            <div>
                <div class="stat-label">@lang('portal::app.vehicles.view.km')</div>
                {{ number_format($vehicle->km, 0, ',', '.') }}
            </div>
            <div>
                <div class="stat-label">@lang('portal::app.vehicles.index.status')</div>
                <x-portal::status-badge :status="$vehicle->status" />
            </div>
        </div>
    </div>

    <div class="card">
        <h3>@lang('portal::app.vehicles.view.history')</h3>

        @if ($serviceOrders->isEmpty())
            <p class="empty-state">@lang('portal::app.dashboard.no-service-orders')</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>@lang('portal::app.service-orders.index.subject')</th>
                        <th>@lang('portal::app.service-orders.index.status')</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($serviceOrders as $serviceOrder)
                        <tr>
                            <td>#{{ $serviceOrder->id }}</td>
                            <td>{{ $serviceOrder->subject }}</td>
                            <td><x-portal::status-badge :status="$serviceOrder->workshop_status" /></td>
                            <td><a href="{{ route('portal.service_orders.view', $serviceOrder->id) }}">@lang('portal::app.dashboard.view')</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
