(() => {
  const form = document.getElementById('registerForm');
  const modal = document.getElementById('tocModal');
  const terms = document.getElementById('tocContainer');
  const checkbox = document.getElementById('tocCheckbox');
  const hint = document.getElementById('toc-scroll-hint');
  const continueButton = document.getElementById('tocSubmitBtn');
  if (!form || !modal || !terms || !checkbox || !continueButton) return;

  let returnFocus = null;
  let termsViewed = false;
  checkbox.disabled = true;
  continueButton.disabled = true;
  const close = () => {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('toc-modal-open');
    if (returnFocus?.isConnected) returnFocus.focus();
  };
  const open = () => {
    returnFocus = document.activeElement;
    termsViewed = false;
    checkbox.checked = false;
    checkbox.disabled = true;
    checkbox.style.cursor = 'not-allowed';
    checkbox.style.opacity = '0.6';
    continueButton.disabled = true;
    hint.hidden = false;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('toc-modal-open');
    terms.scrollTop = 0;
    terms.focus();
  };

  form.addEventListener('submit', (event) => {
    if (form.dataset.tocAccepted === 'true') return;
    event.preventDefault();
    // auth.js also listens for submit on this form. Stop later listeners so
    // registration cannot start until the user accepts the terms.
    event.stopImmediatePropagation();
    if (!form.reportValidity()) return;
    open();
  });

  terms.addEventListener('scroll', () => {
    const isAtBottom = terms.scrollTop + terms.clientHeight >= terms.scrollHeight - 5;
    if (!isAtBottom) return;
    termsViewed = true;
    checkbox.disabled = false;
    checkbox.style.cursor = 'pointer';
    checkbox.style.opacity = '1';
    hint.hidden = true;
  });
  checkbox.addEventListener('change', () => {
    continueButton.disabled = !termsViewed || !checkbox.checked;
  });
  continueButton.addEventListener('click', () => {
    if (!termsViewed || !checkbox.checked) return;
    form.dataset.tocAccepted = 'true';
    close();
    form.requestSubmit();
  });
  modal.querySelectorAll('[data-toc-dismiss]').forEach((button) => button.addEventListener('click', close));
  modal.addEventListener('click', (event) => {
    if (event.target === modal && modal.dataset.bsBackdrop !== 'false') close();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('show') && modal.dataset.bsKeyboard !== 'false') close();
  });
})();
