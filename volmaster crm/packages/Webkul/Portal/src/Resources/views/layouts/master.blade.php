<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', trans('portal::app.layout.title')) - {{ config('app.name') }}</title>
    <style>
        :root {
            --blue: #2081e2;
            --blue-dark: #1b6cc2;
            --ink: #1b1f27;
            --muted: #5b6472;
            --bg: #f4f6f9;
            --card: #ffffff;
            --border: #e4e8ee;
            --success: #1c9a5b;
            --success-bg: #e6f7ee;
            --danger: #d64545;
            --danger-bg: #fbeaea;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--ink);
        }

        a { color: var(--blue); }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 32px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 18px;
            text-decoration: none;
            color: var(--ink);
        }

        .brand svg { flex-shrink: 0; }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 22px;
            font-size: 14px;
            font-weight: 500;
        }

        .nav-links a {
            color: var(--muted);
            text-decoration: none;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: var(--blue);
        }

        .nav-links form { margin: 0; }

        .nav-links button {
            background: none;
            border: none;
            color: var(--muted);
            font: inherit;
            font-weight: 500;
            cursor: pointer;
            padding: 0;
        }

        .nav-links button:hover { color: var(--danger); }

        main {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 24px 80px;
        }

        h1 {
            font-size: 24px;
            margin: 0 0 24px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .card h3 {
            margin: 0 0 8px;
            font-size: 15px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .stat {
            font-size: 30px;
            font-weight: 700;
            color: var(--blue);
        }

        .stat-label {
            font-size: 13px;
            color: var(--muted);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th, td {
            text-align: left;
            padding: 10px 8px;
            border-bottom: 1px solid var(--border);
        }

        th {
            color: var(--muted);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: var(--border);
            color: var(--muted);
        }

        .badge-success { background: var(--success-bg); color: var(--success); }
        .badge-danger { background: var(--danger-bg); color: var(--danger); }
        .badge-warning { background: #fdf3e0; color: #a8710a; }

        .btn {
            display: inline-block;
            background: var(--blue);
            color: #fff;
            text-decoration: none;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
        }

        .btn:hover { background: var(--blue-dark); }

        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #b83a3a; }

        .btn-outline {
            background: transparent;
            color: var(--blue);
            border: 1px solid var(--blue);
        }

        .btn-outline:hover { background: var(--blue); color: #fff; }

        form.inline { display: inline; }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: var(--card);
            color: var(--ink);
            margin-bottom: 16px;
        }

        .field-error {
            color: var(--danger);
            font-size: 13px;
            margin: -12px 0 16px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .alert-success { background: var(--success-bg); color: var(--success); }
        .alert-error { background: var(--danger-bg); color: var(--danger); }

        .empty-state {
            text-align: center;
            color: var(--muted);
            padding: 40px 0;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #eef1f5;
                --muted: #99a3b2;
                --bg: #10141c;
                --card: #171c26;
                --border: #262d3a;
                --success-bg: #113626;
                --danger-bg: #3a1c1c;
            }
        }
    </style>
    @stack('styles')
</head>
<body>
    @auth('client')
        <nav class="nav">
            <a class="brand" href="{{ route('portal.dashboard.index') }}">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2L22 12L12 22L2 12L12 2Z" fill="#2081e2"/>
                </svg>
                {{ config('app.name') }}
            </a>

            <div class="nav-links">
                <a href="{{ route('portal.dashboard.index') }}">@lang('portal::app.layout.nav.dashboard')</a>
                <a href="{{ route('portal.vehicles.index') }}">@lang('portal::app.layout.nav.vehicles')</a>
                <a href="{{ route('portal.service_orders.index') }}">@lang('portal::app.layout.nav.service-orders')</a>
                <a href="{{ route('portal.service_requests.index') }}">@lang('portal::app.layout.nav.service-requests')</a>

                <form method="POST" action="{{ route('portal.session.destroy') }}">
                    @csrf
                    <button type="submit">@lang('portal::app.layout.nav.logout')</button>
                </form>
            </div>
        </nav>
    @endauth

    <main>
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
