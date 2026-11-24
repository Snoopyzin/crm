@extends('portal::layouts.master')

@section('title', trans('portal::app.service-requests.index.title'))

@section('content')
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <h1 style="margin: 0;">@lang('portal::app.service-requests.index.title')</h1>
        <a class="btn" href="{{ route('portal.service_requests.create') }}">@lang('portal::app.service-requests.index.new')</a>
    </div>

    <div class="card">
        @if ($serviceRequests->isEmpty())
            <p class="empty-state">@lang('portal::app.service-requests.index.empty')</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>@lang('portal::app.service-requests.index.descricao')</th>
                        <th>@lang('portal::app.service-requests.index.data-preferencia')</th>
                        <th>@lang('portal::app.service-requests.index.status')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($serviceRequests as $serviceRequest)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::limit($serviceRequest->descricao, 60) }}</td>
                            <td>{{ $serviceRequest->data_preferencia ? $serviceRequest->data_preferencia->format('d/m/Y') : '-' }}</td>
                            <td><x-portal::status-badge :status="$serviceRequest->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
