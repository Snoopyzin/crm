@component('admin::emails.layout')
    <div style="margin-bottom: 34px;">
        <p style="font-size: 16px;color: #5E5E5E;line-height: 24px;">
            @lang('portal::app.emails.invite.dear', ['name' => $personName]), 👋
        </p>

        <p style="font-size: 16px;color: #5E5E5E;line-height: 24px;">
            @lang('portal::app.emails.invite.info', ['app_name' => config('app.name')])
        </p>

        <p style="text-align: center;padding: 20px 0;">
            <a
                href="{{ $acceptUrl }}"
                style="padding: 10px 20px;background: #0D8DD5;color: #ffffff;text-transform: uppercase;text-decoration: none; font-size: 16px"
            >
                @lang('portal::app.emails.invite.cta')
            </a>
        </p>

        <p style="font-size: 16px;color: #5E5E5E;line-height: 24px;">
            @lang('portal::app.emails.invite.thanks')
        </p>
    </div>
@endcomponent
