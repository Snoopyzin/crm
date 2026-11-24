<?php

// A página de ajuda é a do Volmaster Tech (em português, com o contato da
// equipe); o teste antigo procurava os textos em inglês do Krayin original.
it('shows the help page to an authenticated admin', function () {
    $admin = getDefaultAdmin();

    test()->actingAs($admin)
        ->get(route('admin.help.index'))
        ->assertOk()
        ->assertSee(trans('admin::app.help.index.title'))
        ->assertSee(trans('admin::app.help.index.services.title'))
        ->assertSee(trans('admin::app.help.index.still-need-help-title'))
        ->assertSee(trans('admin::app.help.index.contact-us'))
        ->assertSee(config('support.contact_url'), false);
});
