import { setActiveNavigation, preventPlaceholderForms } from './ui.js';
import { createBooking } from './firestore.js';
import { requireRole } from './auth-guard.js';
import { createWebInvoiceAndCheckout } from './payments.js';

setActiveNavigation();

const bookingForm = document.getElementById('bookingForm');

if (bookingForm) {
  const appointmentDate = document.getElementById('appointmentDate');
  appointmentDate.min = new Date().toISOString().slice(0, 10);

  requireRole(['customer']).then((access) => {
    if (!access) return;
    bookingForm.removeAttribute('hidden');
    const emailInput = bookingForm.querySelector('#email');
    if (emailInput && access.user.email) {
      emailInput.value = access.user.email;
      emailInput.readOnly = true;
    }
  }).catch((error) => {
    console.error('CoolTime booking authorization error:', error);
    window.location.replace('login.html');
  });

  bookingForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(bookingForm);
    try {
      const access = await requireRole(['customer']);
      if (!access) return;
      const { user } = access;
      if (formData.get('email').trim().toLowerCase() !== user.email.toLowerCase()) {
        throw new Error('Use the email address registered to your account.');
      }
      const result = await createBooking({
        customerId: user.uid,
        customerEmail: user.email,
        branchId: formData.get('branchId'),
        serviceId: formData.get('serviceType'),
        date: formData.get('appointmentDate'),
        timeSlot: formData.get('timeSlot'),
        vehicle: {
          plateNumber: formData.get('plateNumber'),
          make: formData.get('vehicleMake'),
          model: formData.get('vehicleModel'),
          year: formData.get('vehicleYear')
        }
      });
      let invoice;
      try { invoice = await createWebInvoiceAndCheckout(result.bookingId, user); }
      catch (error) {
        alert(`Booking ${result.bookingId} was saved, but invoice creation failed: ${error.message}`);
        return;
      }
      if (invoice.checkoutUrl) {
        window.location.assign(invoice.checkoutUrl);
        return;
      }
      alert(`Booking submitted. Invoice: ${invoice.invoiceId}. ${invoice.checkoutError || 'Checkout is available from your dashboard.'}`);
      bookingForm.reset();
    } catch (error) {
      alert(`Booking failed: ${error.message}`);
    }
  });
} else {
  // Pages without Firebase forms can keep the placeholder behavior for search forms.
  preventPlaceholderForms();
}
