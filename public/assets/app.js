(function () {
    var baseMeta = document.querySelector('meta[name="app-base"]');
    var csrfMeta = document.querySelector('meta[name="csrf"]');
    var base = baseMeta ? baseMeta.content : '';
    var csrf = csrfMeta ? csrfMeta.content : '';

    document.addEventListener('input', function (ev) {
        var el = ev.target;
        if (el && el.hasAttribute && el.hasAttribute('data-party') && ev.isTrusted) {
            el.removeAttribute('data-filled');
        }
    });

    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || !form.getAttribute) return;
        var msg = form.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            ev.preventDefault();
            return;
        }
        if (form.id === 'settingsForm' && form.getAttribute('data-env') !== 'production') {
            var picked = form.querySelector('input[name="environment"]:checked');
            var intent = ev.submitter && ev.submitter.value;
            if (picked && picked.value === 'production' && intent !== 'test') {
                if (!window.confirm('Save and use production? Live NRS credentials will be used for validate and sign.')) {
                    ev.preventDefault();
                    return;
                }
            }
        }
        if (form.hasAttribute('data-once')) {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
        }
    }, true);

    document.addEventListener('click', function (ev) {
        var secretBtn = ev.target.closest('[data-toggle-secret]');
        if (secretBtn) {
            var input = document.getElementById(secretBtn.getAttribute('data-toggle-secret'));
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
            secretBtn.textContent = input.type === 'password' ? 'Show' : 'Hide';
        }
        if (ev.target.id === 'addLine') {
            var tpl = document.getElementById('lineTemplate');
            var host = document.getElementById('lines');
            var form = document.getElementById('invoiceForm');
            if (!tpl || !host || !form) return;
            var node = tpl.content.firstElementChild.cloneNode(true);
            var isic = node.querySelector('[name="line_isic[]"]');
            var cat = node.querySelector('[name="line_cat[]"]');
            if (isic) isic.value = form.getAttribute('data-default-isic') || '';
            if (cat) cat.value = form.getAttribute('data-default-cat') || '';
            host.appendChild(node);
            recalc();
        }
        if (ev.target.hasAttribute('data-remove-line')) {
            var line = ev.target.closest('.line');
            var host = document.getElementById('lines');
            if (!line || !host) return;
            if (host.querySelectorAll('.line').length < 2) {
                line.querySelectorAll('input').forEach(function (input) {
                    if (input.classList.contains('qty')) input.value = '1';
                    else input.value = '';
                });
            } else {
                line.remove();
            }
            recalc();
        }
        var lookup = ev.target.closest('[data-lookup]');
        if (lookup) lookupTin(lookup.getAttribute('data-lookup'));
    });

    var pick = document.getElementById('customerPick');
    if (pick) {
        pick.addEventListener('change', function () {
            var idField = document.getElementById('customerId');
            if (idField) idField.value = pick.value || '0';
            var opt = pick.options[pick.selectedIndex];
            if (!opt || !opt.getAttribute('data-customer')) return;
            var data = {};
            try { data = JSON.parse(opt.getAttribute('data-customer')); } catch (e) { return; }
            fillParty(document.getElementById('buyerParty'), {
                name: data.name || '',
                tin: data.tin || '',
                email: data.email || '',
                street: data.street || '',
                city: data.city || '',
                postal: data.postal || '',
                state: data.state || '',
                lga: data.lga || ''
            }, true);
        });
    }

    function fillParty(root, party, overwrite) {
        if (!root) return;
        Object.keys(party).forEach(function (key) {
            var el = root.querySelector('[data-party="' + key + '"]');
            if (!el || !party[key]) return;
            if (!overwrite && el.value && el.getAttribute('data-filled') !== '1') return;
            el.value = party[key];
            el.setAttribute('data-filled', '1');
        });
    }

    function lookupTin(selector) {
        var root = document.querySelector(selector);
        if (!root) return;
        var tinEl = root.querySelector('[data-party="tin"]');
        var note = root.querySelector('.lookup-note');
        var tin = tinEl ? tinEl.value.trim() : '';
        if (!tin) {
            if (note) note.textContent = 'Enter a TIN first.';
            return;
        }
        if (note) note.textContent = 'Looking up…';
        var body = new FormData();
        body.append('_csrf', csrf);
        body.append('tin', tin);
        body.append('role', root.id.indexOf('seller') >= 0 ? 'seller' : 'buyer');
        fetch(base + '/lookup-tin', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    if (note) note.textContent = (data && data.error) ? data.error : 'No details for that TIN.';
                    return;
                }
                fillParty(root, data.party || {}, false);
                if (note) note.textContent = 'Filled empty fields from ' + (data.source || 'NRS') + '.';
            })
            .catch(function () {
                if (note) note.textContent = 'Lookup failed.';
            });
    }

    function money(n) {
        var v = Math.round((Number(n) || 0) * 100) / 100;
        return '₦' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function num(v) {
        var n = parseFloat(String(v || '').replace(/,/g, ''));
        return isNaN(n) ? 0 : n;
    }
    function recalc() {
        var form = document.getElementById('invoiceForm');
        if (!form) return;
        var rate = num(document.getElementById('vatRate') && document.getElementById('vatRate').value);
        var inclusive = document.getElementById('vatInclusive') && document.getElementById('vatInclusive').checked;
        var gross = 0;
        form.querySelectorAll('.line').forEach(function (row) {
            var q = num(row.querySelector('.qty') && row.querySelector('.qty').value);
            var p = num(row.querySelector('.price') && row.querySelector('.price').value);
            if (q < 0) q = 0;
            var line = Math.round(q * p * 100) / 100;
            var slot = row.querySelector('.line-total');
            if (slot) slot.textContent = money(line);
            gross += line;
        });
        gross = Math.round(gross * 100) / 100;
        var net, vat, total;
        if (inclusive && rate > 0) {
            net = Math.round((gross / (1 + rate / 100)) * 100) / 100;
            vat = Math.round((gross - net) * 100) / 100;
            total = gross;
        } else {
            net = gross;
            vat = Math.round(net * (rate / 100) * 100) / 100;
            total = Math.round((net + vat) * 100) / 100;
        }
        var netEl = document.getElementById('netTotal');
        var vatEl = document.getElementById('vatTotal');
        var grand = document.getElementById('grandTotal');
        if (netEl) netEl.textContent = money(net);
        if (vatEl) vatEl.textContent = money(vat);
        if (grand) grand.textContent = money(total);
        var preview = document.getElementById('irnPreview');
        if (preview) {
            var number = (form.querySelector('[name="invoice_number"]').value || 'INV').toUpperCase().replace(/[^A-Z0-9]/g, '') || 'INV';
            var sid = (form.getAttribute('data-service-id') || 'SERVICEID').toUpperCase().replace(/[^A-Z0-9]/g, '') || 'SERVICEID';
            var date = (form.querySelector('[name="issue_date"]').value || '').replace(/-/g, '');
            if (date.length < 8) date = 'YYYYMMDD';
            preview.textContent = number + '-' + sid + '-' + date;
        }
    }
    var invoice = document.getElementById('invoiceForm');
    if (invoice) {
        invoice.addEventListener('input', recalc);
        invoice.addEventListener('change', recalc);
        recalc();
    }
})();
