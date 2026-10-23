(() => {
  const eyeIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
  const eyeOffIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"></path><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3 3.8"></path><path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1 0 1.9-.2 2.8-.5"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>';

  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = button.closest('.password-input-wrap')?.querySelector('input');
    if (!input) return;

    button.addEventListener('click', () => {
      const showPassword = input.type === 'password';
      input.type = showPassword ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(showPassword));
      button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
      button.innerHTML = showPassword ? eyeOffIcon : eyeIcon;
    });
  });
})();
