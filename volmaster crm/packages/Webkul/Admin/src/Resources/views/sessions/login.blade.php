<x-admin::layouts.anonymous>
    <!-- Page Title -->
    <x-slot:title>
        @lang('admin::app.users.login.title')
    </x-slot>

    <div class="vm-login relative flex min-h-[100vh] items-center justify-center overflow-hidden px-4 py-10">
        <!-- padrão de hexágonos, na linha da esfera de chaves do logotipo -->
        <div class="vm-login-hex pointer-events-none absolute inset-0"></div>

        <div class="relative flex w-full max-w-[920px] overflow-hidden rounded-2xl bg-white shadow-[0_30px_80px_-20px_rgba(0,0,0,.6)] dark:bg-gray-900 max-md:max-w-[420px] max-md:flex-col">
            <!-- Painel da marca -->
            <div class="relative flex flex-1 flex-col justify-between gap-12 bg-gradient-to-br from-[#0B2447] to-[#071A33] p-10 text-white max-md:gap-6 max-md:p-7">
                <img
                    class="w-[210px] max-md:w-[160px]"
                    src="{{ vite()->asset('images/dark-logo.svg') }}"
                    alt="{{ config('app.name') }}"
                />

                <div class="flex flex-col gap-3 max-md:hidden">
                    <p class="font-display text-[30px] !leading-[1.15] font-bold tracking-wide">
                        Sua oficina sob controle,<br>
                        <span class="text-brandColor">do orçamento à entrega.</span>
                    </p>

                    <p class="max-w-[320px] !text-[15px] !leading-[1.5] text-[#9FB4D0]">
                        Ordens de serviço, diagnóstico, estoque e comissões em um só lugar.
                    </p>
                </div>

                <div class="font-display text-xs tracking-[.2em] text-[#5F7C9E] max-md:hidden">
                    VOLMASTER TECH
                </div>
            </div>

            <!-- Formulário -->
            <div class="flex w-[400px] max-w-full flex-col justify-center p-10 max-md:w-full max-md:p-7">
                {!! view_render_event('admin.sessions.login.form_controls.before') !!}

                <x-admin::form :action="route('admin.session.store')">
                    <p class="font-display mb-1 text-[26px] font-bold !leading-[1.2] text-gray-800 dark:text-white">
                        @lang('admin::app.users.login.title')
                    </p>

                    <p class="mb-6 !text-[14px] text-gray-500">
                        Acesse o painel da sua oficina.
                    </p>

                    <!-- Email -->
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('admin::app.users.login.email')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="email"
                            class="w-full"
                            id="email"
                            name="email"
                            rules="required|email"
                            :label="trans('admin::app.users.login.email')"
                            :placeholder="trans('admin::app.users.login.email')"
                        />

                        <x-admin::form.control-group.error control-name="email" />
                    </x-admin::form.control-group>

                    <!-- Password -->
                    <x-admin::form.control-group class="relative w-full">
                        <x-admin::form.control-group.label class="required">
                            @lang('admin::app.users.login.password')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="password"
                            class="w-full ltr:pr-10 rtl:pl-10"
                            id="password"
                            name="password"
                            rules="required|min:6"
                            :label="trans('admin::app.users.login.password')"
                            :placeholder="trans('admin::app.users.login.password')"
                        />

                        <span
                            class="icon-eye-hide absolute top-11 -translate-y-2/4 cursor-pointer text-2xl ltr:right-3 rtl:left-3"
                            onclick="switchVisibility()"
                            id="visibilityIcon"
                            role="presentation"
                            tabindex="0"
                        >
                        </span>

                        <x-admin::form.control-group.error control-name="password" />
                    </x-admin::form.control-group>

                    <div class="mt-2 flex items-center justify-between gap-4">
                        <!-- Forgot Password Link -->
                        <a
                            class="cursor-pointer text-xs font-semibold leading-6 text-brandColor hover:underline"
                            href="{{ route('admin.forgot_password.create') }}"
                        >
                            @lang('admin::app.users.login.forget-password-link')
                        </a>

                        <!-- Submit Button -->
                        <button
                            class="primary-button !px-6 !py-2.5"
                            aria-label="{{ trans('admin::app.users.login.submit-btn')}}"
                        >
                            @lang('admin::app.users.login.submit-btn')
                        </button>
                    </div>
                </x-admin::form>

                {!! view_render_event('admin.sessions.login.form_controls.after') !!}
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function switchVisibility() {
                let passwordField = document.getElementById("password");
                let visibilityIcon = document.getElementById("visibilityIcon");

                passwordField.type = passwordField.type === "password" ? "text" : "password";
                visibilityIcon.classList.toggle("icon-eye");
            }
        </script>
    @endpush
</x-admin::layouts.anonymous>
