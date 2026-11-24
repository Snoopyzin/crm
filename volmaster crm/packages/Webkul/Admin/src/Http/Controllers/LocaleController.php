<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Webkul\Core\Contracts\CoreConfig as CoreConfigContract;

class LocaleController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(protected CoreConfigContract $coreConfigModel) {}

    /**
     * Switch the admin panel's display language.
     */
    public function switch(string $code): RedirectResponse
    {
        if (! array_key_exists($code, config('app.available_locales'))) {
            abort(404);
        }

        $this->coreConfigModel->updateOrCreate(
            ['code' => 'general.general.locale_settings.locale'],
            ['value' => $code]
        );

        return redirect()->back();
    }
}
