@extends('portal::layouts.master')

@section('title', trans('portal::app.service-orders.index.title'))

@section('content')
    <h1>@lang('portal::app.service-orders.index.title')</h1>

    <div class="card">
        @if ($serviceOrders->isEmpty())
            <p class="empty-state">@lang('portal::app.dashboard.no-service-orders')</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>@lang('portal::app.service-orders.index.subject')</th>
                        <th>@lang('portal::app.service-orders.index.total')</th>
                        <th>@lang('portal::app.service-orders.index.status')</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($serviceOrders as $serviceOrder)
                        <tr>
                            <td>#{{ $serviceOrder->id }}</td>
                            <td>{{ $serviceOrder->subject }}</td>
                            <td>{{ core()->formatBasePrice($serviceOrder->grand_total) }}</td>
                            <td><x-portal::status-badge :status="$serviceOrder->workshop_status" /></td>
                            <td><a href="{{ route('portal.service_orders.view', $serviceOrder->id) }}">@lang('portal::app.dashboard.view')</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{ $serviceOrders->links() }}
        @endif
    </div>
@endsection
