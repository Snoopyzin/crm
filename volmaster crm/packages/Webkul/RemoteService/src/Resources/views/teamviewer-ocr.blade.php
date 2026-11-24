@php
    $idField = $idField ?? 'teamviewer_id';
    $passwordField = $passwordField ?? 'teamviewer_password';
    $zoneId = $idField.'_ocr_zone';
    $statusId = $idField.'_ocr_status';
    $fileInputId = $idField.'_ocr_file';
@endphp

<div
    id="{{ $zoneId }}"
    contenteditable="true"
    class="mb-1 cursor-text rounded-md border border-dashed border-gray-300 bg-gray-50 p-3 text-center text-xs text-gray-500 outline-none transition-all hover:border-gray-400 focus:border-blue-400 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400"
>
    <span id="{{ $statusId }}">
        Clique aqui e cole (Ctrl+V) a captura de tela do TeamViewer, ou
    </span>

    <label
        for="{{ $fileInputId }}"
        class="cursor-pointer font-semibold text-blue-600 underline dark:text-blue-400"
    >
        envie a imagem
    </label>

    <input
        type="file"
        id="{{ $fileInputId }}"
        accept="image/*"
        class="hidden"
    />
</div>

@pushOnce('scripts')
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
@endPushOnce

<script>
(function () {
    var zoneId = '{{ $zoneId }}';
    var statusId = '{{ $statusId }}';
    var fileInputId = '{{ $fileInputId }}';
    var idFieldId = '{{ $idField }}';
    var passwordFieldId = '{{ $passwordField }}';

    // Everything here looks elements up fresh by id, every time, instead of
    // caching them once at load: the fields around this box are rendered by
    // Vue <v-field> components which can re-render (and swap out) their DOM
    // nodes after the page loads, silently detaching any listener that was
    // attached directly to the original node. Listening on `document` and
    // resolving ids at event time survives that.

    function setFieldValue(field, value) {
        if (! field || ! value) {
            return;
        }

        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
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
    // much worse than a clean screenshot -- convert to grayscale and boost
    // contrast on a canvas first, which noticeably helps Tesseract in that
    // case. Downscale very large photos so it stays fast.
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
                // Canvas may be tainted for cross-origin images -- fall back
                // to the untouched (but still resized) canvas.
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
                        status.textContent = 'ID e senha preenchidos automaticamente — confira antes de salvar.';
                    } else if (parsed.id || parsed.password) {
                        status.textContent = 'Preenchi só ' + (parsed.id ? 'o ID' : 'a senha') + ', não achei ' + (parsed.id ? 'a senha' : 'o ID') + '. Texto lido: "' + rawText.replace(/\s+/g, ' ') + '"';
                    } else {
                        status.textContent = rawText
                            ? 'Não identifiquei o ID/senha. Texto lido da imagem: "' + rawText.replace(/\s+/g, ' ') + '"'
                            : 'Não consegui ler nenhum texto dessa imagem, preencha manualmente.';
                    }
                })
                .catch(function (error) {
                    status.textContent = 'Erro ao ler a imagem: ' + (error && error.message ? error.message : String(error));
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
})();
</script>
