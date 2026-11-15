(() => {
  const form = document.getElementById('registerForm');
  const modal = document.getElementById('tocModal');
  const terms = document.getElementById('tocContainer');
  const checkbox = document.getElementById('tocCheckbox');
  const hint = document.getElementById('toc-scroll-hint');
  const continueButton = document.getElementById('tocSubmitBtn');
  const email = form?.querySelector('#email');
  if (!form || !modal || !terms || !checkbox || !continueButton) return;

  let returnFocus = null;
  let termsViewed = false;
  let onAgree = null;

  const syncContinueButton = () => {
    continueButton.disabled = !(termsViewed && checkbox.checked);
  };

  const reset = (closeModal = false) => {
    onAgree = null;
    termsViewed = false;
    terms.scrollTop = 0;
    checkbox.checked = false;
    checkbox.disabled = true;
    continueButton.disabled = true;
    checkbox.style.cursor = 'not-allowed';
    checkbox.style.opacity = '0.6';
    if (hint) hint.hidden = false;
    if (closeModal && modal.classList.contains('show')) {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('toc-modal-open');
      if (returnFocus?.isConnected) returnFocus.focus();
    }
  };

  const unlockIfAtBottom = () => {
    if (terms.scrollTop + terms.clientHeight < terms.scrollHeight - 5) return;
    termsViewed = true;
    checkbox.disabled = false;
    checkbox.style.cursor = 'pointer';
    checkbox.style.opacity = '1';
    if (hint) hint.hidden = true;
    syncContinueButton();
  };

  const open = (agreeCallback) => {
    reset(false);
    returnFocus = document.activeElement;
    onAgree = agreeCallback;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('toc-modal-open');
    terms.scrollTop = 0;
    terms.focus();
    requestAnimationFrame(unlockIfAtBottom);
  };

  terms.addEventListener('scroll', unlockIfAtBottom);
  checkbox.addEventListener('change', syncContinueButton);
  continueButton.addEventListener('click', () => {
    if (!termsViewed || !checkbox.checked) return;
    const callback = onAgree;
    reset(true);
    if (typeof callback === 'function') callback();
  });
  modal.querySelectorAll('[data-toc-dismiss]').forEach((button) => button.addEventListener('click', () => reset(true)));
  modal.addEventListener('click', (event) => {
    if (event.target === modal && modal.dataset.bsBackdrop !== 'false') reset(true);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('show') && modal.dataset.bsKeyboard !== 'false') reset(true);
  });
  email?.addEventListener('input', () => reset(true));

  window.CooltimeToc = { open, reset: () => reset(true) };
  reset();
})();
