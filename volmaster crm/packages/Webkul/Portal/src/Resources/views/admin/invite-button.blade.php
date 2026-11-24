@php
    $clientUser = \Webkul\Portal\Models\ClientUser::where('person_id', $person->id)->first();
@endphp

<div class="flex items-center gap-2">
    @if ($clientUser?->status === \Webkul\Portal\Models\ClientUser::STATUS_ATIVO)
        <span class="rounded-md bg-green-100 px-2.5 py-1.5 text-xs font-semibold text-green-600 dark:bg-green-900 dark:text-green-300">
            @lang('portal::app.admin.invite-button.active')
        </span>
    @endif

    <form method="POST" action="{{ route('admin.portal.invite', $person->id) }}">
        @csrf

        <button type="submit" class="secondary-button">
            @if ($clientUser)
                @lang('portal::app.admin.invite-button.resend')
            @else
                @lang('portal::app.admin.invite-button.invite')
            @endif
        </button>
    </form>
</div>
