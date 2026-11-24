@extends('portal::layouts.master')

@section('title', trans('portal::app.service-requests.create.title'))

@section('content')
    <h1>@lang('portal::app.service-requests.create.title')</h1>

    <div class="card" style="max-width: 520px;">
        <form method="POST" action="{{ route('portal.service_requests.store') }}">
            @csrf

            <label for="vehicle_id">@lang('portal::app.service-requests.create.vehicle')</label>
            <select id="vehicle_id" name="vehicle_id">
                <option value="">@lang('portal::app.service-requests.create.vehicle-other')</option>
                @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>
                        {{ $vehicle->placa }} &mdash; {{ $vehicle->marca }} {{ $vehicle->modelo }}
                    </option>
                @endforeach
            </select>

            <label for="veiculo_descricao_livre">@lang('portal::app.service-requests.create.vehicle-free-text')</label>
            <input type="text" id="veiculo_descricao_livre" name="veiculo_descricao_livre" value="{{ old('veiculo_descricao_livre') }}" placeholder="@lang('portal::app.service-requests.create.vehicle-free-text-hint')">
            @error('veiculo_descricao_livre')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <label for="descricao">@lang('portal::app.service-requests.create.descricao')</label>
            <textarea id="descricao" name="descricao" rows="4" required>{{ old('descricao') }}</textarea>
            @error('descricao')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <label for="data_preferencia">@lang('portal::app.service-requests.create.data-preferencia')</label>
            <input type="date" id="data_preferencia" name="data_preferencia" value="{{ old('data_preferencia') }}">

            <button type="submit" class="btn">@lang('portal::app.service-requests.create.submit')</button>
        </form>
    </div>
@endsection
