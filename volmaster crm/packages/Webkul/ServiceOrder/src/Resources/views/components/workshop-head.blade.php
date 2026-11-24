@if (auth()->guard('user')->check() && \Webkul\ServiceOrder\Services\StageGate::active())
    {{-- App instalável (PWA) para receber as notificações da oficina no celular. --}}
    <link rel="manifest" href="{{ route('workshop.pwa.manifest', [], false) }}">
    <link rel="apple-touch-icon" href="/workshop-icone-192.png">
    <meta name="theme-color" content="#0E90D9">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
@endif
