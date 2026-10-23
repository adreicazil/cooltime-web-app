document.querySelectorAll('.public-form').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = form.querySelector('.form-feedback');
    const button = form.querySelector('[type="submit"]');
    if (!form.reportValidity()) {
      status.textContent = 'Submission failed Fields must be filled.';
      return;
    }
    const security = window.SecurityValidator;
    const field = (name) => form.elements.namedItem(name)?.value?.trim() || '';
    const email = field('email');
    const contact = field('contactNumber');
    const textFields = ['name', 'message', 'servicePerformed', 'requestedService']
      .map(field).filter(Boolean);
    if (security?.isDisposableEmail(email)) {
      status.textContent = 'Please use a permanent email address.';
      form.elements.namedItem('email')?.focus();
      return;
    }
    if (contact && security && !security.isValidPHMobileNumber(contact)) {
      status.textContent = 'Enter a valid Philippine mobile number, such as 09XXXXXXXXX or +639XXXXXXXXX.';
      form.elements.namedItem('contactNumber')?.focus();
      return;
    }
    if (security && textFields.some((value) => security.containsInappropriateContent(value))) {
      status.textContent = 'Please remove inappropriate language before submitting.';
      return;
    }
    const attachment = form.elements.namedItem('attachment')?.files?.[0];
    if (attachment && security) {
      const result = security.validateUploadedPhoto(attachment);
      if (!result.valid) {
        status.textContent = result.message;
        return;
      }
    }
    button.disabled = true;
    status.textContent = 'Submitting…';
    try {
      const body = new FormData(form);
      body.set('kind', form.dataset.kind);
      const response = await fetch('api/public-submission.php', { method: 'POST', body });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Submission failed Fields must be filled.');
      status.textContent = `Thank you. Your ticket number is ${result.ticketNumber}.${result.emailSent ? ' A confirmation email was sent.' : ' We saved your request, but could not send the confirmation email.'}`;
      form.reset();
    } catch (error) {
      status.textContent = error.message || 'Your submission could not be sent. Please try again.';
    } finally {
      button.disabled = false;
    }
  });
});
