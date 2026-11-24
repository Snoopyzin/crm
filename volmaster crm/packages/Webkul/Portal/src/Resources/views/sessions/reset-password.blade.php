@extends('portal::layouts.master')

@section('title', trans('portal::app.sessions.reset-password.title'))

@section('content')
    <div class="card" style="max-width: 400px; margin: 40px auto;">
        <h1 style="font-size: 20px;">@lang('portal::app.sessions.reset-password.title')</h1>

        <form method="POST" action="{{ route('portal.reset_password.store') }}">
            @csrf

            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">@lang('portal::app.sessions.email')</label>
            <input type="email" id="email" name="email" value="{{ old('email', $email) }}" required autofocus>

            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <label for="password">@lang('portal::app.sessions.reset-password.new-password')</label>
            <input type="password" id="password" name="password" required>

            <label for="password_confirmation">@lang('portal::app.sessions.reset-password.confirm-password')</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required>

            <button type="submit" class="btn" style="width: 100%;">@lang('portal::app.sessions.reset-password.submit')</button>
        </form>
    </div>
@endsection
