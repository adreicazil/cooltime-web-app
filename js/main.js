import { collection, onSnapshot } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { auth, authReady, db } from './firebase-config.js';
import { updateAvailableSlots } from './slot-availability.js';

const bookingModalElement = document.getElementById('guest-booking-modal');
const paymentModalElement = document.getElementById('guest-payment-modal');
const bookingForm = document.getElementById('guest-booking-form');
const inquiryModalElement = document.getElementById('inquiry-form-modal');
const registeredAccountLink = document.getElementById('noticeRegisteredAccountLink');

registeredAccountLink?.addEventListener('click', async (event) => {
  event.preventDefault();
  try {
    await authReady;
    window.location.assign(auth.currentUser ? 'customer-dashboard.html' : 'login.html');
  } catch (error) {
    console.error('CoolTime authentication state could not be checked:', error);
    window.location.assign('login.html');
  }
});

const serviceSelects = [
  document.getElementById('guest-service-select'),
  document.getElementById('serviceType'),
].filter(Boolean);

function renderBookableServices(snapshot) {
  const services = snapshot.docs
    .map((serviceDoc) => ({ id: serviceDoc.id, ...serviceDoc.data() }))
    .filter((service) => ['active', 'available'].includes(String(service.status || '').trim().toLowerCase()))
    .filter((service) => typeof service.name === 'string' && service.name.trim() !== '')
    .sort((a, b) => a.name.localeCompare(b.name));

  serviceSelects.forEach((select) => {
    const selectedId = select.value;
    const options = [new Option('Select a Service', '')];
    services.forEach((service) => options.push(new Option(service.name.trim(), service.id)));
    select.replaceChildren(...options);
    select.disabled = services.length === 0;
    if (services.some((service) => service.id === selectedId)) select.value = selectedId;
  });
}

if (serviceSelects.length) {
  onSnapshot(collection(db, 'services'), renderBookableServices, (error) => {
    console.error('CoolTime service catalog could not be loaded:', error);
    serviceSelects.forEach((select) => {
      select.replaceChildren(new Option('Select a Service', ''));
      select.disabled = true;
    });
  });
}

if (inquiryModalElement) {
  const openInquiryLink = document.querySelector('[data-open-inquiry]');
  openInquiryLink?.addEventListener('click', (event) => {
    if (!window.bootstrap?.Modal) return;
    event.preventDefault();
    window.bootstrap.Modal.getOrCreateInstance(inquiryModalElement).show();
    history.replaceState(null, '', '#inquiry-form-modal');
  });

  if (window.location.hash === '#inquiry-form-modal' && window.bootstrap?.Modal) {
    window.bootstrap.Modal.getOrCreateInstance(inquiryModalElement).show();
  }
}

if (bookingModalElement && paymentModalElement && bookingForm) {
  const bookingModal = window.bootstrap?.Modal.getOrCreateInstance(bookingModalElement);
  const paymentModal = window.bootstrap?.Modal.getOrCreateInstance(paymentModalElement);
  const otcModalElement = document.getElementById('otc-receipt-modal');
  const otcModal = otcModalElement ? window.bootstrap?.Modal.getOrCreateInstance(otcModalElement) : null;
  const serviceSelect = document.getElementById('guest-service-select');
  const dateInput = document.getElementById('guest-date');
  const branchInput = document.getElementById('guest-branch-select');
  const slotSelect = document.getElementById('guest-time-slot');

  if (bookingModal && paymentModal) {
    dateInput.min = new Date().toLocaleDateString('en-CA');
    updateAvailableSlots(dateInput, branchInput, slotSelect);
    dateInput.addEventListener('change', () => updateAvailableSlots(dateInput, branchInput, slotSelect));
    branchInput.addEventListener('change', () => updateAvailableSlots(dateInput, branchInput, slotSelect));

    const openForCard = (card) => {
      const title = card.dataset.bookableService || card.querySelector('h3')?.textContent.trim();
      const matchingService = [...serviceSelect.options].find((option) => option.textContent.trim() === title);
      if (matchingService) serviceSelect.value = matchingService.value;
      document.getElementById('guest-booking-notice').textContent = '';
      bookingModal.show();
    };

    document.querySelectorAll('.service-card, .package-card').forEach((card) => {
      card.addEventListener('click', (event) => {
        if (event.target.closest('a,button,input,select,textarea')) return;
        openForCard(card);
      });
      card.addEventListener('keydown', (event) => {
        if (event.target !== card || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        openForCard(card);
      });
    });

    bookingForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const notice = document.getElementById('guest-booking-notice');
      if (!slotSelect?.value || slotSelect.disabled || slotSelect.selectedOptions[0]?.disabled) {
        notice.textContent = 'Please select an available time slot before continuing.';
        slotSelect?.focus();
        return;
      }
      if (!bookingForm.checkValidity()) {
        notice.textContent = 'Please complete all required booking details before continuing.';
        bookingForm.reportValidity();
        return;
      }
      const security = window.SecurityValidator;
      const email = bookingForm.elements.namedItem('email')?.value?.trim() || '';
      const name = bookingForm.elements.namedItem('name')?.value?.trim() || '';
      if (security?.isDisposableEmail(email)) {
        notice.textContent = 'Please use a permanent email address.';
        bookingForm.elements.namedItem('email')?.focus();
        return;
      }
      if (security?.containsInappropriateContent(name)) {
        notice.textContent = 'Please enter a name without inappropriate language.';
        bookingForm.elements.namedItem('name')?.focus();
        return;
      }
      document.getElementById('guest-payment-notice').textContent = '';
      bookingModal.hide();
      paymentModal.show();
    });

    document.querySelectorAll('[data-payment-method]').forEach((button) => {
      button.addEventListener('click', async () => {
        const notice = document.getElementById('guest-payment-notice');
        const buttons = [...paymentModalElement.querySelectorAll('[data-payment-method]')];
        const selectedMethod = String(button.dataset.paymentMethod || '').toLowerCase().replaceAll('-', '_');
        const isCash = ['cash', 'cash_otc', 'otc'].includes(selectedMethod);
        buttons.forEach((item) => { item.disabled = true; });
        notice.textContent = 'Saving your booking…';
        try {
          if (!bookingForm.checkValidity() || !slotSelect?.value || slotSelect.disabled || slotSelect.selectedOptions[0]?.disabled) {
            buttons.forEach((item) => { item.disabled = false; });
            paymentModal.hide(); bookingModal.show();
            const bookingNotice = document.getElementById('guest-booking-notice');
            if (!slotSelect?.value || slotSelect.disabled || slotSelect.selectedOptions[0]?.disabled) {
              bookingNotice.textContent = 'Please select an available time slot before continuing.';
              slotSelect?.focus();
            } else {
              bookingNotice.textContent = 'Please complete all required booking details before continuing.';
              bookingForm.reportValidity();
            }
            return;
          }
          const form = new FormData(bookingForm);
          const bookingPayload = {
            name: form.get('name'), email: form.get('email'), service: form.get('service'), branch: form.get('branch'),
            date: form.get('date'), timeSlot: slotSelect.value, plateNumber: form.get('plateNumber'),
            vehicleMake: form.get('vehicleMake'), vehicleModel: form.get('vehicleModel'), vehicleYear: form.get('vehicleYear'),
            paymentMethod: isCash ? 'cash_otc' : 'e_wallet',
          };
          const phoneInput = bookingForm.elements.namedItem('phone');
          if (phoneInput?.value?.trim()) bookingPayload.phone = phoneInput.value.trim();
          const response = await fetch('api/create-walkin-booking.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(bookingPayload),
          });
          const booking = await response.json();
          if (!response.ok || !booking.ok) throw new Error(booking.message || 'Booking could not be saved.');
          if (isCash) {
            document.getElementById('otc-reference').textContent = booking.referenceNumber;
            document.getElementById('otc-customer').textContent = booking.customerName;
            document.getElementById('otc-branch').textContent = booking.branch;
            document.getElementById('otc-service').textContent = booking.serviceName;
            document.getElementById('otc-amount').textContent = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(booking.amount);
            const qr = document.getElementById('otc-qrcode');
            qr.replaceChildren();
            if (!window.QRCode) throw new Error('The receipt QR code could not be loaded. Please keep your reference: ' + booking.referenceNumber);
            new window.QRCode(qr, { text: booking.referenceNumber, width: 200, height: 200, correctLevel: window.QRCode.CorrectLevel.M });
            paymentModal.hide(); otcModal?.show();
            return;
          }
          notice.textContent = 'Opening secure PayMongo checkout…';
          const checkoutResponse = await fetch('api/create-checkout-session.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ invoiceId: booking.invoiceId }),
          });
          const checkout = await checkoutResponse.json();
          if (!checkoutResponse.ok || !checkout.ok || !checkout.checkoutUrl) throw new Error(checkout.message || 'Payment checkout could not be started.');
          window.location.href = checkout.checkoutUrl;
        } catch (error) {
          notice.textContent = error instanceof Error ? error.message : 'Payment could not be started. Please try again.';
          buttons.forEach((item) => { item.disabled = false; });
        }
      });
    });
  }
}
