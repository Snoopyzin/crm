{{--
    Money inputs as "R$ 10.000,00": any <input data-money> is formatted on
    load and while typing (bank-app style: digits fill from the cents). The
    submitted "R$ ..." string is turned back into a decimal on the server by
    App\Http\Middleware\NormalizeMoneyInput. Listeners live on document and
    a MutationObserver catches inputs rendered later (the admin is a Vue app
    that re-renders the page on mount).
--}}
<script>
    (function () {
        if (window.vmMoney) {
            return;
        }

        var formatter = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        function format(number) {
            return 'R$ ' + formatter.format(Math.round(number * 100) / 100);
        }

        // "R$ 1.234,56" / "1234.56" / "1234,5" -> 1234.56 (NaN if empty)
        function parse(value) {
            value = String(value == null ? '' : value).trim();

            if (value === '') {
                return NaN;
            }

            if (value.indexOf('R$') !== -1 || value.indexOf(',') !== -1) {
                value = value.replace(/R\$|\s| /g, '').replace(/\./g, '').replace(',', '.');
            }

            return parseFloat(value);
        }

        function emit(input) {
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Programmatic set (e.g. price pre-filled from the parts catalog).
        function set(input, number) {
            input.value = isNaN(number) || number === '' || number === null ? '' : format(Number(number));
            input.dataset.moneyReady = '1';
            emit(input);
        }

        function initialize(input) {
            if (input.dataset.moneyReady) {
                return;
            }

            input.dataset.moneyReady = '1';
            input.setAttribute('inputmode', 'numeric');

            if (input.type === 'number') {
                input.type = 'text';
            }

            var number = parse(input.value);

            if (! isNaN(number) && input.value.indexOf('R$') === -1) {
                input.value = format(number);
                emit(input);
            }
        }

        function scan(root) {
            (root.querySelectorAll ? root.querySelectorAll('input[data-money]') : []).forEach(initialize);
        }

        document.addEventListener('input', function (event) {
            var input = event.target;

            if (! input || ! input.matches || ! input.matches('input[data-money]') || event.vmMoney) {
                return;
            }

            var digits = input.value.replace(/\D/g, '');
            var formatted = digits === '' ? '' : format(parseInt(digits, 10) / 100);

            if (formatted !== input.value) {
                input.value = formatted;

                var again = new Event('input', { bubbles: true });
                again.vmMoney = true;
                input.dispatchEvent(again);
            }
        }, true);

        window.vmMoney = { parse: parse, format: format, set: set, scan: scan };

        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        if (node.matches && node.matches('input[data-money]')) {
                            initialize(node);
                        }

                        scan(node);
                    }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });

        document.addEventListener('DOMContentLoaded', function () { scan(document); });
        window.addEventListener('load', function () { setTimeout(function () { scan(document); }, 0); });
    })();

    /*
     * Phones as "+55 (62) 99999-9999": the Cadastro WhatsApp field
     * (data-phone) and the client's phone list (contact_numbers[n][value]).
     * The server stores/sends them via Webkul\WhatsApp\Support\BrazilianPhone.
     */
    (function () {
        var SELECTOR = 'input[data-phone], input[name^="contact_numbers["][name$="[value]"]';

        function national(value, typing) {
            var raw = String(value || '').trim();
            var digits = raw.replace(/\D/g, '');

            if (raw.indexOf('+55') === 0 || (! typing && digits.length >= 12 && digits.indexOf('55') === 0)) {
                digits = digits.slice(2);
            }

            return digits.replace(/^0+/, '').slice(0, 11);
        }

        function format(digits) {
            if (! digits) {
                return '';
            }

            var out = '+55 (' + digits.slice(0, 2);

            if (digits.length > 2) {
                var rest = digits.slice(2);
                var split = digits.length === 11 ? 5 : 4;

                out += ') ' + rest.slice(0, split) + (rest.length > split ? '-' + rest.slice(split) : '');
            }

            return out;
        }

        function apply(input, typing, deleting) {
            var digits = national(input.value, typing);

            // Backspace over ") " or "-" would otherwise be re-added forever.
            if (deleting && digits === input.dataset.phoneDigits) {
                digits = digits.slice(0, -1);
            }

            input.dataset.phoneDigits = digits;

            var formatted = format(digits);

            if (formatted !== input.value) {
                input.value = formatted;

                var again = new Event('input', { bubbles: true });
                again.vmPhone = true;
                input.dispatchEvent(again);
            }
        }

        document.addEventListener('input', function (event) {
            var input = event.target;

            if (! input || ! input.matches || ! input.matches(SELECTOR) || event.vmPhone) {
                return;
            }

            apply(input, true, (event.inputType || '').indexOf('delete') === 0);
        }, true);

        function scan(root) {
            (root.querySelectorAll ? root.querySelectorAll(SELECTOR) : []).forEach(function (input) {
                if (input.dataset.phoneReady) {
                    return;
                }

                input.dataset.phoneReady = '1';
                input.setAttribute('inputmode', 'tel');

                if (input.value) {
                    apply(input, false, false);
                }
            });
        }

        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        scan(node.parentNode || node);
                    }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });

        // Krayin's "phone" rule only accepts digits -- allow the formatted
        // version (and still require DDD + number).
        function relaxPhoneRule() {
            if (window.defineRule) {
                window.defineRule('phone', function (value) {
                    return ! value || (/^[+\d\s().-]+$/.test(value) && value.replace(/\D/g, '').length >= 10);
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function () { relaxPhoneRule(); scan(document); });
        window.addEventListener('load', function () { relaxPhoneRule(); setTimeout(function () { scan(document); }, 0); });
    })();
</script>
