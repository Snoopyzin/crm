<x-admin::layouts>
    <x-slot:title>
        @lang('workbay::app.create.title')
    </x-slot>

    <x-admin::form :action="route('admin.work_bays.store')" method="POST">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <x-admin::breadcrumbs name="work_bays.create" />

                    <div class="text-xl font-bold dark:text-white">
                        @lang('workbay::app.create.title')
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        @lang('workbay::app.create.save-btn')
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('workbay::app.form.general')
                </p>

                <div class="grid grid-cols-2 gap-x-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('workbay::app.form.name')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="name"
                            name="name"
                            rules="required"
                            :value="old('name')"
                            :label="trans('workbay::app.form.name')"
                        />

                        <x-admin::form.control-group.error control-name="name" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('workbay::app.form.capacity')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="capacity"
                            name="capacity"
                            :value="old('capacity', 1)"
                            :label="trans('workbay::app.form.capacity')"
                        />

                        <x-admin::form.control-group.error control-name="capacity" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('workbay::app.form.status')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            id="status"
                            name="status"
                            rules="required"
                            :value="old('status', 'Ativa')"
                            :label="trans('workbay::app.form.status')"
                        >
                            <option value="Ativa">{{ trans('workbay::app.form.status-options.ativa') }}</option>
                            <option value="Inativa">{{ trans('workbay::app.form.status-options.inativa') }}</option>
                        </x-admin::form.control-group.control>

                        <x-admin::form.control-group.error control-name="status" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
