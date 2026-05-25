(function() {
    'use strict';

    var cfg          = document.getElementById('maker-config');
    if (!cfg) return;

    var MAKER_CSRF   = cfg.getAttribute('data-csrf');
    var NOMINATE_URL = cfg.getAttribute('data-nominate-url');
    var SEARCH_URL   = cfg.getAttribute('data-search-url');
    var IS_ADMIN     = cfg.getAttribute('data-is-admin') === '1';

    // Onglets
    document.querySelectorAll('.maker-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.maker-tab').forEach(function(t) {
                t.classList.remove('active');
            });
            document.querySelectorAll('.maker-panel').forEach(function(p) {
                p.classList.remove('active');
            });
            this.classList.add('active');
            var panel = document.getElementById('panel-' + this.getAttribute('data-panel'));
            if (panel) panel.classList.add('active');
        });
    });

    // Gadget select → quantité max (non-admin)
    if (!IS_ADMIN) {
        var gadgetSelect = document.getElementById('gadget-select');
        var qtyInput     = document.getElementById('quantity-input');
        var walletHint   = document.getElementById('wallet-hint');
        var walletCount  = document.getElementById('wallet-count');
        var qtyError     = document.getElementById('qty-error');
        var qtyMaxLabel  = document.getElementById('qty-max-label');

        if (gadgetSelect && qtyInput) {
            gadgetSelect.addEventListener('change', function() {
                var selected = this.options[this.selectedIndex];
                var max      = parseInt(selected.getAttribute('data-max') || '0', 10);

                if (!selected.value || max === 0) {
                    qtyInput.disabled    = true;
                    qtyInput.value       = '';
                    qtyInput.placeholder = 'Choisir un gadget avant';
                    if (walletHint) walletHint.style.display = 'none';
                    return;
                }

                qtyInput.disabled    = false;
                qtyInput.max         = max;
                qtyInput.value       = 1;
                qtyInput.placeholder = 'Entre 1 et ' + max;
                if (walletHint) { walletHint.style.display = 'block'; }
                if (walletCount) { walletCount.textContent = max; }
                if (qtyError) { qtyError.style.display = 'none'; }
            });

            qtyInput.addEventListener('input', function() {
                var opt = gadgetSelect.options[gadgetSelect.selectedIndex];
                var max = parseInt(opt ? opt.getAttribute('data-max') || '0' : '0', 10);
                var val = parseInt(this.value, 10);
                if (val > max && max > 0) {
                    this.value = max;
                    if (qtyError) { qtyError.style.display = 'block'; }
                    if (qtyMaxLabel) { qtyMaxLabel.textContent = max; }
                } else {
                    if (qtyError) { qtyError.style.display = 'none'; }
                }
            });
        }
    }

    // Mode distribution → afficher/cacher max/visiteur
    var distSelect = document.getElementById('dist-mode-select');
    if (distSelect) {
        distSelect.addEventListener('change', function() {
            var field = document.getElementById('field-per-visitor');
            if (field) { field.style.display = this.value === 'exposed' ? 'block' : 'none'; }
        });
    }

    // Recherche utilisateurs
    var searchTimeout;
    var searchInput    = document.getElementById('user-search-input');
    var searchResults  = document.getElementById('user-search-results');
    var selectedIdEl   = document.getElementById('selected-user-id');
    var selectedNameEl = document.getElementById('selected-user-name');
    var selectedDisp   = document.getElementById('selected-user-display');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            var q = this.value.trim();
            if (q.length < 2) {
                if (searchResults) { searchResults.style.display = 'none'; }
                return;
            }
            searchTimeout = setTimeout(function() {
                fetch(SEARCH_URL + '?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!searchResults) return;
                        searchResults.innerHTML = '';
                        if (!data.length) {
                            var empty = document.createElement('div');
                            empty.className = 'user-search-item';
                            empty.style.color = '#9ca3af';
                            empty.textContent = 'Aucun resultat';
                            searchResults.appendChild(empty);
                        } else {
                            data.forEach(function(u) {
                                var div = document.createElement('div');
                                div.className = 'user-search-item';
                                var strong = document.createElement('strong');
                                strong.textContent = u.name;
                                var span = document.createElement('span');
                                span.textContent = u.email;
                                div.appendChild(strong);
                                div.appendChild(span);
                                div.addEventListener('click', function() {
                                    if (selectedIdEl)   { selectedIdEl.value = u.id; }
                                    if (selectedNameEl) { selectedNameEl.textContent = u.name + ' (' + u.email + ')'; }
                                    if (selectedDisp)   { selectedDisp.style.display = 'block'; }
                                    searchInput.value = u.name;
                                    searchResults.style.display = 'none';
                                });
                                searchResults.appendChild(div);
                            });
                        }
                        searchResults.style.display = 'block';
                    });
            }, 300);
        });
    }

    document.addEventListener('click', function(e) {
        if (searchResults && !e.target.closest('.user-search-wrap')) {
            searchResults.style.display = 'none';
        }
    });

    // Attribution nominative
    var btnNominate = document.getElementById('btn-nominate');
    if (btnNominate) {
        btnNominate.addEventListener('click', function() {
            var allocEl  = document.getElementById('nominate-allocation');
            var textEl   = document.getElementById('nominate-text');
            var resultEl = document.getElementById('nominate-result');
            var self     = this;

            if (!allocEl || !allocEl.value) { alert('Choisissez une allocation.'); return; }
            if (!selectedIdEl || !selectedIdEl.value) { alert('Choisissez un utilisateur.'); return; }

            self.disabled    = true;
            self.textContent = 'Attribution en cours...';

            var fd = new FormData();
            fd.append('allocation_id', allocEl.value);
            fd.append('user_id', selectedIdEl.value);
            fd.append('custom_text', textEl ? textEl.value : '');
            fd.append('_token', MAKER_CSRF);

            fetch(NOMINATE_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!resultEl) return;
                    resultEl.style.display = 'block';
                    if (data.success) {
                        resultEl.style.color = '#15803d';
                        resultEl.textContent = data.gadgetName + ' attribue a ' + data.recipientName;
                        if (selectedIdEl)   { selectedIdEl.value = ''; }
                        if (selectedNameEl) { selectedNameEl.textContent = ''; }
                        if (selectedDisp)   { selectedDisp.style.display = 'none'; }
                        if (searchInput)    { searchInput.value = ''; }
                        if (textEl)         { textEl.value = ''; }
                    } else {
                        resultEl.style.color = '#dc2626';
                        resultEl.textContent = data.error || 'Erreur';
                    }
                    self.disabled    = false;
                    self.textContent = 'Attribuer le gadget';
                });
        });
    }

    // Hash panel nominatif
    if (window.location.hash && window.location.hash.indexOf('nominate-') !== -1) {
        var tab = document.querySelector('[data-panel="nominate"]');
        if (tab) { tab.click(); }
    }

})();
