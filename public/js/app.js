/**
 * EnMémoire.com — app.js
 * Scripts JavaScript principaux
 */

document.addEventListener('DOMContentLoaded', function () {

    // --------------------------------------------------
    // 1. Header scroll effect
    // --------------------------------------------------
    const header = document.getElementById('site-header');
    if (header) {
        const onScroll = () => {
            if (window.scrollY > 20) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // --------------------------------------------------
    // 2. Compteur de notifications (polling léger)
    // --------------------------------------------------
    const notifBadge = document.getElementById('notif-count');
    if (notifBadge && document.body.classList.contains('user-logged')) {
        const fetchNotifCount = () => {
            fetch('/api/notifications/count', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data) return;
                if (data.count > 0) {
                    notifBadge.textContent = data.count > 99 ? '99+' : data.count;
                    notifBadge.style.display = 'flex';
                } else {
                    notifBadge.style.display = 'none';
                }
            })
            .catch(() => {});
        };

        fetchNotifCount();
        setInterval(fetchNotifCount, 60000); // Poll chaque minute
    }

    // --------------------------------------------------
    // 3. Tooltips Bootstrap
    // --------------------------------------------------
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el, { trigger: 'hover focus' });
    });

    // --------------------------------------------------
    // 4. Confirmation avant actions destructrices
    // --------------------------------------------------
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            const msg = this.dataset.confirm || 'Êtes-vous sûr ?';
            if (!window.confirm(msg)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });

    // --------------------------------------------------
    // 5. Formulaires : disable submit button après envoi
    // --------------------------------------------------
    document.querySelectorAll('form[data-disable-on-submit]').forEach(form => {
        form.addEventListener('submit', function () {
            const btn = this.querySelector('[type="submit"]');
            if (btn) {
                btn.disabled = true;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<span class="em-spinner" style="width:16px;height:16px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></span> Chargement...';
                // Re-enable après 10s (sécurité)
                setTimeout(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }, 10000);
            }
        });
    });

    // --------------------------------------------------
    // 6. Smooth scroll sur les liens d'ancrage
    // --------------------------------------------------
    document.querySelectorAll('a[href^="#"]').forEach(link => {
        link.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                const headerH = document.getElementById('site-header')?.offsetHeight || 70;
                const top = target.getBoundingClientRect().top + window.scrollY - headerH - 16;
                window.scrollTo({ top, behavior: 'smooth' });
            }
        });
    });

    // --------------------------------------------------
    // 7. Lazy-load images (Intersection Observer)
    // --------------------------------------------------
    if ('IntersectionObserver' in window) {
        const imgObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                    imgObserver.unobserve(img);
                }
            });
        }, { rootMargin: '200px' });

        document.querySelectorAll('img[data-src]').forEach(img => imgObserver.observe(img));
    }

    // --------------------------------------------------
    // 8. Copie du lien dans le presse-papier
    // --------------------------------------------------
    document.querySelectorAll('[data-copy-link]').forEach(btn => {
        btn.addEventListener('click', function () {
            const url = this.dataset.copyLink || window.location.href;
            navigator.clipboard.writeText(url).then(() => {
                const originalContent = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check2"></i> Copié !';
                this.classList.add('em-badge--active');
                setTimeout(() => {
                    this.innerHTML = originalContent;
                    this.classList.remove('em-badge--active');
                }, 2000);
            }).catch(() => {
                // Fallback
                const el = document.createElement('textarea');
                el.value = url;
                el.style.position = 'fixed';
                el.style.opacity = '0';
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                document.body.removeChild(el);
            });
        });
    });

    // --------------------------------------------------
    // 9. Animation au scroll (Intersection Observer)
    // --------------------------------------------------
    const animObs = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('em-animate-in');
                animObs.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('[data-animate]').forEach(el => animObs.observe(el));

});

// --------------------------------------------------
// 10. Utilitaires globaux
// --------------------------------------------------
window.EmUtils = {
    /**
     * Requête Ajax GET — retourne une Promise avec les données JSON
     */
    ajax(url, options = {}) {
        return fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                ...options.headers
            },
            ...options
        }).then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        });
    },

    /**
     * Affiche une notification toast Bootstrap
     */
    toast(message, type = 'info') {
        const id = 'toast-' + Date.now();
        const icons = { success: 'check-circle', danger: 'exclamation-circle', info: 'info-circle', warning: 'exclamation-triangle' };
        const html = `
            <div id="${id}" class="toast align-items-center border-0 bg-${type} text-white" role="alert" aria-live="assertive" data-bs-delay="4000">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-${icons[type] || 'info-circle'} me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>`;

        let container = document.getElementById('em-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'em-toast-container';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }

        container.insertAdjacentHTML('beforeend', html);
        const toastEl = document.getElementById(id);
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }
};
