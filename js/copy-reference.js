async function copyReference(button) {
  const reference = button.dataset.copyReference || '';
  if (!reference) return;
  try {
    if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(reference);
    else {
      const input = document.createElement('textarea');
      input.value = reference;
      input.style.position = 'fixed';
      input.style.opacity = '0';
      document.body.append(input);
      input.select();
      if (!document.execCommand('copy')) throw new Error('Copy failed');
      input.remove();
    }
    const original = button.textContent;
    button.textContent = 'Copied';
    window.setTimeout(() => { button.textContent = original; }, 1400);
  } catch (error) {
    console.error('Reference number could not be copied:', error);
    button.textContent = 'Copy failed';
    window.setTimeout(() => { button.textContent = 'Copy'; }, 1400);
  }
}

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-copy-reference]');
  if (button) copyReference(button);
});
