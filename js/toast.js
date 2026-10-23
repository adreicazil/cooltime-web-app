(function () {
  if (window.__cooltimeToastInstalled) return;
  window.__cooltimeToastInstalled = true;

  const queue = [];
  let active = false;
  let backdrop;
  let message;
  let fieldHost;
  let okButton;
  let cancelButton;

  function createDialog() {
    backdrop = document.createElement('div');
    backdrop.className = 'app-toast-backdrop';
    backdrop.hidden = true;
    backdrop.innerHTML = '<div class="app-toast-wrap"><section class="app-toast" role="dialog" aria-modal="true" aria-labelledby="appToastMessage"><div class="app-toast-message" id="appToastMessage"></div><div class="app-toast-field-host"></div><div class="app-toast-actions"><button class="app-toast-cancel" type="button">Cancel</button><button class="app-toast-ok" type="button">OK</button></div></section></div>';
    message = backdrop.querySelector('.app-toast-message');
    fieldHost = backdrop.querySelector('.app-toast-field-host');
    okButton = backdrop.querySelector('.app-toast-ok');
    cancelButton = backdrop.querySelector('.app-toast-cancel');
    okButton.addEventListener('click', () => closeCurrent(true));
    cancelButton.addEventListener('click', () => closeCurrent(false));
    backdrop.addEventListener('click', (event) => {
      if (event.target === backdrop) closeCurrent(false);
    });
    document.addEventListener('keydown', (event) => {
      if (!active) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeCurrent(false);
      } else if (event.key === 'Tab') {
        event.preventDefault();
        (event.shiftKey ? cancelButton : okButton).focus();
      }
    });
    document.body.append(backdrop);
  }

  function closeCurrent(accepted) {
    if (!active) return;
    const current = queue.shift();
    const value = current.type === 'alert' ? undefined
      : accepted ? (fieldHost.querySelector('input, textarea, select')?.value ?? '') : null;
    backdrop.hidden = true;
    active = false;
    current.resolve(value);
    showNext();
  }

  function showNext() {
    if (active || !queue.length) return;
    if (!backdrop) createDialog();
    const current = queue[0];
    message.textContent = current.message;
    fieldHost.replaceChildren();
    fieldHost.hidden = current.type === 'alert';
    cancelButton.hidden = current.type === 'alert';
    backdrop.querySelector('.app-toast').setAttribute('role', current.type === 'alert' ? 'alertdialog' : 'dialog');
    if (current.type !== 'alert') {
      let field;
      if (Array.isArray(current.choices)) {
        field = document.createElement('select');
        field.className = 'app-toast-select';
        field.size = Math.max(2, Math.min(current.choices.length, 4));
        current.choices.forEach((choice) => field.add(new Option(choice, choice)));
        if (current.value != null) field.value = current.value;
        field.setAttribute('aria-label', current.message);
      } else if (current.multiline) {
        field = document.createElement('textarea');
        field.rows = 3;
        field.className = 'app-toast-input app-toast-textarea';
        field.value = current.value ?? '';
        field.setAttribute('aria-label', current.message);
      } else {
        field = document.createElement('input');
        field.type = current.inputType || 'text';
        field.className = 'app-toast-input';
        field.value = current.value ?? '';
        field.setAttribute('aria-label', current.message);
      }
      fieldHost.append(field);
    }
    backdrop.hidden = false;
    active = true;
    (current.type === 'alert' ? okButton : fieldHost.querySelector('input, textarea, select')).focus();
  }

  window.alert = function (value) {
    return new Promise((resolve) => {
      queue.push({ type: 'alert', message: String(value ?? ''), resolve });
      showNext();
    });
  };

  window.prompt = function (messageText, defaultValue = '', options = {}) {
    return new Promise((resolve) => {
      queue.push({ type: 'prompt', message: String(messageText ?? ''), value: defaultValue, resolve, ...options });
      showNext();
    });
  };
})();
