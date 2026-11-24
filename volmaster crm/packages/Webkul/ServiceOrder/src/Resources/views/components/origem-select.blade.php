{{--
    "Por qual meio você chegou à nossa empresa?" select, used via
    @include('service_order::components.origem-select', ['value' => ..., 'required' => true|false])
    only in Cadastro (the question is asked once, when the client is registered;
    a new OS for the same client inherits the origin already on file).
--}}
<select
    name="origem_cliente"
    @if ($required ?? true) required @endif
    class="w-full rounded border border-gray-300 px-2.5 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
>
    <option value="">Selecione...</option>
    @foreach (\Webkul\ServiceOrder\Models\ServiceOrder::ORIGENS as $origem)
        <option value="{{ $origem }}" @selected(($value ?? null) === $origem)>{{ $origem }}</option>
    @endforeach
</select>
