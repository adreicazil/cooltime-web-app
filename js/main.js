import { collection, getDocs } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { auth, authReady, db } from './firebase-config.js';
import { updateAvailableSlots } from './slot-availability.js';
import { normalizeBranchLabel } from './branch-utils.js';

const bookingModalElement = document.getElementById('guest-booking-modal');
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
const fallbackBookableServices = [
  { id: 'svc_cleaning', name: 'General AC Cleaning' },
  { id: 'svc_freon', name: 'Freon Charging' },
];

function populateBookableServiceSelects(services) {
  const bookableServices = services
    .filter((service) => ['active', 'available'].includes(String(service.status || '').trim().toLowerCase()))
    .filter((service) => typeof service.name === 'string' && service.name.trim() !== '')
    .sort((a, b) => a.name.localeCompare(b.name));

  serviceSelects.forEach((select) => {
    const selectedId = select.value;
    const options = [new Option('Select a Service', '')];
    bookableServices.forEach((service) => options.push(new Option(service.name.trim(), service.id)));
    select.replaceChildren(...options);
    select.disabled = bookableServices.length === 0;
    if (bookableServices.some((service) => service.id === selectedId)) select.value = selectedId;
  });
}

const SERVICE_CATALOG_CACHE_KEY = 'cooltime.services.catalog.v1';
const SERVICE_CATALOG_CACHE_TTL_MS = 5 * 60 * 1000;

async function loadServiceCatalog() {
  try {
    const cached = JSON.parse(sessionStorage.getItem(SERVICE_CATALOG_CACHE_KEY) || 'null');
    if (Array.isArray(cached?.services) && Date.now() - Number(cached.savedAt) < SERVICE_CATALOG_CACHE_TTL_MS) {
      return cached.services;
    }
  } catch { /* Ignore unavailable or malformed session cache. */ }

  const snapshot = await getDocs(collection(db, 'services'));
  const services = snapshot.docs.map((serviceDoc) => ({ id: serviceDoc.id, ...serviceDoc.data() }));
  try { sessionStorage.setItem(SERVICE_CATALOG_CACHE_KEY, JSON.stringify({ savedAt: Date.now(), services })); }
  catch { /* The catalog still works when session storage is unavailable. */ }
  return services;
}

if (serviceSelects.length) {
  loadServiceCatalog().then(populateBookableServiceSelects).catch((error) => {
    console.error('CoolTime service catalog could not be loaded:', error);
    populateBookableServiceSelects(fallbackBookableServices.map((service) => ({ ...service, status: 'active' })));
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

if (bookingModalElement && bookingForm) {
  const bookingModal = window.bootstrap?.Modal.getOrCreateInstance(bookingModalElement);
  const serviceSelect = document.getElementById('guest-service-select');
  const dateInput = document.getElementById('guest-date');
  const branchInput = document.getElementById('guest-branch-select');
  const slotSelect = document.getElementById('guest-time-slot');

  if (bookingModal) {
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

    bookingForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const notice = document.getElementById('guest-booking-notice');
      if (!slotSelect?.value || slotSelect.disabled || slotSelect.selectedOptions[0]?.disabled) { notice.textContent = 'Please select an available time slot before continuing.'; slotSelect?.focus(); return; }
      if (!bookingForm.checkValidity()) { notice.textContent = 'Please complete all required booking details before continuing.'; bookingForm.reportValidity(); return; }
      const security = window.SecurityValidator;
      const email = bookingForm.elements.namedItem('email')?.value?.trim() || '';
      const name = bookingForm.elements.namedItem('name')?.value?.trim() || '';
      if (security?.isDisposableEmail(email)) { notice.textContent = 'Please use a permanent email address.'; bookingForm.elements.namedItem('email')?.focus(); return; }
      if (security?.containsInappropriateContent(name)) { notice.textContent = 'Please enter a name without inappropriate language.'; bookingForm.elements.namedItem('name')?.focus(); return; }
      const submitButton = bookingForm.querySelector('[type="submit"]');
      const form = new FormData(bookingForm);
      const payload = { name: form.get('name'), email: form.get('email'), service: form.get('service'), branch: normalizeBranchLabel(form.get('branch'), ''), date: form.get('date'), timeSlot: slotSelect.value, plateNumber: form.get('plateNumber'), vehicleMake: form.get('vehicleMake'), vehicleModel: form.get('vehicleModel'), vehicleYear: form.get('vehicleYear') };
      const phone = bookingForm.elements.namedItem('phone');
      if (phone?.value?.trim()) payload.phone = phone.value.trim();
      if (submitButton) submitButton.disabled = true;
      notice.textContent = 'Submitting your walk-in booking...';
      try {
        const response = await fetch('api/create-walkin-booking.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'Booking could not be saved.');
        bookingModal.hide();
        window.alert(`Walk-In Booking Confirmed\n\nWalk-In Reference ID: ${result.referenceNumber}\n\nPlease proceed to the service bay. Payment is due after the service is marked completed.`);
        bookingForm.reset(); notice.textContent = '';
        await updateAvailableSlots(dateInput, branchInput, slotSelect);
      } catch (error) { notice.textContent = error instanceof Error ? error.message : 'Booking could not be submitted. Please try again.'; }
      finally { if (submitButton) submitButton.disabled = false; }
    });
  }
}
