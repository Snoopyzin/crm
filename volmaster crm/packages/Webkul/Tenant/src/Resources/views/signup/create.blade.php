<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Criar conta — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px 0; }
        .card { background: #fff; border-radius: 12px; padding: 40px; width: 100%; max-width: 420px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 22px; margin: 0 0 4px; }
        p.subtitle { color: #6b7280; margin: 0 0 24px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
        .subdomain-row { display: flex; align-items: center; gap: 6px; }
        .subdomain-row span { color: #6b7280; font-size: 14px; white-space: nowrap; }
        button { margin-top: 24px; width: 100%; background: #2563eb; color: #fff; border: none; padding: 12px; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; }
        .errors { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
        .errors ul { margin: 0; padding-left: 18px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Criar conta na Volmaster Tech</h1>
        <p class="subtitle">Sua oficina, com seu próprio CRM.</p>

        @if ($errors->any())
            <div class="errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('tenant.signup.store') }}">
            @csrf

            <label for="name">Nome da oficina</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required>

            <label for="subdomain">Endereço da sua conta</label>
            <div class="subdomain-row">
                <input type="text" id="subdomain" name="subdomain" value="{{ old('subdomain') }}" placeholder="oficina-do-joao" required>
                <span>.{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}</span>
            </div>

            <label for="admin_name">Seu nome</label>
            <input type="text" id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required>

            <label for="admin_email">Seu e-mail</label>
            <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required>

            <label for="admin_password">Senha</label>
            <input type="password" id="admin_password" name="admin_password" minlength="8" required>

            <label for="admin_password_confirmation">Confirmar senha</label>
            <input type="password" id="admin_password_confirmation" name="admin_password_confirmation" minlength="8" required>

            <button type="submit">Criar minha conta</button>
        </form>
    </div>
</body>
</html>
