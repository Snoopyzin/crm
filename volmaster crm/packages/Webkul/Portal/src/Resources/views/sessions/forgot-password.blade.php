@extends('portal::layouts.master')

@section('title', trans('portal::app.sessions.forgot-password.title'))

@section('content')
    <div class="card" style="max-width: 400px; margin: 40px auto;">
        <h1 style="font-size: 20px;">@lang('portal::app.sessions.forgot-password.title')</h1>

        <p style="color: var(--muted); font-size: 14px;">@lang('portal::app.sessions.forgot-password.info')</p>

        <form method="POST" action="{{ route('portal.forgot_password.store') }}">
            @csrf

            <label for="email">@lang('portal::app.sessions.email')</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>

            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn" style="width: 100%;">@lang('portal::app.sessions.forgot-password.submit')</button>
        </form>

        <p style="margin-top: 16px; font-size: 13px;">
            <a href="{{ route('portal.session.create') }}">@lang('portal::app.sessions.back-to-login')</a>
        </p>
    </div>
@endsection
