/**
 * EnMémoire.com — memorial.js
 * Interactions de la page mémorielle publique
 */
document.addEventListener('DOMContentLoaded', function () {

    // --------------------------------------------------
    // 1. Navigation par onglets (scroll spy)
    // --------------------------------------------------
    const tabs   = document.querySelectorAll('.memorial-tab');
    const navbar = document.getElementById('memorial-nav');

    if (tabs.length && navbar) {
        const sections = [...tabs].map(t => {
            const id = t.getAttribute('href');
            return id ? document.querySelector(id) : null;
        }).filter(Boolean);

        const navH = (document.getElementById('site-header')?.offsetHeight || 68)
                   + (navbar.offsetHeight || 48) + 16;

        const spy = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    tabs.forEach(t => t.classList.remove('active'));
                    const activeTab = [...tabs].find(
                        t => t.getAttribute('href') === '#' + entry.target.id
                    );
                    if (activeTab) activeTab.classList.add('active');
                }
            });
        }, { rootMargin: `-${navH}px 0px -60% 0px`, threshold: 0 });

        sections.forEach(s => spy.observe(s));

        // Smooth scroll avec offset double header + tabs
        tabs.forEach(tab => {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    window.scrollTo({
                        top: target.getBoundingClientRect().top + window.scrollY - navH,
                        behavior: 'smooth'
                    });
                }
            });
        });
    }

    // --------------------------------------------------
    // 2. Formulaire condoléance (Ajax)
    // --------------------------------------------------
    const condolenceForm = document.getElementById('condolence-form');
    if (condolenceForm) {
        condolenceForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const slug     = this.dataset.slug;
            const feedback = document.getElementById('condolence-feedback');
            const btn      = this.querySelector('[type="submit"]');
            const list     = document.getElementById('condolences-list');

            btn.disabled   = true;
            const origText = btn.innerHTML;
            btn.innerHTML  = '<span class="em-spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></span> Envoi...';

            try {
                const res  = await fetch(`/memorial/${slug}/condolence`, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(new FormData(this))
                });
                const data = await res.json();

                if (!res.ok) throw new Error(data.error || 'Erreur serveur');

                feedback.innerHTML = `<div class="em-badge em-badge--${data.status === 'approved' ? 'active' : 'pending'} py-1 px-2">
                    <i class="bi bi-${data.status === 'approved' ? 'check-circle' : 'hourglass-split'}"></i>
                    ${data.message}
                </div>`;

                if (data.status === 'approved' && data.html && list) {
                    const placeholder = list.querySelector('.text-muted');
                    if (placeholder) placeholder.remove();
                    list.insertAdjacentHTML('afterbegin', data.html);
                    // Animation
                    list.firstElementChild?.classList.add('em-animate-in');
                }

                this.reset();
                setTimeout(() => { feedback.innerHTML = ''; }, 5000);

            } catch (err) {
                feedback.innerHTML = `<div class="em-badge em-badge--rejected py-1 px-2">
                    <i class="bi bi-exclamation-circle"></i> ${err.message}
                </div>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        });
    }

    // --------------------------------------------------
    // 3. Formulaire témoignage (Ajax)
    // --------------------------------------------------
    const testimonialForm = document.getElementById('testimonial-form');
    if (testimonialForm) {
        testimonialForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const slug     = this.dataset.slug;
            const feedback = document.getElementById('testimonial-feedback');
            const btn      = this.querySelector('[type="submit"]');

            btn.disabled = true;
            const origText = btn.innerHTML;
            btn.innerHTML = '<span class="em-spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></span> Publication...';

            try {
                const res  = await fetch(`/memorial/${slug}/testimonial`, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(new FormData(this))
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erreur');

                feedback.innerHTML = `<div class="em-badge em-badge--${data.status === 'approved' ? 'active' : 'pending'} py-1 px-2">
                    <i class="bi bi-${data.status === 'approved' ? 'check-circle' : 'hourglass-split'}"></i>
                    ${data.message}
                </div>`;
                this.reset();
                if (data.status === 'approved') {
                    setTimeout(() => window.location.reload(), 1500);
                }
            } catch (err) {
                feedback.innerHTML = `<div class="em-badge em-badge--rejected py-1 px-2">
                    <i class="bi bi-exclamation-circle"></i> ${err.message}
                </div>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        });
    }

    // --------------------------------------------------
    // 4. Formulaire livre d'or (Ajax)
    // --------------------------------------------------
    const guestbookForm = document.getElementById('guestbook-form');
    if (guestbookForm) {
        guestbookForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const slug     = this.dataset.slug;
            const feedback = document.getElementById('guestbook-feedback');
            const btn      = this.querySelector('[type="submit"]');

            btn.disabled = true;
            const origText = btn.innerHTML;
            btn.innerHTML = '<span class="em-spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></span>';

            try {
                const res  = await fetch(`/memorial/${slug}/guestbook`, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(new FormData(this))
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erreur');

                feedback.innerHTML = `<div class="em-badge em-badge--active py-1 px-2">
                    <i class="bi bi-check-circle"></i> ${data.message}
                </div>`;
                this.style.display = 'none';
                if (data.status === 'approved') setTimeout(() => window.location.reload(), 1200);

            } catch (err) {
                feedback.innerHTML = `<div class="em-badge em-badge--rejected py-1 px-2">
                    <i class="bi bi-exclamation-circle"></i> ${err.message}
                </div>`;
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        });
    }

    // --------------------------------------------------
    // 5. Galerie lightbox
    // --------------------------------------------------
    const galleryModal = document.getElementById('galleryModal');
    if (galleryModal) {
        const modalImg     = document.getElementById('gallery-modal-img');
        const modalCaption = document.getElementById('gallery-modal-caption');

        document.querySelectorAll('.memorial-gallery__item').forEach(item => {
            item.addEventListener('click', function (e) {
                e.preventDefault();
                if (modalImg) modalImg.src = this.dataset.src || this.href;
                if (modalCaption) modalCaption.textContent = this.dataset.caption || '';
            });
        });
    }

    // --------------------------------------------------
    // 6. Gadgets — animation + requête Ajax
    // --------------------------------------------------
    document.querySelectorAll('.memorial-gadget-btn').forEach(btn => {
        btn.addEventListener('click', async function () {
            const gadget = this.dataset.gadget;
            const slug   = this.dataset.slug;

            // Animation rapide
            this.style.transform = 'scale(1.3)';
            setTimeout(() => { this.style.transform = ''; }, 300);

            // Lancer l'animation thématique
            launchGadgetAnimation(gadget);

            // TODO: appel API gadget quand la boutique sera prête
            EmUtils.toast(
                gadget === 'flower' ? '🌹 Fleur déposée !'
                : gadget === 'candle' ? '🕯️ Bougie allumée !'
                : '🕊️ Colombe lancée !',
                'success'
            );
        });
    });

    function launchGadgetAnimation(type) {
        const container = document.createElement('div');
        container.style.cssText = `
            position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
            font-size:3rem; z-index:9999; pointer-events:none;
            animation: gadget-fly 1.5s ease forwards;`;
        container.textContent = type === 'flower' ? '🌹' : type === 'candle' ? '🕯️' : '🕊️';

        const style = document.createElement('style');
        style.textContent = `
            @keyframes gadget-fly {
                0%   { transform:translate(-50%,-50%) scale(0.5); opacity:1; }
                60%  { transform:translate(-50%,-120%) scale(1.5); opacity:1; }
                100% { transform:translate(-50%,-200%) scale(0.8); opacity:0; }
            }`;
        document.head.appendChild(style);
        document.body.appendChild(container);
        setTimeout(() => { container.remove(); style.remove(); }, 1600);
    }

    // --------------------------------------------------
    // 7. Compteur de caractères textarea
    // --------------------------------------------------
    document.querySelectorAll('textarea[maxlength]').forEach(ta => {
        const max = parseInt(ta.getAttribute('maxlength'));
        const counter = document.createElement('small');
        counter.style.cssText = 'color:var(--em-muted);display:block;text-align:right;margin-top:3px;';
        counter.textContent = `0 / ${max}`;
        ta.parentNode.insertBefore(counter, ta.nextSibling);
        ta.addEventListener('input', () => {
            const len = ta.value.length;
            counter.textContent = `${len} / ${max}`;
            counter.style.color = len > max * 0.9 ? 'var(--em-warning)' : 'var(--em-muted)';
        });
    });
});
