<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Agendar programação — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px 0; }
        .card { background: #fff; border-radius: 12px; padding: 40px; width: 100%; max-width: 420px; box-shadow: 0 1px 3px rgba(0,0,0,.1); box-sizing: border-box; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        p.subtitle { color: #6b7280; margin: 0 0 24px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; box-sizing: border-box; font-family: inherit; }
        p.hint { color: #9ca3af; font-size: 12px; margin: 6px 0 0; }
        button { margin-top: 24px; width: 100%; background: #2563eb; color: #fff; border: none; padding: 12px; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; }
        .errors { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
        .errors ul { margin: 0; padding-left: 18px; }
        .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .row-2 label { margin-top: 0; }
        .paste-zone { margin-top: 16px; padding: 12px; border: 1px dashed #d1d5db; border-radius: 8px; background: #f9fafb; color: #6b7280; font-size: 12px; text-align: center; cursor: text; }
        .paste-zone:focus { outline: none; border-color: #93c5fd; }
        .paste-zone a { color: #2563eb; font-weight: 600; text-decoration: underline; cursor: pointer; }
        .tipos-box { max-height: 220px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; }
        .tipos-box .grupo-title { margin: 8px 0 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #9ca3af; }
        .tipos-box .grupo-title:first-child { margin-top: 0; }
        .tipos-box label { display: flex; align-items: center; gap: 8px; font-weight: 400; font-size: 14px; margin: 4px 0; text-transform: none; }
        .tipos-box input[type="checkbox"] { width: auto; }
        .tipos-box .nao-sei { margin-top: 10px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Agendar uma programação</h1>
        <p class="subtitle">Preencha os dados abaixo e entraremos em contato pelo WhatsApp.</p>

        @if ($errors->any())
            <div class="errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('remote_service.public.store') }}">
            @csrf

            <label for="nome">Seu nome</label>
            <input type="text" id="nome" name="nome" value="{{ old('nome') }}" required>

            <label for="nome_oficina">Nome da oficina</label>
            <input type="text" id="nome_oficina" name="nome_oficina" value="{{ old('nome_oficina') }}" required>

            <label for="whatsapp">Seu WhatsApp</label>
            <input type="text" id="whatsapp" name="whatsapp" value="{{ old('whatsapp') }}" placeholder="(62) 99999-9999" required>

            <label>O que você precisa? (pode marcar mais de uma)</label>
            <div class="tipos-box" id="tipos_box">
                @foreach ($tipos as $tipo)
                    <label>
                        <input type="checkbox" name="tipo_atendimento[]" value="{{ $tipo }}">
                        {{ $tipo }}
                    </label>
                @endforeach

                <label class="nao-sei">
                    <input type="checkbox" name="tipo_atendimento[]" value="{{ $naoSei }}">
                    {{ $naoSei }}
                </label>
            </div>
            <p class="hint">Não sabe qual é? Sem problema, marque a última opção que a gente te ajuda a identificar.</p>

            <label>Acesso TeamViewer</label>
            <div id="tv_ocr_zone" class="paste-zone" contenteditable="true">
                <span id="tv_ocr_status">Clique aqui e cole (Ctrl+V) a captura de tela do TeamViewer, ou</span>
                <label for="tv_ocr_file" style="display:inline; margin:0;"><a>envie a imagem</a></label>
                <input type="file" id="tv_ocr_file" accept="image/*" style="display:none;">
            </div>

            <div class="row-2">
                <div>
                    <label for="teamviewer_id">ID</label>
                    <input type="text" id="teamviewer_id" name="teamviewer_id" value="{{ old('teamviewer_id') }}" required>
                </div>

                <div>
                    <label for="teamviewer_password">Senha</label>
                    <input type="text" id="teamviewer_password" name="teamviewer_password" value="{{ old('teamviewer_password') }}" required>
                </div>
            </div>

            <button type="submit">Entrar na fila</button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script>
    (function () {
        var zoneId = 'tv_ocr_zone';
        var statusId = 'tv_ocr_status';
        var fileInputId = 'tv_ocr_file';
        var idFieldId = 'teamviewer_id';
        var passwordFieldId = 'teamviewer_password';

        function setFieldValue(field, value) {
            if (! field || ! value) {
                return;
            }

            field.value = value;
        }

        // ID: any 9-digit run (TeamViewer IDs are always 9 digits, however
        // spaced/grouped in the screenshot). Senha: the label word ("Senha")
        // is unreliable coming out of OCR, so instead look for a token that
        // *looks* like a TeamViewer password -- mixed letters and digits,
        // 5-15 chars -- anywhere in the recognized text.
        function parseText(text) {
            var id = null;
            var password = null;

            var digitMatch = text.match(/\d[\d\s.\-]{6,12}\d/);

            if (digitMatch) {
                var digitsOnly = digitMatch[0].replace(/[^0-9]/g, '');

                if (digitsOnly.length >= 8 && digitsOnly.length <= 10) {
                    id = digitsOnly.length === 9
                        ? digitsOnly.replace(/(\d{3})(\d{3})(\d{3})/, '$1 $2 $3')
                        : digitsOnly;
                }
            }

            var tokens = text.split(/\s+/);

            for (var i = 0; i < tokens.length; i++) {
                var token = tokens[i].replace(/[^a-z0-9]/gi, '');

                if (/^(?=.*[a-z])(?=.*[0-9])[a-z0-9]{5,15}$/i.test(token)) {
                    password = token;
                    break;
                }
            }

            return { id: id, password: password };
        }

        // Photos taken with a phone camera (glare, angle, low contrast) OCR
        // much worse than a clean screenshot -- convert to grayscale and
        // boost contrast on a canvas first, which noticeably helps Tesseract
        // in that case. Downscale very large photos so it stays fast.
        function preprocessImage(blob, callback) {
            var img = new Image();
            var url = URL.createObjectURL(blob);

            img.onload = function () {
                URL.revokeObjectURL(url);

                var maxDimension = 1600;
                var scale = Math.min(1, maxDimension / Math.max(img.width, img.height));

                var canvas = document.createElement('canvas');
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);

                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

                try {
                    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    var data = imageData.data;

                    for (var i = 0; i < data.length; i += 4) {
                        var gray = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
                        gray = (gray - 128) * 1.6 + 128;
                        gray = Math.max(0, Math.min(255, gray));
                        data[i] = data[i + 1] = data[i + 2] = gray;
                    }

                    ctx.putImageData(imageData, 0, 0);
                } catch (e) {
                    // Canvas may be tainted for cross-origin images -- fall
                    // back to the untouched (but still resized) canvas.
                }

                canvas.toBlob(function (processedBlob) {
                    callback(processedBlob || blob);
                }, 'image/png');
            };

            img.onerror = function () {
                URL.revokeObjectURL(url);

                callback(blob);
            };

            img.src = url;
        }

        function handleImage(blob) {
            var status = document.getElementById(statusId);

            if (! status) {
                return;
            }

            status.textContent = 'Lendo imagem...';

            if (typeof Tesseract === 'undefined') {
                status.textContent = 'Não foi possível carregar o leitor de imagem, preencha manualmente.';

                return;
            }

            preprocessImage(blob, function (processedBlob) {
                Tesseract.recognize(processedBlob, 'eng')
                    .then(function (result) {
                        var rawText = (result.data.text || '').trim();

                        var parsed = parseText(rawText);

                        setFieldValue(document.getElementById(idFieldId), parsed.id);
                        setFieldValue(document.getElementById(passwordFieldId), parsed.password);

                        if (parsed.id && parsed.password) {
                            status.textContent = 'ID e senha preenchidos automaticamente — confira antes de enviar.';
                        } else if (parsed.id || parsed.password) {
                            status.textContent = 'Preenchi só ' + (parsed.id ? 'o ID' : 'a senha') + '. Confira e complete o resto manualmente.';
                        } else {
                            status.textContent = 'Não consegui ler essa imagem automaticamente. Preencha o ID e a senha manualmente.';
                        }
                    })
                    .catch(function () {
                        status.textContent = 'Não consegui ler essa imagem. Preencha o ID e a senha manualmente.';
                    });
            });
        }

        document.addEventListener('paste', function (event) {
            var zone = document.getElementById(zoneId);
            var status = document.getElementById(statusId);

            if (! zone || document.activeElement !== zone) {
                return;
            }

            event.preventDefault();

            var clipboardData = event.clipboardData || window.clipboardData;
            var items = clipboardData ? clipboardData.items : [];

            for (var i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    handleImage(items[i].getAsFile());

                    return;
                }
            }

            if (status) {
                status.textContent = 'Nenhuma imagem encontrada, copie a captura de tela do TeamViewer e cole aqui.';
            }
        });

        document.addEventListener('change', function (event) {
            if (! event.target || event.target.id !== fileInputId) {
                return;
            }

            if (event.target.files && event.target.files[0]) {
                handleImage(event.target.files[0]);
            }
        });

        document.addEventListener('click', function (event) {
            if (event.target && event.target.id === fileInputId) {
                event.stopPropagation();
            }
        });

        document.querySelector('form').addEventListener('submit', function (event) {
            var anyChecked = document.querySelector('input[name="tipo_atendimento[]"]:checked');

            if (! anyChecked) {
                event.preventDefault();

                alert('Marque pelo menos uma opção de programação (ou "' + '{{ addslashes($naoSei) }}' + '").');
            }
        });
    })();
    </script>
</body>
</html>
