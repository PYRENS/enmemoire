/**
 * EnMémoire.com — dashboard.js
 * Interactions du tableau de bord modérateur
 */
document.addEventListener('DOMContentLoaded', function () {

    // --------------------------------------------------
    // 1. Sidebar mobile toggle
    // --------------------------------------------------
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar       = document.getElementById('dashboard-sidebar');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
    }

    // --------------------------------------------------
    // 2. Onglets de modération (condoléances / témoignages / livre d'or)
    // --------------------------------------------------
    const modTabs = document.querySelectorAll('.moderation-tab-btn');
    const modPanels = document.querySelectorAll('.moderation-panel');

    modTabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const target = this.dataset.target;
            modTabs.forEach(t => t.classList.remove('active'));
            modPanels.forEach(p => p.style.display = 'none');
            this.classList.add('active');
            const panel = document.getElementById(target);
            if (panel) panel.style.display = 'block';
        });
    });

    // Activer le premier onglet par défaut
    if (modTabs.length) modTabs[0].click();

    // --------------------------------------------------
    // 3. Ajout à la liste de confiance (Ajax)
    // --------------------------------------------------
    const trustForm = document.getElementById('trust-add-form');
    if (trustForm) {
        trustForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const slug    = this.dataset.slug;
            const emailEl = this.querySelector('[name="email"]');
            const list    = document.getElementById('trust-list');
            const btn     = this.querySelector('[type="submit"]');

            btn.disabled = true;
            const origText = btn.innerHTML;
            btn.innerHTML = '<span class="em-spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;"></span>';

            try {
                const res = await fetch(`/dashboard/memorial/${slug}/trust/add`, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams(new FormData(this))
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erreur');

                if (list) {
                    const li = document.createElement('li');
                    li.className = 'trust-list-item em-animate-in';
                    li.innerHTML = `
                        <span class="trust-list-item__name">
                            <i class="bi bi-check-circle text-success"></i>
                            ${data.name} <small class="text-muted">&lt;${emailEl.value}&gt;</small>
                        </span>`;
                    const placeholder = list.querySelector('.trust-empty');
                    if (placeholder) placeholder.remove();
                    list.appendChild(li);
                }

                emailEl.value = '';
                EmUtils.toast(`${data.name} ajouté à votre liste de confiance.`, 'success');

            } catch (err) {
                EmUtils.toast(err.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        });
    }

    // --------------------------------------------------
    // 4. Preview du thème en temps réel dans le modal
    // --------------------------------------------------
    document.querySelectorAll('.theme-picker-item input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.theme-picker-item').forEach(item => {
                item.classList.remove('theme-picker-item--active');
            });
            this.closest('.theme-picker-item').classList.add('theme-picker-item--active');
        });
    });

    // --------------------------------------------------
    // 5. Confirmation suppression élément timeline
    // --------------------------------------------------
    document.querySelectorAll('[data-delete-timeline]').forEach(btn => {
        btn.addEventListener('click', function (e) {
            if (!confirm('Supprimer cette étape de la ligne de vie ?')) {
                e.preventDefault();
            }
        });
    });

    // --------------------------------------------------
    // 6. Confirmation suppression événement
    // --------------------------------------------------
    document.querySelectorAll('[data-delete-event]').forEach(btn => {
        btn.addEventListener('click', function (e) {
            if (!confirm('Supprimer cet événement ? Cette action est irréversible.')) {
                e.preventDefault();
            }
        });
    });

    // --------------------------------------------------
    // 7. Validation code page rapprochement
    // --------------------------------------------------
    const pageCodeInput = document.querySelector('[name="target_page_code"]');
    if (pageCodeInput) {
        pageCodeInput.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
            if (!this.value.startsWith('MEM-') && this.value.length >= 4) {
                if (!this.value.includes('-')) {
                    this.value = 'MEM-' + this.value;
                }
            }
        });
    }

    // --------------------------------------------------
    // 8. Animation KPI au chargement
    // --------------------------------------------------
    const kpiValues = document.querySelectorAll('.dashboard-kpi__value');
    kpiValues.forEach(el => {
        const target = parseInt(el.textContent) || 0;
        if (target === 0) return;
        let current = 0;
        const step  = Math.max(1, Math.ceil(target / 30));
        const timer = setInterval(() => {
            current = Math.min(current + step, target);
            el.textContent = current.toLocaleString('fr-FR');
            if (current >= target) clearInterval(timer);
        }, 30);
    });

    // --------------------------------------------------
    // 9. Modération rapide par raccourcis clavier
    // --------------------------------------------------
    document.addEventListener('keydown', function (e) {
        const focused = document.activeElement;
        // Ignorer si focus dans un input/textarea
        if (['INPUT','TEXTAREA','SELECT'].includes(focused.tagName)) return;

        // Alt + A = approuver le premier élément en attente
        if (e.altKey && e.key === 'a') {
            const firstApprove = document.querySelector('.btn-approve');
            if (firstApprove) { firstApprove.click(); }
        }
    });

    // --------------------------------------------------
    // 10. Copie du code modérateur
    // --------------------------------------------------
    document.querySelectorAll('.copy-mod-code').forEach(btn => {
        btn.addEventListener('click', function () {
            const code = this.dataset.code;
            navigator.clipboard.writeText(code).then(() => {
                const orig = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check2"></i>';
                setTimeout(() => { this.innerHTML = orig; }, 1500);
            });
        });
    });
});
