@extends('portal::layouts.master')

@section('title', trans('portal::app.dashboard.title'))

@section('content')
    <h1>@lang('portal::app.dashboard.title')</h1>

    <div class="grid" style="margin-bottom: 24px;">
        <div class="card">
            <div class="stat">{{ $vehicleCount }}</div>
            <div class="stat-label">@lang('portal::app.dashboard.vehicle-count')</div>
        </div>

        <div class="card">
            <div class="stat">{{ $pendingApprovalCount }}</div>
            <div class="stat-label">@lang('portal::app.dashboard.pending-approval-count')</div>
        </div>
    </div>

    <div class="card">
        <h3>@lang('portal::app.dashboard.recent-service-orders')</h3>

        @if ($recentServiceOrders->isEmpty())
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
                    @foreach ($recentServiceOrders as $serviceOrder)
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
