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
      const duration = Number(service.duration ?? service.estimatedDuration) || (/basic|single/i.test(service.name) ? 2 : /dual|double/i.test(service.name) ? 3 : 2);
      const option = new Option(`${service.name.trim()} — ${duration} hrs`, service.id);
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

if (serviceSelects.length) {
  onSnapshot(collection(db, 'services'), (snapshot) => {
    const services = snapshot.docs.map((serviceDoc) => ({ id: serviceDoc.id, ...serviceDoc.data() }));
    populateBookableServiceSelects(services);
  }, (error) => {
    console.error('CoolTime service catalog could not be loaded:', error);
    populateBookableServiceSelects([]);
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
  const vehicleType = document.getElementById('guest-vehicle-type');
  const vehicleMake = document.getElementById('guest-vehicle-make');
  const vehicleModel = document.getElementById('guest-vehicle-model');
  const vehicleYear = document.getElementById('guest-vehicle-year');
  const vehicleCatalog = {
    single: { Toyota: ['Vios', 'Altis', 'Camry', 'Hilux', 'Others'], Mitsubishi: ['Mirage', 'Lancer EX', 'Strada', 'Others'], Honda: ['Civic', 'City', 'Brio', 'Others'], Nissan: ['Navara', 'Juke', 'Others'], Isuzu: ['D-Max', 'Others'], Others: ['Others'] },
    dual: { Toyota: ['Innova', 'Fortuner', 'Avanza', 'Hiace', 'Veloz', 'Others'], Mitsubishi: ['Montero', 'Xpander', 'L300', 'Others'], Honda: ['CR-V', 'Mobilio', 'BR-V', 'Others'], Nissan: ['Terra', 'NV350', 'Others'], Isuzu: ['mu-X', 'Traviz', 'Crosswind', 'Alterra', 'Others'], Others: ['Others'] },
  };
  const currentYear = new Date().getFullYear();
  vehicleYear.replaceChildren(new Option('Select year', ''), ...Array.from({ length: currentYear - 1989 }, (_, index) => new Option(String(currentYear - index), String(currentYear - index))));
  vehicleType.addEventListener('change', () => {
    const brands = vehicleCatalog[vehicleType.value] || {};
    vehicleMake.replaceChildren(new Option('Select brand', ''), ...Object.keys(brands).map((brand) => new Option(brand, brand)));
    vehicleMake.disabled = !vehicleType.value;
    vehicleModel.replaceChildren(new Option('Select model', ''));
    vehicleModel.disabled = true;
    document.getElementById('guest-aircon-type').textContent = vehicleType.value === 'single' ? 'Single Aircon' : vehicleType.value === 'dual' ? 'Dual Aircon' : 'Select a vehicle type';
    const matchingService = [...serviceSelect.options].find((option) => vehicleType.value === 'single'
      ? /single/i.test(option.dataset.serviceName || '')
      : /dual|double/i.test(option.dataset.serviceName || ''));
    if (matchingService) serviceSelect.value = matchingService.value;
  });
  vehicleMake.addEventListener('change', () => {
    const models = vehicleCatalog[vehicleType.value]?.[vehicleMake.value] || [];
    vehicleModel.replaceChildren(new Option('Select model', ''), ...models.map((model) => new Option(model, model)));
    vehicleModel.disabled = !vehicleMake.value;
  });
  bookingForm.elements.namedItem('plateNumber').addEventListener('input', (event) => {
    event.target.value = event.target.value.toUpperCase();
  });

  if (bookingModal) {
    dateInput.min = new Date().toLocaleDateString('en-CA');
    updateAvailableSlots(dateInput, branchInput, slotSelect);
    dateInput.addEventListener('change', () => updateAvailableSlots(dateInput, branchInput, slotSelect));
    branchInput.addEventListener('change', () => updateAvailableSlots(dateInput, branchInput, slotSelect));

    const openForCard = (card) => {
      const selectedId = card.dataset.serviceId;
      const serviceName = card.dataset.serviceName || card.dataset.bookableService || '';
      const option = [...serviceSelect.options].find((item) => (selectedId && item.value === selectedId)
        || (serviceName && item.dataset.serviceName === serviceName));
      if (option) serviceSelect.value = option.value;
      document.getElementById('guest-booking-notice').textContent = '';
      bookingModal.show();
    };

    document.querySelector('.services-grid-container')?.addEventListener('click', (event) => {
      const card = event.target.closest('.service-card[data-bookable-service], .service-card[data-service-id]');
      if (card) openForCard(card);
    });
    document.querySelector('.services-grid-container')?.addEventListener('keydown', (event) => {
      const card = event.target.closest('.service-card[data-bookable-service], .service-card[data-service-id]');
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
      const plateInput = bookingForm.elements.namedItem('plateNumber');
      plateInput.value = plateInput.value.trim().toUpperCase().replace(/\s+/g, ' ');
      if (!/^[A-Z]{3} ?[0-9]{3,4}$/.test(plateInput.value)) { notice.textContent = 'Enter a valid Philippine plate number, such as ABC 1234 or ABC 123.'; plateInput.focus(); return; }
      const selectedName = serviceSelect.selectedOptions[0]?.dataset.serviceName || '';
      if ((vehicleType.value === 'dual' && /single/i.test(selectedName)) || (vehicleType.value === 'single' && /dual|double/i.test(selectedName))) { notice.textContent = 'This service package does not match the vehicle aircon type. Choose a matching Single or Dual Aircon package.'; serviceSelect.focus(); return; }
      if (security?.isDisposableEmail(email)) { notice.textContent = 'Please use a permanent email address.'; bookingForm.elements.namedItem('email')?.focus(); return; }
      if (security?.containsInappropriateContent(name)) { notice.textContent = 'Please enter a name without inappropriate language.'; bookingForm.elements.namedItem('name')?.focus(); return; }
      const submitButton = bookingForm.querySelector('[type="submit"]');
      const form = new FormData(bookingForm);
      const selectedService = serviceSelect.selectedOptions[0];
      const payload = { name: form.get('name'), email: form.get('email'), service: selectedService?.dataset.serviceId || form.get('service'), serviceId: selectedService?.dataset.serviceId || form.get('service'), serviceName: selectedService?.dataset.serviceName || selectedService?.textContent.trim(), price: Number(selectedService?.dataset.price || 0), branch: normalizeBranchLabel(form.get('branch'), ''), date: form.get('date'), timeSlot: slotSelect.value, plateNumber: plateInput.value, vehicleType: vehicleType.value, airconType: vehicleType.value, vehicleMake: form.get('vehicleMake'), make: form.get('vehicleMake'), vehicleModel: form.get('vehicleModel'), model: form.get('vehicleModel'), vehicleYear: form.get('vehicleYear'), year: form.get('vehicleYear') };
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
