/**
 * EnMémoire.com — home.js
 * Interactions de la page d'accueil
 */
document.addEventListener('DOMContentLoaded', function () {

    // --------------------------------------------------
    // 1. Autocomplete recherche hero
    // --------------------------------------------------
    const heroSearch   = document.getElementById('hero-search');
    const autocomplete = document.getElementById('hero-autocomplete');
    let acTimer;

    if (heroSearch && autocomplete) {
        heroSearch.addEventListener('input', function () {
            clearTimeout(acTimer);
            const q = this.value.trim();

            if (q.length < 2) {
                autocomplete.innerHTML = '';
                autocomplete.classList.remove('open');
                return;
            }

            acTimer = setTimeout(async () => {
                try {
                    const res  = await fetch(`/api/search/autocomplete?q=${encodeURIComponent(q)}`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await res.json();

                    if (!data.length) {
                        autocomplete.classList.remove('open');
                        return;
                    }

                    autocomplete.innerHTML = data.map(item => `
                        <a href="/memorial/${item.slug}" class="home-autocomplete__item">
                            <div class="home-autocomplete__photo">
                                ${item.photo
                                    ? `<img src="${item.photo}" alt="${item.name}" style="width:36px;height:36px;object-fit:cover;border-radius:50%;">`
                                    : '👤'}
                            </div>
                            <div>
                                <span class="home-autocomplete__name">${item.name}</span>
                                <span class="home-autocomplete__years">${item.years}</span>
                            </div>
                        </a>
                    `).join('');

                    autocomplete.classList.add('open');
                } catch (e) {}
            }, 300);
        });

        // Fermer au clic extérieur
        document.addEventListener('click', function (e) {
            if (!heroSearch.contains(e.target) && !autocomplete.contains(e.target)) {
                autocomplete.classList.remove('open');
            }
        });

        // Navigation clavier dans l'autocomplete
        heroSearch.addEventListener('keydown', function (e) {
            const items = autocomplete.querySelectorAll('.home-autocomplete__item');
            if (!items.length) return;

            const active = autocomplete.querySelector('.home-autocomplete__item--active');
            let idx      = [...items].indexOf(active);

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                active?.classList.remove('home-autocomplete__item--active');
                items[Math.min(idx + 1, items.length - 1)]?.classList.add('home-autocomplete__item--active');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                active?.classList.remove('home-autocomplete__item--active');
                items[Math.max(idx - 1, 0)]?.classList.add('home-autocomplete__item--active');
            } else if (e.key === 'Enter' && active) {
                e.preventDefault();
                window.location.href = active.href;
            } else if (e.key === 'Escape') {
                autocomplete.classList.remove('open');
            }
        });
    }

    // --------------------------------------------------
    // 2. Animation compteurs KPI (si présents sur la home)
    // --------------------------------------------------
    const counters = document.querySelectorAll('[data-counter]');
    if (counters.length) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const el     = entry.target;
                const target = parseInt(el.dataset.counter) || 0;
                let current  = 0;
                const step   = Math.ceil(target / 50);
                const timer  = setInterval(() => {
                    current = Math.min(current + step, target);
                    el.textContent = current.toLocaleString('fr-FR');
                    if (current >= target) clearInterval(timer);
                }, 30);
                observer.unobserve(el);
            });
        }, { threshold: 0.3 });

        counters.forEach(el => observer.observe(el));
    }

    // --------------------------------------------------
    // 3. Parallax léger sur le hero
    // --------------------------------------------------
    const hero = document.querySelector('.home-hero');
    if (hero && window.matchMedia('(prefers-reduced-motion: no-preference)').matches) {
        window.addEventListener('scroll', () => {
            const scrolled = window.scrollY;
            if (scrolled < window.innerHeight) {
                hero.style.backgroundPositionY = scrolled * 0.3 + 'px';
            }
        }, { passive: true });
    }

    // --------------------------------------------------
    // 4. Smooth reveal des cartes au scroll
    // --------------------------------------------------
    const animEls = document.querySelectorAll('[data-animate]');
    if (animEls.length) {
        const animObs = new IntersectionObserver(entries => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    setTimeout(() => {
                        entry.target.classList.add('em-animate-in');
                        entry.target.style.opacity = '1';
                    }, i * 80);
                    animObs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

        animEls.forEach(el => {
            el.style.opacity = '0';
            animObs.observe(el);
        });
    }

    // --------------------------------------------------
    // 5. Mise en évidence du plan tarifaire survolé
    // --------------------------------------------------
    document.querySelectorAll('.home-plan').forEach(plan => {
        plan.addEventListener('mouseenter', function () {
            document.querySelectorAll('.home-plan').forEach(p => {
                if (p !== this) p.style.opacity = '.8';
            });
        });
        plan.addEventListener('mouseleave', function () {
            document.querySelectorAll('.home-plan').forEach(p => {
                p.style.opacity = '';
            });
        });
    });
});
