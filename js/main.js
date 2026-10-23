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
      if (!bookingForm.checkValidity()) {
        bookingForm.reportValidity();
        return;
      }
      const security = window.SecurityValidator;
      const email = bookingForm.elements.namedItem('email')?.value?.trim() || '';
      const name = bookingForm.elements.namedItem('name')?.value?.trim() || '';
      const notice = document.getElementById('guest-booking-notice');
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
      // The payment UI is prepared here. Booking/payment must be created by the
      // server so guest data and PayMongo credentials are never trusted in-browser.
      document.getElementById('guest-payment-notice').textContent =
        'Guest checkout is not enabled on the server yet. Please sign in and book from your customer dashboard.';
      bookingModal.hide();
      paymentModal.show();
    });

    document.querySelectorAll('[data-payment-method]').forEach((button) => {
      button.addEventListener('click', () => {
        document.getElementById('guest-payment-notice').textContent =
          'Payment could not be started because guest checkout is not enabled on the server.';
      });
    });
  }
}
