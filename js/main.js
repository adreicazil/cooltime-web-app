import { collection, onSnapshot } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
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
function populateBookableServiceSelects(services) {
  const bookableServices = services
    .filter((service) => ['active', 'available'].includes(String(service.status || '').trim().toLowerCase()))
    .filter((service) => typeof service.name === 'string' && service.name.trim() !== '')
    .sort((a, b) => a.name.localeCompare(b.name));

  serviceSelects.forEach((select) => {
    const selectedId = select.value;
    const options = [new Option('Select a Service', '')];
    bookableServices.forEach((service) => {
      const price = Number(service.price) || 0;
      const duration = String(service.duration || '').trim();
      const label = `${service.name.trim()} — PHP ${new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(price)}${duration ? ` · ${duration}` : ''}`;
      const option = new Option(label, service.id);
      option.dataset.serviceId = service.id;
      option.dataset.serviceName = service.name.trim();
      option.dataset.price = String(price);
      option.title = String(service.description || '');
      options.push(option);
    });
    select.replaceChildren(...options);
    select.disabled = bookableServices.length === 0;
    if (bookableServices.some((service) => service.id === selectedId)) select.value = selectedId;
  });
}

function renderPublicServiceCards(services) {
  const target = document.getElementById('publicServiceCatalog');
  if (!target) return;
  const bookable = services
    .filter((service) => ['active', 'available'].includes(String(service.status || '').trim().toLowerCase()))
    .filter((service) => typeof service.name === 'string' && service.name.trim() !== '')
    .sort((a, b) => a.name.localeCompare(b.name));
  if (!bookable.length) {
    target.replaceChildren(Object.assign(document.createElement('p'), { textContent: 'No services are currently available.' }));
    return;
  }
  target.replaceChildren(...bookable.map((service) => {
    const card = document.createElement('article');
    card.className = 'service-card package-card package-card-bg';
    card.tabIndex = 0;
    card.setAttribute('role', 'button');
    card.dataset.serviceId = service.id;
    card.dataset.serviceName = service.name.trim();
    card.dataset.price = String(Number(service.price) || 0);
    card.setAttribute('aria-label', `Book ${service.name.trim()}`);
    const overlay = document.createElement('div'); overlay.className = 'package-overlay';
    const title = document.createElement('h3'); title.textContent = service.name.trim();
    const description = document.createElement('p'); description.textContent = service.description || '';
    const duration = document.createElement('p'); duration.textContent = service.duration ? `Duration: ${service.duration}` : '';
    const price = document.createElement('strong'); price.className = 'package-price';
    price.textContent = `PHP ${new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(service.price) || 0)}`;
    overlay.append(title, description, duration, price); card.append(overlay); return card;
  }));
}

if (serviceSelects.length) {
  onSnapshot(collection(db, 'services'), (snapshot) => {
    const services = snapshot.docs.map((serviceDoc) => ({ id: serviceDoc.id, ...serviceDoc.data() }));
    populateBookableServiceSelects(services);
    renderPublicServiceCards(services);
  }, (error) => {
    console.error('CoolTime service catalog could not be loaded:', error);
    populateBookableServiceSelects([]);
    renderPublicServiceCards([]);
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
      const selectedId = card.dataset.serviceId;
      if (selectedId && [...serviceSelect.options].some((option) => option.value === selectedId)) serviceSelect.value = selectedId;
      document.getElementById('guest-booking-notice').textContent = '';
      bookingModal.show();
    };

    document.getElementById('publicServiceCatalog')?.addEventListener('click', (event) => {
      const card = event.target.closest('.service-card[data-service-id]');
      if (card) openForCard(card);
    });
    document.getElementById('publicServiceCatalog')?.addEventListener('keydown', (event) => {
      const card = event.target.closest('.service-card[data-service-id]');
      if (!card || !['Enter', ' '].includes(event.key)) return;
      event.preventDefault(); openForCard(card);
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
      const selectedService = serviceSelect.selectedOptions[0];
      const payload = { name: form.get('name'), email: form.get('email'), service: selectedService?.dataset.serviceId || form.get('service'), serviceId: selectedService?.dataset.serviceId || form.get('service'), serviceName: selectedService?.dataset.serviceName || selectedService?.textContent.trim(), price: Number(selectedService?.dataset.price || 0), branch: normalizeBranchLabel(form.get('branch'), ''), date: form.get('date'), timeSlot: slotSelect.value, plateNumber: form.get('plateNumber'), vehicleMake: form.get('vehicleMake'), vehicleModel: form.get('vehicleModel'), vehicleYear: form.get('vehicleYear') };
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
