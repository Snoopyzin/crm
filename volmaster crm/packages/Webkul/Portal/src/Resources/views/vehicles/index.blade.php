@extends('portal::layouts.master')

@section('title', trans('portal::app.vehicles.index.title'))

@section('content')
    <h1>@lang('portal::app.vehicles.index.title')</h1>

    <div class="card">
        @if ($vehicles->isEmpty())
            <p class="empty-state">@lang('portal::app.vehicles.index.empty')</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>@lang('portal::app.vehicles.index.placa')</th>
                        <th>@lang('portal::app.vehicles.index.marca-modelo')</th>
                        <th>@lang('portal::app.vehicles.index.ano')</th>
                        <th>@lang('portal::app.vehicles.index.status')</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vehicles as $vehicle)
                        <tr>
                            <td>{{ $vehicle->placa }}</td>
                            <td>{{ $vehicle->marca }} {{ $vehicle->modelo }}</td>
                            <td>{{ $vehicle->ano }}</td>
                            <td><x-portal::status-badge :status="$vehicle->status" /></td>
                            <td><a href="{{ route('portal.vehicles.view', $vehicle->id) }}">@lang('portal::app.dashboard.view')</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
