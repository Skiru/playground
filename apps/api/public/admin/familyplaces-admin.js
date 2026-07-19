/**
 * FamilyPlaces Admin custom JS interactions
 */
document.addEventListener('DOMContentLoaded', () => {
    // 1. Prevent double submission of forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', (e) => {
            const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            // If the form has already been submitted, prevent default and do nothing
            if (form.dataset.submitting === 'true') {
                e.preventDefault();
                return;
            }
            form.dataset.submitting = 'true';
            submitButtons.forEach(button => {
                button.disabled = true;
                const originalText = button.textContent || button.value;
                button.dataset.originalText = originalText;
                if (button.tagName === 'INPUT') {
                    button.value = 'Proszę czekać...';
                } else {
                    button.textContent = 'Proszę czekać...';
                }
            });
        });
    });

    // 2. Destructive actions confirmation
    const destructiveButtons = document.querySelectorAll('[data-confirm]');
    destructiveButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            const message = button.getAttribute('data-confirm') || 'Czy na pewno chcesz wykonać tę akcję?';
            if (!confirm(message)) {
                e.preventDefault();
                // Re-enable in case form submission was intercepted
                const form = button.closest('form');
                if (form) {
                    delete form.dataset.submitting;
                    const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                    submitButtons.forEach(btn => {
                        btn.disabled = false;
                        if (btn.dataset.originalText) {
                            if (btn.tagName === 'INPUT') {
                                btn.value = btn.dataset.originalText;
                            } else {
                                btn.textContent = btn.dataset.originalText;
                            }
                        }
                    });
                }
            }
        });
    });
});
