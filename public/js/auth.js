/**
 * EnMémoire.com — auth.js
 * Interactions des pages d'authentification
 */
document.addEventListener('DOMContentLoaded', function () {

    // --------------------------------------------------
    // 1. Toggle visibilité mot de passe
    // --------------------------------------------------
    document.querySelectorAll('.auth-toggle-pw').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = this.parentElement.querySelector('input');
            const icon  = this.querySelector('i');
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        });
    });

    // --------------------------------------------------
    // 2. Force du mot de passe
    // --------------------------------------------------
    const pwInput  = document.getElementById('reg_password');
    const pwBar    = document.querySelector('.auth-pw-strength__bar');

    if (pwInput && pwBar) {
        pwInput.addEventListener('input', function () {
            const val = this.value;
            let score = 0;
            if (val.length >= 8)  score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            const pct    = (score / 4) * 100;
            const colors = ['#EF4444', '#F97316', '#EAB308', '#22C55E'];
            pwBar.style.width     = pct + '%';
            pwBar.style.background = colors[score - 1] || '#EF4444';
        });
    }

    // --------------------------------------------------
    // 3. Confirmation mot de passe en temps réel
    // --------------------------------------------------
    const pwConfirm = document.getElementById('reg_password_confirm');
    const matchMsg  = document.getElementById('pw-match-msg');

    if (pwInput && pwConfirm && matchMsg) {
        const check = () => {
            if (!pwConfirm.value) { matchMsg.textContent = ''; return; }
            if (pwInput.value === pwConfirm.value) {
                matchMsg.textContent = '✓ Les mots de passe correspondent';
                matchMsg.style.color = '#22C55E';
                pwConfirm.classList.replace('is-invalid', 'is-valid');
            } else {
                matchMsg.textContent = '✗ Les mots de passe ne correspondent pas';
                matchMsg.style.color = '#EF4444';
                pwConfirm.classList.replace('is-valid', 'is-invalid');
            }
        };
        pwConfirm.addEventListener('input', check);
        pwInput.addEventListener('input', check);
    }

    // --------------------------------------------------
    // 4. Sélecteur canal OTP (email / whatsapp)
    // --------------------------------------------------
    const channels = document.querySelectorAll('.auth-channel input[type="radio"]');
    const waField  = document.getElementById('whatsapp-field');

    channels.forEach(radio => {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.auth-channel').forEach(l => l.classList.remove('active'));
            this.closest('.auth-channel').classList.add('active');
            if (waField) {
                waField.style.display = this.value === 'whatsapp' ? 'block' : 'none';
                const phoneInput = waField.querySelector('input');
                if (phoneInput) phoneInput.required = this.value === 'whatsapp';
            }
        });
    });

    // --------------------------------------------------
    // 5. Activation bouton register (CGU obligatoire)
    // --------------------------------------------------
    const cguCheckbox   = document.getElementById('cgu');
    const registerBtn   = document.getElementById('register-submit');

    if (cguCheckbox && registerBtn) {
        const toggleBtn = () => { registerBtn.disabled = !cguCheckbox.checked; };
        cguCheckbox.addEventListener('change', toggleBtn);
        toggleBtn();
    }

    // --------------------------------------------------
    // 6. Saisie OTP — navigation automatique entre cases
    // --------------------------------------------------
    const otpBoxes = document.getElementById('otp-boxes');
    if (otpBoxes) {
        const digits = otpBoxes.querySelectorAll('.auth-otp-digit');

        digits.forEach((input, idx) => {
            // Focus auto sur le premier
            if (idx === 0) setTimeout(() => input.focus(), 100);

            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(-1);
                if (this.value) {
                    this.classList.add('filled');
                    const next = digits[idx + 1];
                    if (next) next.focus();
                    else {
                        // Tous les champs remplis → soumettre automatiquement
                        const allFilled = [...digits].every(d => d.value.length === 1);
                        if (allFilled) {
                            setTimeout(() => {
                                const form = otpBoxes.closest('form');
                                if (form) form.requestSubmit();
                            }, 200);
                        }
                    }
                } else {
                    this.classList.remove('filled');
                }
            });

            // Backspace : revenir en arrière
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !this.value && idx > 0) {
                    digits[idx - 1].focus();
                    digits[idx - 1].value = '';
                    digits[idx - 1].classList.remove('filled');
                }
                // Coller (Ctrl+V)
                if (e.key === 'v' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    navigator.clipboard.readText().then(text => {
                        const cleaned = text.replace(/\D/g, '').slice(0, 6);
                        if (cleaned.length === 6) {
                            [...digits].forEach((d, i) => {
                                d.value = cleaned[i] || '';
                                d.classList.toggle('filled', !!cleaned[i]);
                            });
                            digits[5].focus();
                        }
                    });
                }
            });

            // Coller directement sur une case
            input.addEventListener('paste', function (e) {
                e.preventDefault();
                const text = (e.clipboardData || window.clipboardData).getData('text');
                const cleaned = text.replace(/\D/g, '').slice(0, 6);
                if (cleaned) {
                    [...digits].forEach((d, i) => {
                        d.value = cleaned[i] || '';
                        d.classList.toggle('filled', !!cleaned[i]);
                    });
                    if (cleaned.length === 6) digits[5].focus();
                }
            });
        });
    }
});
