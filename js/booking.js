import { collection, doc, getDoc, getDocs, getDocsFromServer, onSnapshot, query, where } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { requireRole } from './auth-guard.js';
import { db } from './firebase-config.js';
import { updateAvailableSlots } from './slot-availability.js';
import { createBooking } from './firestore.js';
import { createWebInvoice } from './payments.js';

const form = document.getElementById('bookingForm');
const branchInput = document.getElementById('branchId');
const dateInput = document.getElementById('appointmentDate');
const slotInput = document.getElementById('timeSlot');
const submitButton = document.getElementById('bookBtn');
let customerProfile = null;

const serviceTypeSelect = document.getElementById('serviceType');
if (serviceTypeSelect) {
  onSnapshot(collection(db, 'services'), (snapshot) => {
    const selectedId = serviceTypeSelect.value;
    const services = snapshot.docs
      .map((serviceDoc) => ({ id: serviceDoc.id, ...serviceDoc.data() }))
      .filter((service) => ['active', 'available'].includes(String(service.status || '').trim().toLowerCase()))
      .filter((service) => typeof service.name === 'string' && service.name.trim() !== '')
      .sort((a, b) => a.name.localeCompare(b.name));
    serviceTypeSelect.replaceChildren(
      new Option('Select a Service', ''),
      ...services.map((service) => new Option(service.name.trim(), service.id))
    );
    serviceTypeSelect.disabled = services.length === 0;
    if (services.some((service) => service.id === selectedId)) serviceTypeSelect.value = selectedId;
  }, (error) => {
    console.error('CoolTime service catalog could not be loaded:', error);
    serviceTypeSelect.replaceChildren(new Option('Select a Service', ''));
    serviceTypeSelect.disabled = true;
  });
}

function resetSlots(message = 'Select branch and date first') {
  slotInput.replaceChildren(new Option(message, ''));
  slotInput.disabled = true;
  submitButton.disabled = true;
  const notice = document.getElementById('availabilityNotice');
  if (notice) notice.textContent = message.startsWith('No available slots') ? message : '';
}

function timeMinutes(value) {
  const text = String(value || '').trim();
  const twentyFourHour = /^(\d{1,2}):(\d{2})$/.exec(text);
  if (twentyFourHour) {
    const hour = Number(twentyFourHour[1]);
    const minute = Number(twentyFourHour[2]);
    return hour < 24 && minute < 60 ? hour * 60 + minute : null;
  }
  const twelveHour = /^(\d{1,2}):(\d{2})\s*(AM|PM)$/i.exec(text);
  if (!twelveHour) return null;
  const hour = Number(twelveHour[1]);
  const minute = Number(twelveHour[2]);
  if (hour < 1 || hour > 12 || minute > 59) return null;
  return ((hour % 12) + (twelveHour[3].toUpperCase() === 'PM' ? 12 : 0)) * 60 + minute;
}

function displayTime(minutes) {
  const hour24 = Math.floor(minutes / 60);
  const hour12 = hour24 % 12 || 12;
  return `${String(hour12).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')} ${hour24 < 12 ? 'AM' : 'PM'}`;
}

function configuredSlots(branch, selectedDate) {
  const hours = branch.operatingHours || {};
  const open = timeMinutes(hours.open || hours.start || branch.openingTime || branch.openTime);
  const close = timeMinutes(hours.close || hours.end || branch.closingTime || branch.closeTime);
  const duration = Number(branch.slotDurationMinutes);
  if (open === null || close === null || !Number.isInteger(duration) || duration <= 0 || close <= open) {
    throw new Error('Branch operating hours are not configured. Ask an administrator to run the Firestore initializer.');
  }
  if (selectedDate && Array.isArray(branch.operatingDays) && branch.operatingDays.length) {
    const [year, month, day] = selectedDate.split('-').map(Number);
    const weekday = new Date(year, month - 1, day).toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase();
    if (!branch.operatingDays.map((value) => String(value).toLowerCase()).includes(weekday)) return [];
  }
  const breaks = Array.isArray(hours.breaks) ? hours.breaks.map((item) => ({
    start: timeMinutes(item.start), end: timeMinutes(item.end)
  })).filter((item) => item.start !== null && item.end !== null && item.end > item.start) : [];
  const result = [];
  let start = open;
  // The business closing time is an inclusive final appointment start time.
  while (start <= close) {
    const pause = breaks.find((item) => start < item.end && start + duration > item.start);
    if (pause) {
      start = Math.max(start + 1, pause.end);
      continue;
    }
    result.push(displayTime(start));
    start += duration;
  }
  return result;
}

async function getBranchSchedule(branchId) {
  const aliases = branchId === 'santarosa' ? ['santarosa', 'santa_rosa']
    : branchId === 'cabuyao' ? ['cabuyao'] : [branchId];
  for (const collectionName of ['branches', 'branch']) {
    for (const id of aliases) {
      const snapshot = await getDoc(doc(db, collectionName, id));
      if (snapshot.exists()) return { ...snapshot.data(), branchDocumentId: snapshot.id };
    }
  }
  throw new Error('Branch schedule was not found. Ask an administrator to run the Firestore initializer.');
}

function renderSlots(capacity, slots, occupiedCounts) {
  slotInput.replaceChildren(new Option('Select time', ''));
  slots.forEach((slot) => {
    const remaining = Math.max(0, capacity - Number(occupiedCounts[slot] || 0));
    const option = new Option(`${slot} (${remaining} slot${remaining === 1 ? '' : 's'} left)`, slot);
    option.disabled = remaining === 0;
    slotInput.add(option);
  });
  const hasAvailableSlot = slots.some((slot) => Number(occupiedCounts[slot] || 0) < capacity);
  slotInput.disabled = slots.length === 0 || !hasAvailableSlot;
  submitButton.disabled = slots.length === 0 || !hasAvailableSlot;
  if (slots.length === 0) slotInput.replaceChildren(new Option('No appointment times configured', ''));
  const notice = document.getElementById('availabilityNotice');
  if (notice) notice.textContent = hasAvailableSlot ? '' : 'No available slots for this date. Send an inquiry and we will help arrange your appointment.';
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
}

let billServices = [];
let latestInvoiceSnapshot = null;
let stopInvoiceListener = null;
let runningBillsCurrentPage = 1;
let runningBillsHasInitialSnapshot = false;
let runningBillsKnownIds = new Set();
const billMoney = (amount) => `PHP ${Number(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function renderInvoices(snapshot) {
  latestInvoiceSnapshot = snapshot;
  const invoiceRecords = snapshot.docs.map((item) => ({ id: item.id, data: item.data() }));
  const addedIds = invoiceRecords.filter(({ id }) => !runningBillsKnownIds.has(id)).map(({ id }) => id);
  const justAdded = runningBillsHasInitialSnapshot && addedIds.length > 0;
  runningBillsKnownIds = new Set(invoiceRecords.map(({ id }) => id));
  runningBillsHasInitialSnapshot = true;
  const pendingTimestamp = Date.now();
  const createdAtMillis = (invoice) => {
    const value = Object.hasOwn(invoice, 'createdAt') ? invoice.createdAt : invoice.created_at;
    if (value == null) return pendingTimestamp;
    if (typeof value?.toMillis === 'function') return value.toMillis();
    if (typeof value?.toDate === 'function') return value.toDate().getTime();
    if (typeof value === 'number') return Math.abs(value) < 1e12 ? value * 1000 : value;
    if (typeof value === 'string') {
      const numeric = Number(value);
      if (value.trim() && Number.isFinite(numeric)) return Math.abs(numeric) < 1e12 ? numeric * 1000 : numeric;
      const parsed = Date.parse(value);
      if (Number.isFinite(parsed)) return parsed;
    }
    return pendingTimestamp;
  };
  invoiceRecords.sort((a, b) => createdAtMillis(a.data) - createdAtMillis(b.data));
  const totalPages = Math.max(1, Math.ceil(invoiceRecords.length / PAGE_SIZE));
  if (justAdded) runningBillsCurrentPage = totalPages;
  else runningBillsCurrentPage = Math.min(Math.max(1, runningBillsCurrentPage), totalPages);
  const pagination = document.getElementById('customerInvoicesPagination');
  if (pagination) pagination.innerHTML = renderCustomerPagination(runningBillsCurrentPage, totalPages);
  window.dispatchEvent(new CustomEvent('customer:invoices', { detail: invoiceRecords.map(({ id, data }) => ({ ...data, id })) }));
  const list = document.getElementById('customerInvoices');
  if (!list) return;
  if (!invoiceRecords.length) { list.textContent = 'No running bills yet. A bill will appear after you submit a booking.'; return; }
  const firstIndex = (runningBillsCurrentPage - 1) * PAGE_SIZE;
  list.innerHTML = invoiceRecords.slice(firstIndex, firstIndex + PAGE_SIZE).map(({ id, data: invoice }, index) => {
    const amount = Number(invoice.runningTotal ?? invoice.totalAmount ?? invoice.finalAmount ?? 0);
    const rawStatus = String(invoice.status || 'pending');
    const serviceStatus = String(invoice.serviceStatus || 'pending').toLowerCase();
    const downpayment = serviceStatus === 'completed' ? Math.min(amount, Number(invoice.downpaymentAmount || 0)) : 0;
    const balanceDue = Number(invoice.balance_due ?? invoice.balanceDue ?? (amount - downpayment));
    const cancelled = ['cancelled', 'canceled'].includes(rawStatus.toLowerCase());
    const paid = rawStatus.toLowerCase() === 'paid' || (balanceDue <= 0 && !cancelled);
    const status = cancelled ? 'Cancelled' : paid ? 'Paid' : rawStatus;
    const statusKey = cancelled ? 'cancelled' : paid ? 'paid' : rawStatus.toLowerCase();
    const closed = paid || cancelled;
    const balance = paid || cancelled ? 0 : Math.max(0, balanceDue);
    const lines = Array.isArray(invoice.serviceDetails) ? invoice.serviceDetails : [];
    const referenceNumber = invoice.referenceNumber || invoice.invoiceNumber || id;
    const lineHtml = lines.map((line) => `<li>${escapeHtml(line.name || line.id)} <span>x ${Number(line.quantity || 1)} · ${billMoney(line.lineTotal ?? Number(line.price || 0) * Number(line.quantity || 1))}</span></li>`).join('');
    const addControl = closed ? '' : `<div class="bill-add-service"><label for="add-service-${escapeHtml(id)}">Add a service</label><div class="bill-add-controls" style="display: flex; align-items: center; gap: 8px; margin-top: 8px;"><select id="add-service-${escapeHtml(id)}" class="bill-service-select"><option value="">Choose service</option>${billServices.map((service) => `<option value="${escapeHtml(service.id)}">${escapeHtml(service.name)} - ${billMoney(service.price)}</option>`).join('')}</select><button class="btn btn-secondary bill-add" data-invoice-id="${escapeHtml(id)}" type="button">Add to bill</button></div></div>`;
    const created = invoice.createdAt?.toDate?.() || (invoice.createdAt ? new Date(invoice.createdAt) : null);
    const date = created && !Number.isNaN(created.getTime()) ? created.toISOString().slice(0, 10) : (invoice.date || 'Date unavailable');
    const detailId = `invoice-details-${escapeHtml(id)}`;
    return `<article class="invoice-row running-bill" data-invoice-id="${escapeHtml(id)}"><div class="bill-summary"><span class="bill-index">${firstIndex + index + 1}</span><button class="bill-invoice-toggle" type="button" aria-expanded="false" aria-controls="${detailId}" aria-label="Show details for invoice ${escapeHtml(referenceNumber)}"><span class="invoice-meta-col"><span class="invoice-id-text">${escapeHtml(referenceNumber)}</span><span class="invoice-date-text">${escapeHtml(date)}</span></span></button><strong class="bill-amount">${billMoney(amount)}</strong><span class="bill-status ${escapeHtml(statusKey)}">${escapeHtml(status)}</span>${!closed ? `<button class="btn btn-primary invoice-pay bill-summary-pay" type="button" data-invoice-id="${escapeHtml(id)}">Pay balance</button>` : '<span class="bill-summary-pay-placeholder" aria-hidden="true"></span>'}</div><div class="bill-breakdown-panel" id="${detailId}" aria-hidden="true" inert><p><strong>Reference ID:</strong> ${escapeHtml(referenceNumber)} <button type="button" class="btn btn-secondary btn-sm" data-copy-reference="${escapeHtml(referenceNumber)}">Copy</button></p><ul class="bill-lines">${lineHtml || '<li>No services added.</li>'}</ul><div class="bill-totals"><span>Running total</span><strong>${billMoney(amount)}</strong>${downpayment > 0 ? `<span>Downpayment credited</span><strong>- ${billMoney(downpayment)}</strong>` : ''}<span>Balance due</span><strong>${billMoney(balance)}</strong></div>${addControl}</div></article>`;
  }).join('');
}

document.getElementById('customerInvoices')?.addEventListener('click', async (event) => {
  const toggle = event.target.closest('.bill-invoice-toggle');
  if (toggle) {
    const details = document.getElementById(toggle.getAttribute('aria-controls'));
    const expanded = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!expanded));
    toggle.setAttribute('aria-label', `${expanded ? 'Show' : 'Hide'} details for invoice ${toggle.closest('.running-bill').dataset.invoiceId}`);
    details.classList.toggle('expanded', !expanded);
    details.setAttribute('aria-hidden', String(expanded));
    details.inert = expanded;
    return;
  }
  const addButton = event.target.closest('.bill-add');
  const payButton = event.target.closest('.invoice-pay');
  const button = addButton || payButton;
  if (!button) return;
  button.disabled = true;
  try {
    const access = await requireRole(['customer']);
    if (!access) return;
    if (addButton) {
      const serviceId = addButton.closest('.running-bill').querySelector('.bill-service-select').value;
      if (!serviceId) throw new Error('Choose a service to add to this bill.');
      const response = await fetch('api/add-invoice-service.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await access.user.getIdToken()}` },
        body: JSON.stringify({ invoiceId: addButton.dataset.invoiceId, serviceId })
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to add service.');
    } else {
      const response = await fetch('api/create-checkout-session.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await access.user.getIdToken()}` },
        body: JSON.stringify({ invoiceId: payButton.dataset.invoiceId })
      });
      const result = await response.json();
      if (!response.ok || !result.checkoutUrl) throw new Error(result.message || 'Unable to start payment.');
      window.location.assign(result.checkoutUrl);
    }
  } catch (error) {
    if (/already been paid|already paid|invoice is closed/i.test(error.message || '')) {
      const access = await requireRole(['customer']);
      if (access) {
        try { await refreshInvoicesFromServer(access.user); }
        catch (refreshError) { console.error('Paid invoice refresh failed:', refreshError); }
      }
    }
    alert(error.message);
    button.disabled = false;
  }
});

function loadInvoices(user) {
  const target = document.getElementById('customerInvoices');
  if (!target) return;
  getDocs(collection(db, 'services')).then((snapshot) => {
    billServices = snapshot.docs.map((item) => ({ id: item.id, ...item.data() })).filter((service) => Number(service.price) > 0 && String(service.status || 'active').toLowerCase() === 'active');
    if (latestInvoiceSnapshot) renderInvoices(latestInvoiceSnapshot);
  }).catch((error) => console.error('Service catalog for bills failed:', error));
  stopInvoiceListener?.();
  const invoiceQuery = query(collection(db, 'invoices'), where('customerId', '==', user.uid));
  stopInvoiceListener = onSnapshot(invoiceQuery, renderInvoices, (error) => {
    console.error('Invoice list failed:', error);
    target.textContent = 'Running bills are temporarily unavailable.';
  });
  refreshInvoicesFromServer(user).catch((error) => console.error('Fresh invoice status could not be loaded:', error));
}

async function refreshInvoicesFromServer(user) {
  const snapshot = await getDocsFromServer(query(collection(db, 'invoices'), where('customerId', '==', user.uid)));
  renderInvoices(snapshot);
}

let stopCustomerServiceCatalog = null;
let stopCustomerBookingListeners = [];
let bookingsCurrentPage = 1;
const PAGE_SIZE = 15;
let currentBookingCount = 0;
let rerenderCustomerBookings = null;
let latestSubmittedBookingId = null;
const optimisticBookingRecords = new Map();

function readableServiceFallback(serviceId) {
  return String(serviceId || '').replace(/^svc[_-]?/i, '').replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase()) || 'Service';
}

function bookingCreatedAtMs(booking, pendingTimestamp) {
  const raw = Object.hasOwn(booking, 'createdAt') ? booking.createdAt : (booking.created_at ?? booking.timestamp);
  if (raw == null) return pendingTimestamp;
  if (typeof raw?.toMillis === 'function') return raw.toMillis();
  if (typeof raw?.toDate === 'function') return raw.toDate().getTime();
  if (typeof raw === 'number') return Math.abs(raw) < 1e12 ? raw * 1000 : raw;
  if (typeof raw === 'string') {
    const numeric = Number(raw);
    if (raw.trim() && Number.isFinite(numeric)) return Math.abs(numeric) < 1e12 ? numeric * 1000 : numeric;
    const parsed = Date.parse(raw);
    if (Number.isFinite(parsed)) return parsed;
  }
  return pendingTimestamp;
}

function bookingCreatedDate(booking) {
  const raw = booking.createdAt ?? booking.created_at ?? booking.timestamp;
  let date = null;
  if (raw?.toDate && typeof raw.toDate === 'function') date = raw.toDate();
  else if (typeof raw === 'number') date = new Date(raw);
  else if (typeof raw === 'string' && raw.trim()) date = new Date(raw);
  if (!date || Number.isNaN(date.getTime())) date = new Date();
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
  }).formatToParts(date);
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
  return `${values.year}-${values.month}-${values.day}`;
}

function renderCustomerBookings(records, serviceCatalog, vehicleData, branchCatalog, user, profile, target) {
  const bookingData = records.map((record) => {
    const data = record.data;
    const serviceId = data.serviceId || data.service_id || data.serviceID || '';
    const service = serviceCatalog[serviceId] || {};
    const branchKey = String(data.branchId || data.branch_id || '').toLowerCase().replace(/[- ]/g, '_');
    const branch = branchCatalog[branchKey] || {};
    return {
      ...data, id: record.id, serviceId,
      serviceName: service.name || data.service_name || data.serviceName || readableServiceFallback(serviceId),
      servicePrice: Number(service.price || data.servicePrice || data.service_price || 0),
      branchName: data.branchName || branch.name || ({ cabuyao: 'Cabuyao Branch', santarosa: 'Santa Rosa Branch', santa_rosa: 'Santa Rosa Branch' }[branchKey]) || data.branchId || 'Branch unavailable',
      branchAddress: data.branchAddress || branch.address || 'Address unavailable',
      customerName: profile?.name || profile?.username || 'Customer',
      customerEmail: user.email || profile?.email || 'Email unavailable',
      vehicle: data.vehicle || vehicleData[data.vehicleId || data.vehicle_id] || {}
    };
  });
  window.dispatchEvent(new CustomEvent('customer:bookings', { detail: bookingData }));
  const pagination = document.getElementById('customerBookingsPagination');
  const totalPages = Math.max(1, Math.ceil(bookingData.length / PAGE_SIZE));
  currentBookingCount = bookingData.length;
  bookingsCurrentPage = Math.min(Math.max(1, bookingsCurrentPage), totalPages);
  if (pagination) pagination.innerHTML = renderCustomerPagination(bookingsCurrentPage, totalPages);
  if (!bookingData.length) { target.innerHTML = '<p class="customer-bookings-empty">No bookings yet.</p>'; return; }
  const name = profile?.name || profile?.username || 'Customer';
  const pageBookings = bookingData.slice((bookingsCurrentPage - 1) * PAGE_SIZE, bookingsCurrentPage * PAGE_SIZE);
  target.innerHTML = pageBookings.map((booking, index) => {
    const rawStatus = String(booking.status || 'pending');
    const rawStatusKey = rawStatus.toLowerCase().replace(/[^a-z0-9-]/g, '-');
    const statusKey = rawStatusKey === 'in-progress' ? 'ongoing' : rawStatusKey;
    const status = statusKey === 'ongoing' ? 'Ongoing' : statusKey.charAt(0).toUpperCase() + statusKey.slice(1).replaceAll('-', ' ');
    const branchKey = String(booking.branchId || '').toLowerCase().replace(/[- ]/g, '_');
    const branch = booking.branchName || ({ santarosa: 'Sta. Rosa', santa_rosa: 'Sta. Rosa', cabuyao: 'Cabuyao' }[branchKey]) || booking.branchId || 'Branch unavailable';
    const nestedVehicle = booking.vehicle && typeof booking.vehicle === 'object' ? booking.vehicle : {};
    const storedVehicle = vehicleData[booking.vehicleId || booking.vehicle_id] || {};
    const vehicle = {
      plateNumber: nestedVehicle.plateNumber || nestedVehicle.plate_number || booking.plateNumber || booking.plate_number || storedVehicle.plateNumber || storedVehicle.plate_number,
      make: nestedVehicle.make || nestedVehicle.vehicleMake || nestedVehicle.vehicle_make || booking.vehicleMake || booking.vehicle_make || booking.make || storedVehicle.make || storedVehicle.vehicleMake || storedVehicle.vehicle_make,
      model: nestedVehicle.model || nestedVehicle.vehicleModel || nestedVehicle.vehicle_model || booking.vehicleModel || booking.vehicle_model || booking.model || storedVehicle.model || storedVehicle.vehicleModel || storedVehicle.vehicle_model,
      year: nestedVehicle.year || nestedVehicle.vehicleYear || nestedVehicle.vehicle_year || booking.vehicleYear || booking.vehicle_year || booking.year || storedVehicle.year || storedVehicle.vehicleYear || storedVehicle.vehicle_year,
    };
    const subject = `Cancellation request for booking ${booking.id}`;
    const body = `Name: ${name}\nBooking ID: ${booking.id}\nDate: ${booking.date || ''}\nTime: ${booking.timeSlot || ''}\nService: ${booking.serviceName}\n\nPlease review my cancellation request.`;
    const href = `mailto:cooltimeautoaircon@gmail.com?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
    const cancelControl = statusKey === 'ongoing' ? `<a class="btn booking-cancel-link" href="${href}">Cancel</a>` : '<span class="booking-action-spacer" aria-hidden="true"></span>';
    const detailsId = `booking-details-${escapeHtml(booking.id)}`;
    const createdDate = bookingCreatedDate(booking);
    const referenceNumber = booking.referenceNumber || booking.invoiceNumber || booking.id;
    const details = [
      ['Reference ID', referenceNumber], ['Customer name', booking.customerName || name], ['Customer email', booking.customerEmail || 'Email unavailable'],
      ['Branch', branch], ['Branch address', booking.branchAddress || 'Address unavailable'],
      ['Service', booking.serviceName], ['Appointment date', booking.date || 'Date unavailable'], ['Preferred time', booking.timeSlot || 'Time unavailable'],
      ['Service price', `₱${Number(booking.servicePrice || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`],
      ['Vehicle plate number', vehicle.plateNumber || '—'], ['Vehicle make', vehicle.make || '—'],
      ['Vehicle model', vehicle.model || '—'], ['Vehicle year', vehicle.year || '—']
    ].map(([label, value]) => `<div class="booking-detail-field"><div class="booking-detail-label">${escapeHtml(label)}</div><div class="booking-detail-value">${escapeHtml(value)}</div></div>`).join('');
    const rescheduleControl = ['pending', 'confirmed'].includes(statusKey)
      ? `<button class="booking-reschedule-link" type="button" data-booking-id="${escapeHtml(booking.id)}" data-date="${escapeHtml(booking.date || '')}" data-time="${escapeHtml(booking.timeSlot || '')}">Request reschedule</button>` : '';
    return `<article class="customer-booking-row booking-status-${escapeHtml(statusKey)}" data-booking-id="${escapeHtml(booking.id)}"><div class="booking-row-main"><button class="booking-summary-toggle" type="button" aria-expanded="false" aria-controls="${detailsId}"><span class="booking-index">${(bookingsCurrentPage - 1) * PAGE_SIZE + index + 1}</span><span class="booking-summary-meta"><strong>${escapeHtml(booking.serviceName)}</strong><small>${escapeHtml(createdDate)}</small></span></button><span class="customer-booking-status ${escapeHtml(statusKey)}">${escapeHtml(status)}</span>${cancelControl}</div><div class="booking-details-panel" id="${detailsId}" aria-hidden="true" inert><div class="booking-details booking-expanded-grid">${details}</div><p><strong>Reference ID:</strong> ${escapeHtml(referenceNumber)} <button type="button" class="btn btn-secondary btn-sm" data-copy-reference="${escapeHtml(referenceNumber)}">Copy</button></p>${rescheduleControl}</div></article>`;
  }).join('');
}

async function loadCustomerBookings(user, profile) {
  const target = document.getElementById('customerBookings');
  if (!target) return;
  try {
    const [vehicles, branches] = await Promise.all([
      getDocs(query(collection(db, 'vehicles'), where('customerId', '==', user.uid))),
      getDocs(collection(db, 'branches'))
    ]);
    const vehicleData = Object.fromEntries(vehicles.docs.map((item) => [item.id, item.data()]));
    const branchCatalog = Object.fromEntries(branches.docs.map((item) => [item.id.toLowerCase().replace(/[- ]/g, '_'), { id: item.id, ...item.data() }]));
    let records = [];
    let serviceCatalog = {};
    const render = () => renderCustomerBookings(records, serviceCatalog, vehicleData, branchCatalog, user, profile, target);
    stopCustomerServiceCatalog?.();
    stopCustomerServiceCatalog = onSnapshot(collection(db, 'services'), (snapshot) => {
      serviceCatalog = Object.fromEntries(snapshot.docs.map((item) => [item.id, { ...item.data() }]));
      render();
    }, (error) => {
      console.error('Service names for customer bookings could not be refreshed:', error);
      serviceCatalog = {};
      render();
    });
    stopCustomerBookingListeners.forEach((stop) => stop());
    const normalizedEmail = String(user.email || profile?.email || '').trim().toLowerCase();
    const emailValues = [...new Set([String(user.email || profile?.email || '').trim(), normalizedEmail].filter(Boolean))];
    const bookingQueries = [
      query(collection(db, 'bookings'), where('customerId', '==', user.uid)),
      query(collection(db, 'bookings'), where('userId', '==', user.uid)),
      ...emailValues.flatMap((email) => [
        query(collection(db, 'bookings'), where('email', '==', email)),
        query(collection(db, 'bookings'), where('customerEmail', '==', email))
      ])
    ];
    const snapshotsByQuery = new Map();
    const renderMerged = () => {
      const uniqueRecords = new Map();
      snapshotsByQuery.forEach((snapshot) => snapshot.docs.forEach((item) => {
        if (!uniqueRecords.has(item.id)) uniqueRecords.set(item.id, { id: item.id, data: item.data() });
      }));
      snapshotsByQuery.forEach((snapshot) => snapshot.docs.forEach((item) => {
        if (!item.metadata?.hasPendingWrites) optimisticBookingRecords.delete(item.id);
      }));
      optimisticBookingRecords.forEach((data, id) => {
        if (!uniqueRecords.has(id)) uniqueRecords.set(id, { id, data });
      });
      const pendingTimestamp = Date.now();
      records = [...uniqueRecords.values()]
        .map((record) => ({ ...record, createdAtMs: bookingCreatedAtMs(record.data, pendingTimestamp) }))
        .sort((a, b) => {
          const chronological = a.createdAtMs - b.createdAtMs;
          if (chronological !== 0) return chronological;
          if (a.id === latestSubmittedBookingId && a.data.createdAt == null) return 1;
          if (b.id === latestSubmittedBookingId && b.data.createdAt == null) return -1;
          return 0;
        });
      render();
    };
    rerenderCustomerBookings = renderMerged;
    stopCustomerBookingListeners = bookingQueries.map((bookingQuery, index) => onSnapshot(bookingQuery, (snapshot) => {
      snapshotsByQuery.set(index, snapshot);
      renderMerged();
    }, (error) => {
      console.error('Customer booking query could not be refreshed:', error);
      snapshotsByQuery.delete(index);
      renderMerged();
      if (!snapshotsByQuery.size) target.textContent = 'Bookings are temporarily unavailable.';
    }));
  } catch (error) {
    console.error('Customer bookings could not be loaded:', error);
    target.textContent = 'Bookings are temporarily unavailable.';
  }
}

function goToBookingPage(value) {
  const total = Math.max(1, Math.ceil(currentBookingCount / PAGE_SIZE));
  const parsed = Number.parseInt(value, 10);
  bookingsCurrentPage = Math.min(total, Math.max(1, Number.isFinite(parsed) ? parsed : 1));
  rerenderCustomerBookings?.();
}
function goToRunningBillsPage(value) {
  const total = Math.max(1, Math.ceil(runningBillsKnownIds.size / PAGE_SIZE));
  const parsed = Number.parseInt(value, 10);
  runningBillsCurrentPage = Math.min(total, Math.max(1, Number.isFinite(parsed) ? parsed : 1));
  if (latestInvoiceSnapshot) renderInvoices(latestInvoiceSnapshot);
}
function renderCustomerPagination(page, total) {
  const buttonStyle = (disabled) => `width:32px!important;height:32px!important;min-width:32px!important;min-height:32px!important;border-radius:50%!important;border:1px solid rgba(255,255,255,.25)!important;background:transparent!important;color:#8da4c4!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:0!important;line-height:1!important;${disabled ? 'opacity:.3!important;cursor:not-allowed!important;' : 'cursor:pointer!important;'}`;
  return `<div class="pagination-bottom-right-wrapper" style="display:flex!important;justify-content:flex-end!important;align-items:center!important;width:100%!important;margin-top:auto!important;padding-top:20px!important;text-align:right!important"><div style="display:inline-flex!important;align-items:center!important;gap:10px!important;margin-left:auto!important"><button type="button" class="pagination-circle-btn btn-prev-page" style="${buttonStyle(page === 1)}" ${page === 1 ? 'disabled' : ''} aria-label="Previous page"><span style="font-size:.85rem!important;line-height:1!important;font-weight:600!important">&lt;</span></button><span style="color:#fff!important;font-weight:700!important;font-size:.95rem!important;letter-spacing:.3px!important;white-space:nowrap!important;margin:0 4px!important">Page <span class="current-page-num" style="color:#38bdf8!important;text-decoration:underline!important;text-underline-offset:3px!important;cursor:pointer!important;font-weight:700!important" title="Click to jump to page">${page}</span> of ${total}</span><button type="button" class="pagination-circle-btn btn-next-page" style="${buttonStyle(page === total)}" ${page === total ? 'disabled' : ''} aria-label="Next page"><span style="font-size:.85rem!important;line-height:1!important;font-weight:600!important">&gt;</span></button></div></div>`;
}

document.getElementById('customerBookingsPagination')?.addEventListener('click', (event) => {
  if (event.target.closest('.btn-prev-page')) { goToBookingPage(bookingsCurrentPage - 1); return; }
  if (event.target.closest('.btn-next-page')) { goToBookingPage(bookingsCurrentPage + 1); return; }
  const pageDisplay = event.target.closest('.current-page-num');
  if (!pageDisplay) return;
  const totalPages = Math.max(1, Math.ceil(currentBookingCount / PAGE_SIZE));
  const input = document.createElement('input');
  input.type = 'number'; input.min = '1'; input.max = String(totalPages); input.value = String(bookingsCurrentPage);
  input.className = 'report-page-input';
  input.setAttribute('aria-label', `Page number, 1 to ${totalPages}`);
  input.style.cssText = 'width:45px;height:24px;font-size:.85rem;text-align:center;background:rgba(255,255,255,.15);color:#fff;border:1px solid #38bdf8;border-radius:4px;outline:none;padding:0';
  pageDisplay.replaceWith(input); input.focus(); input.select();
  let finished = false;
  const finish = (commit) => {
    if (finished) return;
    finished = true;
    if (commit) goToBookingPage(input.value);
    else rerenderCustomerBookings?.();
  };
  input.addEventListener('keydown', (keyEvent) => {
    if (keyEvent.key === 'Enter') { keyEvent.preventDefault(); finish(true); }
    if (keyEvent.key === 'Escape') { keyEvent.preventDefault(); finish(false); }
  });
  input.addEventListener('blur', () => finish(true), { once: true });
});

document.getElementById('customerInvoicesPagination')?.addEventListener('click', (event) => {
  if (event.target.closest('.btn-prev-page')) { goToRunningBillsPage(runningBillsCurrentPage - 1); return; }
  if (event.target.closest('.btn-next-page')) { goToRunningBillsPage(runningBillsCurrentPage + 1); return; }
  const pageDisplay = event.target.closest('.current-page-num');
  if (!pageDisplay) return;
  const totalPages = Math.max(1, Math.ceil(runningBillsKnownIds.size / PAGE_SIZE));
  const input = document.createElement('input');
  input.type = 'number'; input.min = '1'; input.max = String(totalPages); input.value = String(runningBillsCurrentPage);
  input.className = 'report-page-input';
  input.setAttribute('aria-label', `Page number, 1 to ${totalPages}`);
  input.style.cssText = 'width:45px;height:24px;font-size:.85rem;text-align:center;background:rgba(255,255,255,.15);color:#fff;border:1px solid #38bdf8;border-radius:4px;outline:none;padding:0';
  pageDisplay.replaceWith(input); input.focus(); input.select();
  let finished = false;
  const finish = (commit) => {
    if (finished) return;
    finished = true;
    if (commit) goToRunningBillsPage(input.value);
    else if (latestInvoiceSnapshot) renderInvoices(latestInvoiceSnapshot);
  };
  input.addEventListener('keydown', (keyEvent) => {
    if (keyEvent.key === 'Enter') { keyEvent.preventDefault(); finish(true); }
    if (keyEvent.key === 'Escape') { keyEvent.preventDefault(); finish(false); }
  });
  input.addEventListener('blur', () => finish(true), { once: true });
});

const rescheduleDialog = document.getElementById('rescheduleModal');
const rescheduleForm = document.getElementById('rescheduleForm');
const rescheduleDateInput = document.getElementById('rescheduleDate');
const rescheduleTimeInput = document.getElementById('rescheduleTime');
const rescheduleBookingIdInput = document.getElementById('rescheduleBookingId');
const rescheduleError = document.getElementById('rescheduleError');
const rescheduleSubmit = document.getElementById('rescheduleSubmit');

function localDateInputValue(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function isRescheduleSelectionValid(date, time) {
  const selectedDate = new Date(`${date}T00:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return /^\d{4}-\d{2}-\d{2}$/.test(date)
    && !Number.isNaN(selectedDate.getTime())
    && localDateInputValue(selectedDate) === date
    && selectedDate >= today
    && Boolean(time);
}

function clearRescheduleErrorWhenValid() {
  if (isRescheduleSelectionValid(rescheduleDateInput?.value || '', rescheduleTimeInput?.value || '') && rescheduleError) {
    rescheduleError.textContent = '';
    rescheduleError.hidden = true;
  }
}

rescheduleDateInput?.addEventListener('input', clearRescheduleErrorWhenValid);
rescheduleTimeInput?.addEventListener('change', clearRescheduleErrorWhenValid);
rescheduleDialog?.querySelectorAll('[data-reschedule-close]').forEach((button) => {
  button.addEventListener('click', () => rescheduleDialog.close());
});

document.getElementById('customerBookings')?.addEventListener('click', (event) => {
  const rescheduleButton = event.target.closest('.booking-reschedule-link');
  if (rescheduleButton) {
    event.preventDefault();
    const today = localDateInputValue();
    if (rescheduleDateInput) {
      rescheduleDateInput.min = today;
      rescheduleDateInput.value = rescheduleButton.dataset.date >= today ? rescheduleButton.dataset.date : today;
    }
    if (rescheduleTimeInput) rescheduleTimeInput.value = rescheduleButton.dataset.time || '';
    if (rescheduleBookingIdInput) rescheduleBookingIdInput.value = rescheduleButton.dataset.bookingId || '';
    if (rescheduleError) { rescheduleError.textContent = ''; rescheduleError.hidden = true; }
    rescheduleDialog?.showModal();
    return;
  }
  const row = event.target.closest('.customer-booking-row');
  if (!row || event.target.closest('a, button:not(.booking-summary-toggle)')) return;
  const toggle = row.querySelector('.booking-summary-toggle');
  const expanded = toggle.getAttribute('aria-expanded') === 'true';
  const details = document.getElementById(toggle.getAttribute('aria-controls'));
  toggle.setAttribute('aria-expanded', String(!expanded));
  details?.classList.toggle('expanded', !expanded);
  details?.setAttribute('aria-hidden', String(expanded));
  if (details) details.inert = expanded;
});

rescheduleForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const requestedDate = rescheduleDateInput?.value || '';
  const requestedTime = rescheduleTimeInput?.value || '';
  if (!isRescheduleSelectionValid(requestedDate, requestedTime)) {
    if (rescheduleError) {
      rescheduleError.textContent = 'Choose a date from today onward and a valid appointment time slot.';
      rescheduleError.hidden = false;
    }
    return;
  }

  const access = await requireRole(['customer']);
  if (!access) return;
  if (rescheduleSubmit) rescheduleSubmit.disabled = true;
  try {
    const response = await fetch('api/customer-notification-event.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await access.user.getIdToken()}` },
      body: JSON.stringify({
        action: 'request_reschedule',
        bookingId: rescheduleBookingIdInput?.value || '',
        requestedDate,
        requestedTime
      })
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || 'Reschedule request could not be sent.');
    rescheduleDialog?.close();
    alert(result.message);
  } catch (error) {
    if (rescheduleError) {
      rescheduleError.textContent = error.message || 'Reschedule request could not be sent.';
      rescheduleError.hidden = false;
    }
  } finally {
    if (rescheduleSubmit) rescheduleSubmit.disabled = false;
  }
});
if (form || document.getElementById('customerBookings')) {
  const customerPage = document.querySelector('.customer-dashboard');
  requireRole(['customer']).then((access) => {
    if (!access) return;
    customerPage?.removeAttribute('hidden');
    if (!form) {
      loadCustomerBookings(access.user, access.profile);
      return;
    }
  }).catch(() => window.location.replace('login.html'));
}

if (form) {
  const newBookingButton = document.getElementById('showBookingForm');
  const cancelBookingButton = document.getElementById('cancelBookingForm');
  let bookingCloseTimer;
  const closeBookingForm = (reset = true) => {
    if (reset) {
      form.reset();
      resetSlots();
      updateAvailableSlots(dateInput, branchInput, slotInput, { requireAuthentication: true });
    }
    if (form.hidden) {
      newBookingButton.hidden = false;
      return;
    }
    form.classList.add('is-closing');
    const finishClose = () => {
      form.hidden = true;
      form.classList.remove('is-closing');
      newBookingButton.hidden = false;
      clearTimeout(bookingCloseTimer);
    };
    const onTransitionEnd = (event) => {
      if (event.target === form && event.propertyName === 'max-height') {
        form.removeEventListener('transitionend', onTransitionEnd);
        finishClose();
      }
    };
    form.addEventListener('transitionend', onTransitionEnd);
    clearTimeout(bookingCloseTimer);
    bookingCloseTimer = setTimeout(() => {
      form.removeEventListener('transitionend', onTransitionEnd);
      finishClose();
    }, 450);
  };
  newBookingButton?.addEventListener('click', () => {
    const currentCount = document.querySelectorAll('#customerBookings .customer-booking-row').length;
    document.getElementById('bookingFormIndex').textContent = String(currentCount + 1);
    form.hidden = false;
    newBookingButton.hidden = true;
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
  cancelBookingButton?.addEventListener('click', () => closeBookingForm());
  const today = new Date();
  dateInput.min = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
  resetSlots();
  branchInput.addEventListener('change', () => updateAvailableSlots(dateInput, branchInput, slotInput, { requireAuthentication: true }));
  dateInput.addEventListener('change', () => updateAvailableSlots(dateInput, branchInput, slotInput, { requireAuthentication: true }));
  requireRole(['customer']).then((access) => {
    if (!access) return;
    customerProfile = access.profile;
    window.dispatchEvent(new CustomEvent('customer:profile', { detail: {
      name: customerProfile?.name || customerProfile?.username || 'Customer',
      username: customerProfile?.username || customerProfile?.name || 'customer'
    } }));
    document.querySelector('.customer-dashboard')?.removeAttribute('hidden');
    form.removeAttribute('hidden');
    const fullName = document.getElementById('fullName');
    const email = document.getElementById('email');
    fullName.value = customerProfile?.name || customerProfile?.username || '';
    email.value = access.user.email || '';
    email.readOnly = true;
    document.getElementById('customerName')?.replaceChildren(fullName.value || 'Customer');
    loadInvoices(access.user);
    loadCustomerBookings(access.user, customerProfile);
    const paymentNotice = document.getElementById('paymentReturnNotice');
    const returnStatus = new URLSearchParams(window.location.search).get('payment');
    const returnInvoice = new URLSearchParams(window.location.search).get('invoice');
    if (paymentNotice && returnStatus) {
      paymentNotice.hidden = false;
      paymentNotice.textContent = returnStatus === 'overdue'
        ? `Payment was not completed for invoice ${returnInvoice || ''}. The invoice remains available so you can retry payment.`
        : returnStatus === 'success'
          ? `Payment for invoice ${returnInvoice || ''} was submitted. The invoice will show paid after PayMongo confirms it.`
          : returnStatus === 'superseded'
            ? `A newer checkout was started for invoice ${returnInvoice || ''}. Its current payment status is shown below.`
            : returnStatus === 'cancelled'
              ? `Invoice ${returnInvoice || ''} was cancelled. Contact the branch if you need help.`
          : 'We could not confirm the payment return. Check your invoice status or contact support.';
    }
  }).catch(() => window.location.replace('login.html'));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const access = await requireRole(['customer']);
    if (!access || !slotInput.value || slotInput.selectedOptions[0].disabled) return;
    submitButton.disabled = true;
    try {
      const data = new FormData(form);
      const bookingEmail = String(access.user.email || '').trim().toLowerCase();
      const result = await createBooking({ customerId: access.user.uid, userId: access.user.uid, email: bookingEmail, customerEmail: bookingEmail, branchId: data.get('branchId'), serviceId: data.get('serviceType'), date: data.get('appointmentDate'), timeSlot: data.get('timeSlot'), vehicle: { plateNumber: data.get('plateNumber'), make: data.get('vehicleMake'), model: data.get('vehicleModel'), year: data.get('vehicleYear') } });
      latestSubmittedBookingId = result.bookingId;
      if (result.booking) optimisticBookingRecords.set(result.bookingId, result.booking);
      rerenderCustomerBookings?.();
      bookingsCurrentPage = Math.max(1, Math.ceil(currentBookingCount / PAGE_SIZE));
      rerenderCustomerBookings?.();
      let invoice;
      try { invoice = await createWebInvoice(result.bookingId, access.user); }
      catch (error) { alert(`Booking ${result.bookingId} was saved, but invoice creation failed: ${error.message}`); return; }
      try {
        await fetch('api/customer-notification-event.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await access.user.getIdToken()}` },
          body: JSON.stringify({ action: 'booking_created', bookingId: result.bookingId })
        });
      } catch (notificationError) {
        console.error('Booking notification dispatch failed:', notificationError);
      }
      bookingsCurrentPage = Math.max(1, Math.ceil(currentBookingCount / PAGE_SIZE));
      rerenderCustomerBookings?.();
      await loadInvoices(access.user);
      alert(`Booking submitted. Reference ID ${invoice.referenceNumber || invoice.invoiceId} is in your running bills. You can add services there and pay when the bill is ready.`);
      closeBookingForm();
      document.getElementById('fullName').value = customerProfile?.name || customerProfile?.username || '';
      document.getElementById('email').value = access.user.email || '';
    } catch (error) {
      alert(`Booking failed: ${error.message}`);
    } finally { submitButton.disabled = !slotInput.value || slotInput.selectedOptions[0]?.disabled === true; }
  });
}

