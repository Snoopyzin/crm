@extends('portal::layouts.master')

@section('title', trans('portal::app.sessions.login.title'))

@section('content')
    <div class="card" style="max-width: 400px; margin: 40px auto;">
        <h1 style="font-size: 20px;">@lang('portal::app.sessions.login.title')</h1>

        <form method="POST" action="{{ route('portal.session.store') }}">
            @csrf

            <label for="email">@lang('portal::app.sessions.email')</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>

            <label for="password">@lang('portal::app.sessions.password')</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn" style="width: 100%;">@lang('portal::app.sessions.login.submit')</button>
        </form>

        <p style="margin-top: 16px; font-size: 13px;">
            <a href="{{ route('portal.forgot_password.create') }}">@lang('portal::app.sessions.login.forgot-password')</a>
        </p>
    </div>
@endsection
