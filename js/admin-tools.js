import { onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { auth, authReady, db } from './firebase-config.js';
import { collection, onSnapshot } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { getAuthenticatedUser, hasOtpVerification, normalizeRole } from './auth-guard.js';
import { logoutUser } from './auth.js';

const $ = (selector) => document.querySelector(selector);
// Admin tools also runs inside admin-dashboard.html, which intentionally omits
// the standalone tools page's back link and several Super Admin controls.
const on = (selector, event, handler) => $(selector)?.addEventListener(event, handler);
const isSuperAdminDashboard = document.body.dataset.superAdminDashboard === 'true';
const isAdminDashboard = document.body.dataset.adminDashboard === 'true';
const isDashboardPage = isSuperAdminDashboard || isAdminDashboard;
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch]));
const money = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
let actor, role, bookings = [], accounts = [], services = [], invoices = [], payments = [], submissions = [], customers = [], vehicles = [], branches = [];
let usersById = {}, servicesById = {}, paymentsByInvoice = {}, vehiclesById = {}, branchesById = {};
let adminRows = {};
let reportHistoryData = [];
let overviewMetric = 'bookings';
const modulePaginators = {};
const moduleRowsPerPage = 15;
let allSystemLogs = [];
let currentAccountsPage = 1;
const accountsPerPage = 15;

function bookingServiceId(booking) {
  return booking.serviceId || booking.service_id || booking.serviceID || '';
}

function bookingServiceName(booking) {
  const serviceId = bookingServiceId(booking);
  const currentName = servicesById[serviceId]?.name;
  if (currentName) return currentName;
  const storedName = booking.service_name || booking.serviceName;
  if (storedName && storedName !== serviceId) return storedName;
  return String(serviceId).replace(/^svc[_-]?/i, '').replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase()) || 'Service';
}

function setupModulePagination(config) {
  const container = document.getElementById(config.listContainerId);
  const card = document.getElementById(config.cardContainerId);
  const footer = card?.querySelector('.table-pagination-footer');
  if (!container || !footer) return { setItems() {} };
  let currentPage = 1;
  let itemsData = [];
  const renderRow = config.renderRowFn || ((item) => item);

  function updateView() {
    const totalPages = Math.ceil(itemsData.length / moduleRowsPerPage) || 1;
    currentPage = Math.min(Math.max(1, currentPage), totalPages);
    const startIndex = (currentPage - 1) * moduleRowsPerPage;
    const visibleItems = itemsData.slice(startIndex, startIndex + moduleRowsPerPage);
    container.innerHTML = visibleItems.length
      ? visibleItems.map((item, index) => renderRow(item, startIndex + index)).join('')
      : config.emptyHtml;
    const current = footer.querySelector('.current-page-num');
    const total = footer.querySelector('.total-pages-num');
    const previous = footer.querySelector('.btn-prev-page');
    const next = footer.querySelector('.btn-next-page');
    if (current) current.textContent = String(currentPage);
    if (total) total.textContent = String(totalPages);
    if (previous) previous.disabled = currentPage === 1;
    if (next) next.disabled = currentPage === totalPages;
  }

  footer.addEventListener('click', (event) => {
    if (event.target.closest('.btn-prev-page')) { currentPage -= 1; updateView(); return; }
    if (event.target.closest('.btn-next-page')) { currentPage += 1; updateView(); return; }
    const pageDisplay = event.target.closest('.current-page-num');
    if (!pageDisplay) return;
    const totalPages = Math.ceil(itemsData.length / moduleRowsPerPage) || 1;
    const input = document.createElement('input');
    input.type = 'number';
    input.className = 'report-page-input';
    input.value = String(currentPage);
    input.min = '1';
    input.max = String(totalPages);
    input.setAttribute('aria-label', 'Page number, 1 to ' + totalPages);
    pageDisplay.replaceWith(input);
    input.focus();
    input.select();
    let finished = false;
    const finish = (commit) => {
      if (finished) return;
      finished = true;
      if (commit) currentPage = Math.min(Math.max(1, Number.parseInt(input.value, 10) || 1), totalPages);
      const pageSpan = document.createElement('span');
      pageSpan.className = 'current-page-num';
      pageSpan.title = 'Click to jump to page';
      input.replaceWith(pageSpan);
      updateView();
    };
    input.addEventListener('keydown', (keyEvent) => {
      if (keyEvent.key === 'Enter') { keyEvent.preventDefault(); finish(true); }
      if (keyEvent.key === 'Escape') { keyEvent.preventDefault(); finish(false); }
    });
    input.addEventListener('blur', () => finish(true), { once: true });
  });

  updateView();
  return {
    setItems(items) { itemsData = Array.isArray(items) ? items : []; currentPage = 1; updateView(); },
    refresh: updateView
  };
}

async function getRows(name) {
  return adminRows[name] || [];
}

function overviewDate(value) {
  if (!value) return '';
  const date = value?.toDate?.() || new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
}

function createHorizontalBookingRowHTML(booking) {
  const user = usersById[booking.customerId] || {};
  const serviceName = bookingServiceName(booking);
  const status = normalizeBookingFilterStatus(booking.status || 'pending');
  const statusColor = status === 'confirmed' ? '#38bdf8' : '#f97316';
  const branchKey = String(booking.branchId || '').toLowerCase().replace(/[\s-]+/g, '_');
  const branch = branchesById[booking.branchId]?.name
    || ({ cabuyao_main: 'Cabuyao Branch', cabuyao: 'Cabuyao Branch', santa_rosa: 'Santa Rosa Branch', santarosa: 'Santa Rosa Branch' }[branchKey])
    || booking.branchId || 'Branch';
  const dateTime = [booking.date, booking.timeSlot || booking.time].filter(Boolean).join(' - ');
  return `<div class="overview-booking-row" role="row">
    <div class="overview-booking-customer" role="cell" title="${esc(user.name || user.username || booking.customerName || 'Customer')}">${esc(user.name || user.username || booking.customerName || 'Customer')}</div>
    <div class="overview-booking-email" role="cell" title="${esc(user.email || booking.customerEmail || '')}">${esc(user.email || booking.customerEmail || '')}</div>
    <div class="overview-booking-service" role="cell" title="${esc(serviceName)}"><strong>${esc(serviceName)}</strong><span>${esc(dateTime)}</span></div>
    <div class="overview-booking-branch" role="cell" title="${esc(branch)}">${esc(branch)}</div>
    <div class="overview-booking-status" role="cell" style="color: ${statusColor} !important;">${esc(status.replace(/\b\w/g, (letter) => letter.toUpperCase()))}</div>
  </div>`;
}

function renderOverviewBookings(target) {
  const recentBookings = bookings
    .filter((item) => ['pending', 'confirmed'].includes(normalizeBookingFilterStatus(item.status || 'pending')))
    .sort((a, b) => `${b.date || ''} ${b.timeSlot || ''}`.localeCompare(`${a.date || ''} ${a.timeSlot || ''}`))
    .slice(0, 30);
  target.innerHTML = recentBookings.map(createHorizontalBookingRowHTML).join('')
    || '<p class="overview-empty">No pending or confirmed bookings.</p>';
}

function createOverviewServiceRowHTML(service) {
  const parsedPrice = Number.parseFloat(service.price || 0);
  const price = Number.isFinite(parsedPrice) ? parsedPrice : 0;

  return `<div class="overview-service-row d-flex align-items-center justify-content-between py-3 border-bottom border-secondary border-opacity-10" style="display: flex !important; flex-direction: row !important; align-items: center !important; justify-content: space-between !important; gap: 12px; width: 100%; box-sizing: border-box; padding-top: 12px !important; padding-bottom: 12px !important; margin-bottom: 4px; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;">
    <div style="flex: 1 1 60%; min-width: 0; font-weight: 400 !important; color: #ffffff; font-size: 0.92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 65%;" title="${esc(service.name || 'Service Name')}">
      ${esc(service.name || 'Service Name')}
    </div>
    <div style="flex: 0 0 auto; font-weight: 700; color: #ffffff !important; font-size: 0.92rem; white-space: nowrap; text-align: right;">
      PHP ${price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
    </div>
  </div>`;
}

function renderDashboardOverview() {
  if (!isDashboardPage) return;
  const bookingsTarget = $('#overview-bookings-list');
  const serviceTarget = $('#overview-services-list');
  const inquiriesTarget = $('#overview-inquiries-list');
  const reportsTarget = $('#overview-recent-reports-log');
  const notificationsTarget = $('#overview-notifications-list');
  if (!bookingsTarget) return;

  renderOverviewBookings(bookingsTarget);

  if (serviceTarget) {
    const activeServices = services.filter((item) => !['inactive', 'disabled', 'archived'].includes(String(item.status || '').toLowerCase()));
    serviceTarget.innerHTML = activeServices.map(createOverviewServiceRowHTML).join('') || '<p class="overview-empty">No active services in the catalog.</p>';
  }

  if (inquiriesTarget) {
    const recentSubmissions = [...submissions].sort((a, b) => String(b.createdAt || b.requestedDate || b.id || '').localeCompare(String(a.createdAt || a.requestedDate || a.id || ''))).slice(0, 20);
    inquiriesTarget.innerHTML = recentSubmissions.map((item) => `<div class="overview-list-item overview-inquiry-item"><div class="overview-item-main"><span class="overview-item-title">${esc(item.name || item.email || 'Customer request')} · ${esc(item.type || (item.collection === 'feedback' ? 'Feedback' : 'Inquiry'))}</span><span class="overview-item-meta">${esc(item.message || item.requestedService || 'No message provided.')}</span></div><span class="overview-item-value inquiry-date-text">${esc(overviewDate(item.createdAt || item.requestedDate))}</span></div>`).join('') || '<p class="overview-empty">No inquiries or feedback yet.</p>';
  }

  if (reportsTarget) {
    reportsTarget.innerHTML = reportHistoryData.slice(0, 4).map((item) => `<div class="overview-report-row">${esc(item.type || 'Report')} · ${esc(item.period || '—')} · ${Number(item.rowCount || 0)} rows · ${esc(overviewDate(item.createdAt))}</div>`).join('') || '<p class="overview-empty">No reports generated yet.</p>';
  }

  if (notificationsTarget) {
    const recentNotifications = [...(adminRows.notifications || [])]
      .filter((item) => !isAuthenticationNotification(item))
      .filter((item) => !item.recipientRole || item.recipientRole === 'admin')
      .sort((a, b) => String(b.createdAt || b.timestamp || b.updatedAt || '').localeCompare(String(a.createdAt || a.timestamp || a.updatedAt || '')))
      .slice(0, 20);
    notificationsTarget.innerHTML = recentNotifications.map((item) => `<div class="overview-list-item overview-notification-row" role="button" tabindex="0" data-notification-id="${esc(item.id)}"><div class="overview-item-main"><span class="overview-item-title">${esc(formatNotificationMessage(item) || item.title || 'Activity')}</span><span class="overview-item-meta">${esc(overviewDate(item.timestamp || item.createdAt || item.updatedAt))}</span></div></div>`).join('') || '<p class="overview-empty">No operational notifications found.</p>';
  }

  const canvas = $('#overviewMiniChart');
  const context = canvas?.getContext('2d');
  if (!canvas || !context) return;
  const rect = canvas.getBoundingClientRect();
  if (!rect.width || !rect.height) return;
  const ratio = window.devicePixelRatio || 1;
  canvas.width = Math.round(rect.width * ratio);
  canvas.height = Math.round(rect.height * ratio);
  context.setTransform(ratio, 0, 0, ratio, 0, 0);
  context.clearRect(0, 0, rect.width, rect.height);
  const metricDetails = {
    bookings: { label: 'Bookings', rows: bookings, amount: () => 1 },
    inquiries: { label: 'Customer inquiries', rows: submissions.filter((item) => item.collection === 'inquiries'), amount: () => 1 },
    feedback: { label: 'Customer feedback', rows: submissions.filter((item) => item.collection === 'feedback'), amount: () => 1 },
    sales: { label: 'Sales (PHP)', rows: invoices.filter((item) => item.source === 'pos' && String(item.status || '').toLowerCase() === 'paid'), amount: (item) => Number(item.totalAmount ?? item.finalAmount ?? 0) }
  };
  const metric = metricDetails[overviewMetric] || metricDetails.bookings;
  canvas.setAttribute('aria-label', `${metric.label} by day over the past seven days`);
  const days = Array.from({ length: 7 }, (_, index) => {
    const date = new Date(); date.setHours(0, 0, 0, 0); date.setDate(date.getDate() - (6 - index));
    const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    return { key, label: date.toLocaleDateString('en', { weekday: 'short' }), value: 0 };
  });
  metric.rows.forEach((row) => {
    const date = dateKey(row);
    const bucket = days.find((day) => day.key === date);
    if (bucket) bucket.value += metric.amount(row);
  });
  const max = Math.max(1, ...days.map((day) => day.value));
  const left = 10, right = rect.width - 8, top = 10, bottom = rect.height - 24;
  const slot = (right - left) / days.length;
  context.strokeStyle = 'rgba(220,235,255,.14)'; context.lineWidth = 1;
  context.beginPath(); context.moveTo(left, bottom); context.lineTo(right, bottom); context.stroke();
  days.forEach((day, index) => {
    const barWidth = Math.max(10, slot * .48);
    const height = day.value ? Math.max(4, (bottom - top) * day.value / max) : 2;
    const x = left + slot * index + (slot - barWidth) / 2;
    context.fillStyle = '#54c8ff';
    context.beginPath(); context.roundRect(x, bottom - height, barWidth, height, 4); context.fill();
    context.fillStyle = 'rgba(230,239,255,.65)'; context.font = '10px Arial'; context.textAlign = 'center';
    context.fillText(day.label, x + barWidth / 2, rect.height - 6);
    if (day.value) {
      const valueLabel = overviewMetric === 'sales'
        ? day.value.toLocaleString('en-US', { maximumFractionDigits: 0 })
        : String(day.value);
      context.fillStyle = '#fff'; context.fillText(valueLabel, x + barWidth / 2, Math.max(8, bottom - height - 5));
    }
  });
}

async function api(path, payload) {
  const response = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await actor.getIdToken(payload.action === 'verify_session')}` }, body: JSON.stringify(payload) });
  let result;
  try { result = await response.json(); }
  catch { throw new Error(`Admin service returned an invalid response (HTTP ${response.status}).`); }
  if (!response.ok || !result.ok) {
    const error = new Error(result.message || `Request failed (HTTP ${response.status}).`);
    error.status = response.status;
    throw error;
  }
  return result;
}

function showMessage(form, message) { form.querySelector('output').textContent = message; }
function readableError(error) {
  if (error?.code === 'permission-denied' || /missing or insufficient permissions/i.test(error?.message || '')) {
    return 'Your administrator access was denied. Confirm that your users/{UID} role is active and that the current Firestore rules are published.';
  }
  return error?.message || 'The action could not be completed.';
}

async function initialize() {
  actor = await getAuthenticatedUser();
  if (!actor) { redirectToLoginWhenSignedOut(); return; }
  // Match admin-dashboard.js: the Firebase user is resolved only after
  // authReady/onAuthStateChanged, and OTP is bound to this exact UID.
  if (!hasOtpVerification(actor)) { window.location.replace('otp.html'); return; }
  const access = await api('api/admin-workflow.php', { action: 'verify_session' });
  role = normalizeRole(access.role);
  if (!['admin', 'super_admin'].includes(role)) {
    alert('Your account does not have administrator access.');
    return;
  }
  if (isSuperAdminDashboard && role !== 'super_admin') {
    alert('Your account does not have super administrator access.');
    return;
  }
  if (isDashboardPage) {
    modulePaginators.bookings = setupModulePagination({
      cardContainerId: 'bookingManagement', listContainerId: 'bookingResults',
      emptyHtml: '<p class="booking-empty-state">No matching bookings found.</p>'
    });
    modulePaginators.inquiries = setupModulePagination({
      cardContainerId: 'inquiryManagement', listContainerId: 'inquiryResults',
      emptyHtml: '<tr><td colspan="4">No inquiries or feedback yet.</td></tr>'
    });
    modulePaginators.customers = setupModulePagination({
      cardContainerId: 'customerManagement', listContainerId: 'customerResults',
      emptyHtml: '<tr><td colspan="5">No customer records.</td></tr>'
    });
    modulePaginators.pos = setupModulePagination({
      cardContainerId: 'posMonitoring', listContainerId: 'posResults',
      emptyHtml: '<tr><td colspan="7" class="pos-empty-state">No POS transactions match these filters.</td></tr>'
    });
  }
  if (!isSuperAdminDashboard) {
    const dashboardBackLink = $('#dashboardBackLink');
    if (dashboardBackLink) dashboardBackLink.href = role === 'super_admin' ? 'super-dashboard.html' : 'admin-dashboard.html';
    const toolsShell = $('.admin-tools-shell');
    if (toolsShell) toolsShell.hidden = false;
    const adminIdentity = $('#adminIdentity');
    if (adminIdentity) adminIdentity.textContent = `${access.profile?.name || actor.email} · ${role.replace('_', ' ')}`;
  }
  if (role === 'super_admin') document.querySelectorAll('.super-only').forEach((node) => { node.hidden = false; });
  await loadCoreData();
  watchDashboardNotifications();
  watchServiceCatalog();
  $('#toolsLogout')?.addEventListener('click', () => logoutUser('login.html'));
  on('#closeDialog', 'click', () => $('#detailDialog')?.close());
  on('#booking-search-input', 'input', renderBookings);
  on('#booking-start-date', 'change', renderBookings);
  on('#booking-end-date', 'change', renderBookings);
  on('#booking-status-filter', 'change', renderBookings);
  on('#verificationReply', 'submit', sendVerificationReply);
  on('#serviceForm', 'submit', saveService);
  $('#createServiceToggle')?.addEventListener('click', (event) => {
    const accordion = $('#createServiceAccordion');
    if (!accordion) return;
    const expanded = accordion.classList.toggle('expanded');
    event.currentTarget.setAttribute('aria-expanded', String(expanded));
  });
  on('#cancelServiceEdit', 'click', () => {
    $('#serviceForm')?.reset();
    $('#createServiceAccordion')?.classList.remove('expanded');
    $('#createServiceToggle')?.setAttribute('aria-expanded', 'false');
  });
  $('#service-search-input')?.addEventListener('input', (event) => {
    const term = event.currentTarget.value.trim().toLowerCase();
    document.querySelectorAll('#serviceResults .service-card-item').forEach((card) => {
      const title = card.querySelector('.service-title')?.textContent.toLowerCase() || '';
      const description = card.querySelector('.service-desc')?.textContent.toLowerCase() || '';
      card.style.display = title.includes(term) || description.includes(term) ? '' : 'none';
    });
  });
  on('#serviceResults', 'click', serviceAction);
  on('#pos-start-date', 'change', renderPos);
  on('#pos-end-date', 'change', renderPos);
  on('#pos-method-filter', 'change', renderPos);
  on('#pos-cashier-search', 'input', renderPos);
  on('#pos-status-filter', 'change', renderPos);
  on('#posResults', 'click', (event) => {
    const superAction = event.target.closest('[data-super-pos-action]');
    if (superAction) {
      event.stopPropagation();
      const { superPosAction, invoiceId } = superAction.dataset;
      if (superPosAction === 'flag') handleSuperAdminFlag(invoiceId);
      else if (superPosAction === 'void') handleSuperAdminVoid(invoiceId);
      else if (superPosAction === 'export') exportSingleTransactionAudit(invoiceId);
      return;
    }
    const superDropdownToggle = event.target.closest('.btn-super-pos-actions');
    if (superDropdownToggle) {
      event.stopPropagation();
      event.preventDefault();
      const details = superDropdownToggle.closest('details');
      details.open = !details.open;
      return;
    }
    const voidButton = event.target.closest('.btn-pos-void-action');
    if (voidButton) {
      event.stopPropagation();
      voidAdminPosTransaction(voidButton.dataset.invoiceId);
      return;
    }
    const row = event.target.closest('tr.pos-main-row');
    if (!row) return;
    const panelRow = row.nextElementSibling;
    const panel = panelRow?.querySelector('.pos-accordion-panel');
    if (!panel) return;
    const expanded = !panel.classList.contains('expanded');
    $('#posResults').querySelectorAll('.pos-accordion-panel.expanded').forEach((openPanel) => {
      openPanel.classList.remove('expanded');
      const openRow = openPanel.closest('tr')?.previousElementSibling;
      openRow?.classList.remove('is-expanded');
      const openButton = openRow?.querySelector('.pos-actions-button');
      if (openButton) { openButton.setAttribute('aria-expanded', 'false'); openButton.textContent = 'Actions ▾'; }
      openRow?.nextElementSibling?.querySelector('.pos-audit-actions')?.removeAttribute('open');
    });
    if (expanded) {
      panel.classList.add('expanded');
      row.classList.add('is-expanded');
      const button = row.querySelector('.pos-actions-button');
      if (button) { button.setAttribute('aria-expanded', 'true'); button.textContent = 'Actions ▴'; }
    }
  });
  on('#reportForm', 'submit', generateReport);
  on('#customerSearch', 'input', renderCustomers);
  on('#exportBackup', 'click', exportBackup);
  on('#import-db-csv-trigger', 'click', () => $('#import-db-csv-input')?.click());
  on('#import-db-csv-input', 'change', handleDatabaseCSVImport);
  on('#customerResults', 'click', customerAction);
  on('#inquiryResults', 'click', submissionAction);
  on('#inquiryResults', 'keydown', (event) => {
    if (!['Enter', ' '].includes(event.key) || !event.target.matches('[data-inquiry-row]')) return;
    event.preventDefault();
    toggleInquiryRow(event.target);
  });
  on('#bookingResults', 'click', bookingAction);
  if (isDashboardPage) {
    const bookingList = $('#bookingResults');
    bookingList.addEventListener('click', (event) => {
      if (event.target.closest('details, button, a, input, select, textarea')) return;
      const row = event.target.closest('[data-booking-row]');
      if (row) toggleBookingRow(row);
    });
    bookingList.addEventListener('keydown', (event) => {
      if (!['Enter', ' '].includes(event.key) || !event.target.matches('[data-booking-row]')) return;
      event.preventDefault();
      toggleBookingRow(event.target);
    });
  }
  if (role === 'super_admin' && isSuperAdminDashboard) {
    on('#accountForm', 'submit', saveAccount);
    on('#accountResults', 'click', accountAction);
    on('#accountResults', 'keydown', (event) => {
      if (!['Enter', ' '].includes(event.key) || !event.target.matches('[data-account-row]')) return;
      event.preventDefault();
      toggleAccountRow(event.target);
    });
    on('#accountResults', 'submit', saveAccountEdit);
    on('#account-search-input', 'input', () => { currentAccountsPage = 1; filterAccountRows(); });
    on('#account-role-filter', 'change', () => { currentAccountsPage = 1; filterAccountRows(); });
    on('#createAccountToggle', 'click', (event) => {
      const expanded = $('#accountCreateAccordion').classList.toggle('expanded');
      $('#accountCreateAccordion').inert = !expanded;
      event.currentTarget.setAttribute('aria-expanded', String(expanded));
    });
    on('#cancelCreateAccount', 'click', () => {
      $('#accountForm')?.reset();
      $('#accountForm').querySelector('output').textContent = '';
      $('#accountCreateAccordion').classList.remove('expanded');
      $('#accountCreateAccordion').inert = true;
      $('#createAccountToggle').setAttribute('aria-expanded', 'false');
    });
    on('#form-booking-settings', 'submit', saveBookingSettings);
    on('#form-paymongo-settings', 'submit', savePayMongoSettings);
    on('#form-template-settings', 'submit', saveTemplateSettings);
    on('#template-type-select', 'change', loadNotificationTemplate);
  }
}

function redirectToLoginWhenSignedOut(onValidSession = () => {}) {
  authReady.then(() => {
    let unsubscribe = () => {};
    unsubscribe = onAuthStateChanged(auth, (user) => {
      unsubscribe();
      if (!user) {
        window.location.href = 'login.html';
      } else if (sessionStorage.getItem('cooltimeOtpVerifiedUid') !== user.uid) {
        window.location.replace('otp.html');
      } else {
        onValidSession(user);
      }
    });
  }).catch((error) => console.error('Firebase auth initialization failed:', error));
}

// Recheck cached history restores against the same Firebase user and OTP UID
// marker used during normal startup. A transient verification failure must not
// turn a back navigation into an automatic logout.
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    // BFCache restored this page during back/forward navigation. Do not
    // redirect or alter the OTP session during the restore.
    return;
  }
});
async function loadCoreData() {
  const response = await api('api/admin-workflow.php', { action: 'load_data' });
  adminRows = response.data || {};
  const [bookingRows, accountRows, serviceRows, invoiceRows, paymentRows, feedbackRows, inquiryRows, vehicleRows, branchRows] = [
    'bookings', 'users', 'services', 'invoices', 'payments', 'feedback', 'inquiries', 'vehicles', 'branches'
  ].map((name) => adminRows[name] || []);
  bookings = bookingRows; accounts = accountRows.filter((item) => ['customer', 'staff', 'admin'].includes(String(item.role || '').toLowerCase())); customers = accountRows.filter((item) => item.role === 'customer'); services = serviceRows;
  invoices = invoiceRows; payments = paymentRows; submissions = [...feedbackRows.map((x) => ({ ...x, collection: 'feedback' })), ...inquiryRows.map((x) => ({ ...x, collection: 'inquiries' }))];
  usersById = Object.fromEntries(accountRows.map((item) => [item.id, item]));
  servicesById = Object.fromEntries(services.map((item) => [item.id, item]));
  vehicles = vehicleRows; branches = branchRows;
  vehiclesById = Object.fromEntries(vehicles.map((item) => [item.id, item])); branchesById = Object.fromEntries(branches.map((item) => [item.id, item]));
  paymentsByInvoice = Object.fromEntries(payments.map((item) => [item.invoiceId, item]));
  renderBookings(); renderSubmissions(); renderServices(); renderPos(); renderCustomers();
  if (document.body.dataset.adminDashboard !== 'true') renderContinuousSystemLogs();
  renderNotifications();
  await loadReportHistory();
  if (role === 'super_admin' && isSuperAdminDashboard) { renderAccounts(); await loadSettings(); }
  renderDashboardOverview();
}

function watchDashboardNotifications() {
  if (!isDashboardPage) return;
  onSnapshot(collection(db, 'notifications'), (snapshot) => {
    adminRows.notifications = snapshot.docs.map((item) => ({ id: item.id, ...item.data() }));
    renderNotifications();
  }, (error) => console.error('Dashboard notifications could not be refreshed:', error));
  onSnapshot(collection(db, 'reports'), (snapshot) => {
    reportHistoryData = snapshot.docs.map((item) => ({ id: item.id, ...item.data() }))
      .sort((a, b) => String(b.createdAt || b.id).localeCompare(String(a.createdAt || a.id)));
    renderDashboardOverview();
  }, (error) => console.error('Dashboard report history could not be refreshed:', error));
  onSnapshot(collection(db, 'invoices'), (snapshot) => {
    const previousById = Object.fromEntries(invoices.map((item) => [item.id, item]));
    invoices = snapshot.docs.map((document) => ({ ...previousById[document.id], ...document.data(), id: document.id }));
    renderPos();
    renderDashboardOverview();
  }, (error) => console.error('Dashboard sales analytics could not be refreshed:', error));
}

function watchServiceCatalog() {
  onSnapshot(collection(db, 'services'), (snapshot) => {
    services = snapshot.docs.map((item) => ({ id: item.id, ...item.data() }));
    servicesById = Object.fromEntries(services.map((item) => [item.id, item]));
    adminRows.services = services;
    renderServices();
    renderBookings();
    renderDashboardOverview();
  }, (error) => console.error('Service catalog could not be refreshed:', error));
}

function normalizeBookingFilterStatus(value) {
  const status = String(value || '').toLowerCase().trim().replace(/[_-]+/g, ' ').replace(/\s+/g, ' ');
  if (['pending checkout', 'unpaid'].includes(status)) return 'pending';
  if (status === 'paid') return 'completed';
  if (status === 'overdue') return 'payment overdue';
  return status;
}

function renderBookings() {
  const search = $('#booking-search-input').value.trim().toLowerCase();
  const startDate = $('#booking-start-date').value;
  const endDate = $('#booking-end-date').value;
  const statusFilter = normalizeBookingFilterStatus($('#booking-status-filter').value);
  const rows = bookings.filter((booking) => {
    const user = usersById[booking.customerId] || { name: booking.customerName, email: booking.customerEmail, phone: booking.contactNumber };
    const status = normalizeBookingFilterStatus(booking.status || 'pending');
    const matchesStatus = !statusFilter || status === statusFilter;
    const bookingDate = String(booking.date || '').slice(0, 10);
    const matchesDateRange = (!startDate || (bookingDate && bookingDate >= startDate))
      && (!endDate || (bookingDate && bookingDate <= endDate));
    return matchesStatus && matchesDateRange && (!search || `${user.name || user.username || ''} ${user.email || ''}`.toLowerCase().includes(search));
  });
  const renderedRows = rows.map((booking) => {
    const user = usersById[booking.customerId] || { name: booking.customerName, email: booking.customerEmail, phone: booking.contactNumber };
    const service = bookingServiceName(booking);
    const invoice = invoices.find((item) => item.id === `web_${booking.id}` || item.bookingId === booking.id);
    const sessionId = String(invoice?.latestPaymentSessionId || '');
    const checkout = payments.find((item) => item.id === `paymongo_${sessionId}` || item.checkoutSessionId === sessionId);
    const activeCheckout = Boolean(sessionId && String(checkout?.status || '').toLowerCase() === 'pending');
    const invoicePaid = String(invoice?.status || '').toLowerCase() === 'paid';
    const isClosed = ['cancelled', 'completed', 'paid'].includes(String(booking.status).toLowerCase()) || invoicePaid;
    const canApprove = String(booking.status).toLowerCase() === 'pending';
    const canComplete = ['confirmed', 'in_progress'].includes(String(booking.status).toLowerCase());
    const normalizedStatus = normalizeBookingFilterStatus(booking.status || 'pending');
    const isCancelled = normalizedStatus === 'cancelled';
    const invoiceStatus = String(invoice?.status || '').toLowerCase();
    const bookingStatus = String(booking.status || 'pending').toLowerCase();
    const bookingStatusKey = bookingStatus.replace(/[^a-z0-9-]/g, '-');
    const invoiceStatusKey = invoiceStatus.replace(/[^a-z0-9-]/g, '-');
    const state = `<span class="status ${esc(bookingStatusKey)}">${esc(bookingStatus.replaceAll('_', ' '))}</span>${invoiceStatus && invoiceStatus !== bookingStatus ? ` <span class="status ${esc(invoiceStatusKey)}">invoice ${esc(invoiceStatus)}</span>` : ''}${activeCheckout ? ' <span class="status pending">checkout</span>' : ''}`;

    if (isDashboardPage) {
      const vehicle = vehiclesById[booking.vehicleId] || {};
      const branch = branchesById[booking.branchId] || {};
      const serviceRecord = servicesById[bookingServiceId(booking)] || {};
      const branchKey = String(booking.branchId || '').toLowerCase().replace(/[\s-]+/g, '_');
      const branchName = branch.name || ({ cabuyao_main: 'Cabuyao', cabuyao: 'Cabuyao', santa_rosa: 'Santa Rosa', santarosa: 'Santa Rosa' }[branchKey] || booking.branchId);
      const customerName = user.name || user.username || booking.customerName || booking.customerId;
      const customerEmail = user.email || booking.customerEmail;
      const expandedId = `booking-details-${esc(booking.id)}`;
      const field = (label, value) => `<div class="booking-detail-field"><span>${label}</span><strong>${esc(value || '—')}</strong></div>`;
      const reasonField = (label, value) => String(value || '').trim()
        ? `<div class="booking-detail-reason"><span>${label}</span><p>${esc(value)}</p></div>` : '';
      const menuItems = [
        !isClosed && ['pending', 'confirmed'].includes(bookingStatus) ? `<button type="button" class="reschedule-booking" data-id="${esc(booking.id)}">Update appointment slot</button>` : '',
        !isClosed && bookingStatus !== 'confirmed' ? `<button type="button" class="cancel-booking" data-id="${esc(booking.id)}">Expire checkout &amp; release slot</button>` : '',
        !isClosed ? `<button type="button" class="reject-cancellation" data-id="${esc(booking.id)}">Reject request</button>` : '',
        canComplete ? `<button type="button" class="complete-booking" data-id="${esc(booking.id)}">Complete service</button>` : '',
        canApprove ? `<button type="button" class="approve-booking" data-id="${esc(booking.id)}">Approve booking</button>` : ''
      ].filter(Boolean).join('');
      const actionColumnHtml = isCancelled
        ? '<div class="booking-actions-none" aria-hidden="true"></div>'
        : `<details class="booking-actions-dropdown"><summary class="actions-dropdown-btn">Actions ▾</summary><div class="booking-actions-menu">${menuItems || '<span>No actions available</span>'}</div></details>`;
      return `<article class="booking-item-row" data-booking-row data-id="${esc(booking.id)}" tabindex="0" aria-expanded="false" aria-controls="${expandedId}">
        <div class="booking-row-summary">
          <div class="booking-row-customer"><strong>${esc(customerName)}</strong></div>
          <div class="booking-row-email">${esc(customerEmail || '—')}</div>
          <div class="booking-service-info"><span class="service-title">${esc(service)}</span><span class="service-datetime">${esc(booking.date)} - ${esc(booking.timeSlot)}</span></div>
          <div class="booking-row-branch">${esc(branchName || '—')}</div>
          <span class="booking-status-pill status ${esc(bookingStatusKey)}">${esc(bookingStatus.replaceAll('_', ' '))}</span>
          ${actionColumnHtml}
        </div>
        <div class="booking-details-accordion" id="${expandedId}" aria-hidden="true"><div class="booking-details-grid">
          ${field('Customer name', user.name || user.username || booking.customerName || booking.customerId)}
          ${field('Customer email', user.email || booking.customerEmail)}
          ${field('Branch', branch.name || booking.branchId)}
          ${field('Branch address', branch.address)}
          ${field('Service price', money(serviceRecord.price))}
          ${field('Vehicle plate number', vehicle.plateNumber)}
          ${field('Vehicle make', vehicle.make)}
          ${field('Vehicle model', vehicle.model)}
          ${field('Vehicle year', vehicle.year)}
          ${reasonField('Cancellation request reason', booking.cancellationRequestReason)}
          ${reasonField('Admin cancellation reason', booking.cancellationReason)}
        </div></div>
      </article>`;
    }

    return `<tr><td>${esc(user.name || user.username || booking.customerId)}</td><td>${esc(user.email)}</td><td>${esc(booking.date)} ${esc(booking.timeSlot)}</td><td>${esc(service)}</td><td>${esc(booking.branchId)}</td><td>${state}</td><td><button class="action-btn booking-details" data-id="${esc(booking.id)}">View</button>${canApprove ? ` <button class="action-btn approve-booking" data-id="${esc(booking.id)}">Approve booking</button>` : ''}${!isClosed ? `${bookingStatus !== 'confirmed' ? ` <button class="action-btn cancel-booking" data-id="${esc(booking.id)}">${activeCheckout ? 'Expire checkout & release slot' : 'Cancel booking / release slot'}</button>` : ''} <button class="action-btn reject-cancellation" data-id="${esc(booking.id)}">Reject request</button>` : ''}${canComplete ? ` <button class="action-btn complete-booking" data-id="${esc(booking.id)}">Complete service</button>` : ''}</td></tr>`;
  });
  if (isDashboardPage && modulePaginators.bookings) {
    modulePaginators.bookings.setItems(renderedRows);
  } else {
    $('#bookingResults').innerHTML = renderedRows.length
      ? renderedRows.join('')
      : '<tr><td colspan="7">No matching bookings found.</td></tr>';
  }
}

function toggleBookingRow(row) {
  const expanded = row.classList.toggle('expanded');
  row.setAttribute('aria-expanded', String(expanded));
  row.querySelector('.booking-details-accordion')?.setAttribute('aria-hidden', String(!expanded));
}

async function bookingAction(event) {
  const button = event.target.closest('button'); if (!button) return;
  const booking = bookings.find((item) => item.id === button.dataset.id); if (!booking) return;
  if (button.classList.contains('reschedule-booking')) {
    const date = await prompt('New appointment date (YYYY-MM-DD):', booking.date || '');
    if (!date) return;
    const time = await prompt('New appointment time (for example, 09:30 AM):', booking.timeSlot || '');
    if (!time) return;
    button.disabled = true;
    try {
      const result = await api('api/admin-workflow.php', { action: 'reschedule_booking', bookingId: booking.id, date, timeSlot: time });
      alert(result.message);
      await loadCoreData();
    } catch (error) { alert(readableError(error)); button.disabled = false; }
    return;
  }
  if (button.classList.contains('approve-booking')) {
    button.disabled = true;
    try { const result = await api('api/admin-workflow.php', { action: 'approve_booking', bookingId: booking.id }); alert(result.message); await loadCoreData(); }
    catch (error) { alert(readableError(error)); button.disabled = false; }
    return;
  }
  if (button.classList.contains('complete-booking')) {
    const rawAmount = await prompt('Downpayment already collected (PHP). Enter 0 if none:', '0', { inputType: 'number' });
    if (rawAmount === null || !Number.isFinite(Number(rawAmount)) || Number(rawAmount) < 0) return alert('Enter a valid downpayment amount.');
    const amount = Number(rawAmount);
    const method = amount > 0 ? await prompt('How was the downpayment paid?', 'cash', { choices: ['cash', 'card', 'e-wallet'] }) : '';
    if (amount > 0 && !['cash', 'card', 'e-wallet'].includes(String(method).toLowerCase())) return alert('Choose cash, card, or e-wallet.');
    button.disabled = true;
    try { await api('api/admin-workflow.php', { action:'complete_booking', bookingId:booking.id, downpaymentAmount:amount, paymentMethod:String(method || '') }); await loadCoreData(); }
    catch (error) { alert(readableError(error)); button.disabled = false; }
    return;
  }
  if (button.classList.contains('booking-details')) {
    const user = usersById[booking.customerId] || {};
    const vehicle = vehiclesById[booking.vehicleId] || {};
    const branch = branchesById[booking.branchId] || {};
    const serviceRecord = servicesById[bookingServiceId(booking)] || {};
    $('#detailContent').innerHTML = `<h2>Booking ${esc(booking.id)}</h2><dl>${[['Customer', user.name || user.username], ['Email', user.email], ['Contact', user.phone || user.contact], ['Date', booking.date], ['Time', booking.timeSlot], ['Service', bookingServiceName(booking)], ['Service price', money(serviceRecord.price)], ['Branch', branch.name || booking.branchId], ['Branch address', branch.address], ['Status', booking.status], ['Vehicle plate', vehicle.plateNumber], ['Vehicle', `${vehicle.make || ''} ${vehicle.model || ''} ${vehicle.year || ''}`], ['Cancellation request reason', booking.cancellationRequestReason], ['Admin cancellation reason', booking.cancellationReason]].map(([key, val]) => `<dt>${esc(key)}</dt><dd>${esc(val || '—')}</dd>`).join('')}</dl>`;
    $('#detailDialog').showModal(); return;
  }
  if (button.classList.contains('reject-cancellation')) {
    const reason = await prompt('Enter the verification or rejection reply to email the customer:');
    if (!reason?.trim()) return;
    button.disabled = true;
    try { const result = await api('api/admin-workflow.php', { action: 'reject_cancellation', bookingId: booking.id, reason }); alert(result.message); }
    catch (error) { alert(readableError(error)); button.disabled = false; }
    return;
  }
  if (!button.classList.contains('cancel-booking')) return;
  const reason = await prompt('Enter the cancellation reason. Any active PayMongo checkout will be expired and the slot released:');
  if (!reason?.trim()) return;
  button.disabled = true;
  try { const result = await api('api/admin-workflow.php', { action: 'cancel_booking', bookingId: booking.id, reason }); alert(result.message); await loadCoreData(); }
  catch (error) { alert(readableError(error)); button.disabled = false; }
}

async function sendVerificationReply(event) {
  event.preventDefault(); const form = event.currentTarget; const data = Object.fromEntries(new FormData(form));
  try { const result = await api('api/admin-workflow.php', { action: 'send_email', ...data }); showMessage(form, result.message); form.reset(); }
  catch (error) { showMessage(form, error.message); }
}

function renderSubmissions() {
  $('#inquiryResults').innerHTML = submissions.length ? submissions.map((item, index) => {
    const detailsId = `inquiry-message-${index}`;
    const ticket = item.ticketNumber || item.id;
    const type = item.type || (item.collection === 'feedback' ? 'Feedback' : item.collection === 'manualRequests' ? 'Manual Request' : 'Inquiry');
    const branch = branchesById[item.branchId]?.name || item.branchId;
    const branchAndTime = [branch, item.requestedDate && `${item.requestedDate}${item.requestedTime ? ` · ${item.requestedTime}` : ''}`].filter(Boolean);
    return `<tr class="inquiry-ticket-row" data-inquiry-row tabindex="0" aria-expanded="false" aria-controls="${detailsId}"><td>${esc(ticket)}</td><td>${esc(type)}</td><td>${esc(item.name)}<br>${esc(item.email)}<br>${esc(item.contactNumber)}</td><td>${branchAndTime.map(esc).join('<br>') || '—'}</td></tr><tr class="inquiry-message-row"><td colspan="4"><div class="inquiry-message-accordion" id="${detailsId}"><div class="inquiry-accordion-details"><div class="inquiry-message-body"><strong>Message</strong><p>${esc(item.message || 'No message provided.')}</p>${item.requestedService ? `<p><strong>Requested service:</strong> ${esc(item.requestedService)}</p>` : ''}${item.attachmentName ? `<button class="view-attachment" data-ticket="${esc(ticket)}" type="button">View ${esc(item.attachmentName)}</button>` : ''}</div><div class="inquiry-accordion-actions"><button class="submission-decision btn-approve-inquiry" data-id="${esc(item.id)}" data-collection="${esc(item.collection)}" data-decision="approved">Approve</button><button class="submission-decision btn-reject-inquiry" data-id="${esc(item.id)}" data-collection="${esc(item.collection)}" data-decision="rejected">Reject</button></div></div></div></td></tr>`;
  }).join('') : '<tr><td colspan="4">No inquiries or feedback yet.</td></tr>';
  if (isDashboardPage && modulePaginators.inquiries) {
    const renderedItems = [];
    const children = $('#inquiryResults').children;
    if (submissions.length) for (let index = 0; index < children.length; index += 2) {
      renderedItems.push(children[index].outerHTML + (children[index + 1]?.outerHTML || ''));
    }
    modulePaginators.inquiries.setItems(renderedItems);
  }
}

function toggleInquiryRow(row) {
  const expanded = row.getAttribute('aria-expanded') !== 'true';
  row.setAttribute('aria-expanded', String(expanded));
  row.nextElementSibling?.classList.toggle('expanded', expanded);
}

async function submissionAction(event) {
  const attachment = event.target.closest('.view-attachment');
  if (attachment) {
    try {
      const response = await fetch(`api/feedback-attachment.php?ticket=${encodeURIComponent(attachment.dataset.ticket)}`, { headers: { Authorization: `Bearer ${await actor.getIdToken()}` } });
      if (!response.ok) throw new Error('Attachment could not be opened.');
      const url = URL.createObjectURL(await response.blob()); window.open(url, '_blank', 'noopener'); setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (error) { alert(error.message); }
    return;
  }
  const row = event.target.closest('[data-inquiry-row]');
  if (row && !event.target.closest('button, a, input, select, textarea')) {
    toggleInquiryRow(row);
    return;
  }
  const button = event.target.closest('.submission-decision'); if (!button) return;
  const record = submissions.find((item) => item.id === button.dataset.id); if (!record) return;
  const decision = button.dataset.decision;
  const message = await prompt(`Email response for ${record.ticketNumber || record.id}:`, decision === 'approved' ? 'Your request has been approved. Please contact the branch to confirm the appointment details.' : 'We are unable to approve your request. Please reply to this email if you need assistance.', { multiline: true });
  if (!message?.trim()) return;
  button.disabled = true;
  try { const result = await api('api/admin-workflow.php', { action: 'submission_decision', collection: button.dataset.collection, ticketNumber: record.ticketNumber || record.id, decision, message }); alert(result.message); await loadCoreData(); }
  catch (error) { alert(error.message); button.disabled = false; }
}

function renderServices() {
  $('#serviceResults').innerHTML = services.map(renderServiceCard).join('') || 'No services configured.';
}

function renderServiceCard(item) {
  return `<article id="service-card-${esc(item.id)}" class="tool-card service-card-item" data-id="${esc(item.id)}" data-editing="false"><div class="service-content-body"><h3 class="service-title">${esc(item.name || item.id)}</h3><strong class="service-price">${money(item.price)}</strong><p class="service-desc">${esc(item.description || '')}</p><p class="service-duration">${esc(formatServiceDuration(item.duration))}</p></div><div class="service-card-actions"><button type="button" class="btn-service-toggle-edit" data-id="${esc(item.id)}">Edit</button><button type="button" class="btn-service-remove" data-id="${esc(item.id)}">Remove</button></div></article>`;
}

function formatServiceDuration(value) {
  const digits = String(value ?? '').match(/\d+/)?.[0] || '';
  if (!digits) return '';
  const hours = Number(digits);
  return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
}

async function saveService(event) {
  event.preventDefault(); const form = event.currentTarget; const data = Object.fromEntries(new FormData(form));
  const id = data.id.trim() || data.name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
  if (!id || !Number.isFinite(Number(data.price)) || Number(data.price) <= 0) return showMessage(form, 'Enter a service name and a price above zero.');
  try {
    await api('api/admin-workflow.php', { action: 'save_service', serviceId: id, name: data.name.trim(), price: Number(data.price), duration: data.duration.trim(), description: data.description.trim() });
    form.reset(); showMessage(form, 'Service saved.'); await loadCoreData();
  } catch (error) { showMessage(form, readableError(error)); }
}

async function serviceAction(event) {
  const button = event.target.closest('button'); if (!button) return;
  const item = services.find((entry) => entry.id === button.dataset.id); if (!item) return;
  if (button.classList.contains('btn-service-toggle-edit')) {
    enterServiceInlineEdit(item.id);
  } else if (button.classList.contains('btn-service-action')) {
    await exitServiceInlineEdit(item.id, button.dataset.state === 'save');
  } else if (button.classList.contains('btn-service-remove') && confirm(`Remove ${item.name} from the active catalog? Existing invoice snapshots will remain.`)) {
    try {
      await api('api/admin-workflow.php', { action: 'delete_service', serviceId: item.id });
      services = services.filter((entry) => entry.id !== item.id);
      delete servicesById[item.id];
      document.getElementById(`service-card-${item.id}`)?.remove();
      if (!services.length) $('#serviceResults').textContent = 'No services configured.';
    } catch (error) { alert(readableError(error)); }
  }
}

function enterServiceInlineEdit(serviceId) {
  const card = document.getElementById(`service-card-${serviceId}`);
  const item = services.find((entry) => entry.id === serviceId);
  if (!card || !item) return;
  if (card.dataset.editing === 'true') return;
  const originalData = {
    name: String(item.name || item.id || '').trim(),
    price: String(Number(item.price || 0)),
    desc: String(item.description || '').trim(),
    duration: String(item.duration ?? '').match(/\d+/)?.[0] || ''
  };
  card.dataset.original = JSON.stringify(originalData);
  card.dataset.editing = 'true';
  card.classList.add('is-editing');
  card.querySelector('.service-content-body').innerHTML = `
    <input type="text" class="form-control form-control-sm mb-2 service-edit-field service-input-name" value="${esc(originalData.name)}" placeholder="Service Name" aria-label="Service name">
    <input type="text" inputmode="decimal" class="form-control form-control-sm mb-2 service-edit-field service-input-price" value="${esc(originalData.price)}" placeholder="Price (₱)" aria-label="Price" oninput="this.value = this.value.replace(/[^0-9.]/g, '')">
    <textarea class="form-control form-control-sm mb-2 service-edit-field service-input-desc" rows="2" placeholder="Description" aria-label="Description">${esc(originalData.desc)}</textarea>
    <input type="text" inputmode="numeric" class="form-control form-control-sm mb-2 service-edit-field service-input-duration" value="${esc(originalData.duration)}" placeholder="Duration in hours" aria-label="Duration in hours" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
  `;
  card.querySelector('.service-card-actions').innerHTML = `<button type="button" class="btn-service-action btn-cancel-state" data-id="${esc(serviceId)}" data-state="cancel">Cancel</button><button type="button" class="btn-service-remove" data-id="${esc(serviceId)}">Remove</button>`;
  card.querySelectorAll('.service-edit-field').forEach((input) => {
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Backspace') event.stopPropagation();
    });
    input.addEventListener('input', () => checkServiceDirtyState(serviceId));
    input.addEventListener('keyup', () => checkServiceDirtyState(serviceId));
  });
}

function checkServiceDirtyState(serviceId) {
  const card = document.getElementById(`service-card-${serviceId}`);
  if (!card || card.dataset.editing !== 'true' || !card.dataset.original) return;
  const original = JSON.parse(card.dataset.original);
  const current = {
    name: card.querySelector('.service-input-name')?.value.trim() || '',
    price: card.querySelector('.service-input-price')?.value.trim() || '',
    desc: card.querySelector('.service-input-desc')?.value.trim() || '',
    duration: card.querySelector('.service-input-duration')?.value.trim() || ''
  };
  const isDirty = Object.keys(original).some((key) => current[key] !== original[key]);
  const stateButton = card.querySelector('.btn-service-action');
  if (!stateButton) return;
  stateButton.dataset.state = isDirty ? 'save' : 'cancel';
  stateButton.textContent = isDirty ? 'Save Changes' : 'Cancel';
  stateButton.classList.toggle('btn-save-state', isDirty);
  stateButton.classList.toggle('btn-cancel-state', !isDirty);
}

async function exitServiceInlineEdit(serviceId, shouldSave) {
  const card = document.getElementById(`service-card-${serviceId}`);
  const item = services.find((entry) => entry.id === serviceId);
  if (!card || !item) return;
  if (!shouldSave) {
    card.outerHTML = renderServiceCard(item);
    return;
  }
  const name = card.querySelector('.service-input-name')?.value.trim() || '';
  const rawPrice = card.querySelector('.service-input-price')?.value.trim() || '';
  const price = Number(rawPrice);
  const description = card.querySelector('.service-input-desc')?.value.trim() || '';
  const duration = card.querySelector('.service-input-duration')?.value.trim() || '';
  if (!name) return alert('Enter a service name.');
  if (!rawPrice || !Number.isFinite(price) || price <= 0) return alert('Enter a valid price above zero.');
  if (rawPrice.includes('..') || (rawPrice.match(/\./g) || []).length > 1) return alert('Enter a valid numeric price.');
  if (duration && !/^\d+$/.test(duration)) return alert('Duration must contain digits only.');
  const buttonEl = card.querySelector('.btn-service-action');
  if (buttonEl) buttonEl.disabled = true;
  try {
    await api('api/admin-workflow.php', { action: 'save_service', serviceId, name, price, description, duration });
    Object.assign(item, { name, price, description, duration });
    servicesById[serviceId] = item;
    const updatedCard = document.getElementById(`service-card-${serviceId}`);
    if (updatedCard) updatedCard.outerHTML = renderServiceCard(item);
  } catch (error) {
    alert(readableError(error));
    if (buttonEl) buttonEl.disabled = false;
  }
}

function invoiceDate(item) { const value = item.createdAt?.toDate?.() || (item.createdAt ? new Date(item.createdAt) : null); return value && !Number.isNaN(value.getTime()) ? value : null; }
function formatAuditTimestamp(value) {
  const date = value?.toDate?.() || (value ? new Date(value) : null);
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true }).replace(',', ' -').toLowerCase() : 'No audit timestamp';
}
function transactionMethod(invoice, payment) {
  const raw = String(payment?.method || invoice.paymentMethod || '').toLowerCase();
  if (raw.includes('cash')) return 'cash';
  if (raw.includes('card')) return 'card';
  if (raw.includes('gcash')) return 'gcash';
  if (raw.includes('maya') || raw.includes('grab') || raw.includes('qr') || raw.includes('wallet')) return 'e-wallet';
  return raw.includes('paymongo') ? 'e-wallet' : (raw || 'unknown');
}

function renderPos() {
  const from = $('#pos-start-date').value, to = $('#pos-end-date').value, method = $('#pos-method-filter').value, cashierSearch = $('#pos-cashier-search').value.trim().toLowerCase(), status = $('#pos-status-filter').value.toLowerCase();
  const rows = invoices.filter((invoice) => invoice.source === 'pos').filter((invoice) => {
    const date = invoiceDate(invoice); const payment = paymentsByInvoice[invoice.id];
    const cashier = usersById[invoice.createdBy]?.name || usersById[invoice.createdBy]?.email || invoice.createdBy || '';
    return (!from || (date && date >= new Date(`${from}T00:00:00`))) && (!to || (date && date <= new Date(`${to}T23:59:59`)))
      && (!method || transactionMethod(invoice, payment) === method) && (!cashierSearch || String(cashier).toLowerCase().includes(cashierSearch))
      && (!status || String(invoice.status || '').toLowerCase() === status);
  });
  $('#posResults').innerHTML = rows.map((invoice) => {
    const payment = paymentsByInvoice[invoice.id]; const date = invoiceDate(invoice); const cashier = usersById[invoice.createdBy]?.name || usersById[invoice.createdBy]?.email || invoice.createdBy || '—';
    const statusKey = String(invoice.status || '').toLowerCase().replace(/[^a-z0-9-]/g, '-');
    const total = invoice.totalAmount ?? invoice.finalAmount ?? 0;
    const serviceRows = (invoice.serviceDetails || []).map((line) => {
      const quantity = Number(line.quantity || 1);
      const unitPrice = Number(line.price || 0);
      const serviceName = line.name || servicesById[line.serviceId || line.service_id]?.name || line.serviceId || line.service_id || 'Service';
      return `<tr><td>${esc(serviceName)}</td><td>${quantity}</td><td>${money(unitPrice)}</td><td>${money(line.lineTotal ?? unitPrice * quantity)}</td></tr>`;
    }).join('') || '<tr><td colspan="4">No itemized services recorded.</td></tr>';
    const customer = invoice.customerName || (invoice.customerId ? usersById[invoice.customerId]?.name || usersById[invoice.customerId]?.username || invoice.customerId : 'Walk-in customer');
    const dateText = date ? `${date.toLocaleDateString('en-GB')} · ${date.toLocaleTimeString('en-US').toLowerCase()}` : '—';
    const isPaid = String(invoice.status || '').toLowerCase() === 'paid';
    const rowAction = isAdminDashboard
      ? (isPaid ? `<button type="button" class="btn-pos-void-action" data-invoice-id="${esc(invoice.id)}">Void</button>` : '—')
      : isSuperAdminDashboard
        ? ''
        : '<button type="button" class="pos-actions-button" aria-expanded="false">Actions ▾</button>';
    const responsibleId = invoice.lastAuditBy || invoice.voidedBy || invoice.flaggedBy || invoice.createdBy;
    const responsibleName = invoice.lastAuditByName || usersById[responsibleId]?.name || usersById[responsibleId]?.username || usersById[responsibleId]?.email || responsibleId || 'Unknown admin';
    const auditAt = invoice.lastAuditAt || invoice.voidedAt || invoice.flaggedAt || invoice.updatedAt || invoice.createdAt;
    const auditFooter = isSuperAdminDashboard ? `<div class="pos-audit-expanded-footer"><div class="audit-trail-info"><div><strong>Audit Trail:</strong> Processed by Admin (${esc(responsibleName)})</div><div><strong>Last Audit Timestamp:</strong> ${esc(formatAuditTimestamp(auditAt))}</div><div><strong>Flag Status:</strong> ${invoice.isFlagged ? '🚩 Flagged' : 'Normal'}${invoice.flagReason ? ` · ${esc(invoice.flagReason)}` : ''}</div></div><details class="pos-audit-actions"><summary class="btn-super-pos-actions">Actions ▾</summary><div class="pos-audit-actions-menu"><button type="button" class="audit-action-flag" data-super-pos-action="flag" data-invoice-id="${esc(invoice.id)}">⚑ ${invoice.isFlagged ? 'Remove Flag' : 'Toggle Flag'}</button><button type="button" class="audit-action-void" data-super-pos-action="void" data-invoice-id="${esc(invoice.id)}"${isPaid ? '' : ' disabled'}>Void Transaction</button><button type="button" data-super-pos-action="export" data-invoice-id="${esc(invoice.id)}">Export Audit Summary</button></div></details></div>` : '';
    return `<tr class="pos-main-row" tabindex="0"><td><strong class="pos-invoice-id">${esc(invoice.id)}</strong><span class="pos-invoice-time">${esc(dateText)}</span></td><td>${esc(branchesById[invoice.branchId]?.name || invoice.branchId || '—')}</td><td>${esc(cashier)}</td><td>${esc(transactionMethod(invoice, payment))}</td><td><span class="pos-status-text ${esc(statusKey)}">${esc(invoice.status || 'unknown')}</span></td><td class="pos-amount">${money(total)}</td><td>${rowAction}</td></tr><tr class="pos-detail-row"><td colspan="7"><div class="pos-accordion-panel"><p class="pos-accordion-summary">Customer: ${esc(customer)} · Cashier: ${esc(cashier)}</p><div class="pos-detail-table-scroll"><table class="pos-detail-table"><thead><tr><th>Service</th><th>Qty</th><th>Unit</th><th>Line total</th></tr></thead><tbody>${serviceRows}</tbody></table></div><strong class="pos-total-summary">Total ${money(total)}</strong>${payment?.referenceNumber || invoice.paymentReference ? `<p class="pos-payment-reference">Payment reference: ${esc(payment?.referenceNumber || invoice.paymentReference)}</p>` : ''}${auditFooter}</div></td></tr>`;
  }).join('') || '<tr><td class="pos-empty-state" colspan="7">No POS transactions match these filters.</td></tr>';
  if (isDashboardPage && modulePaginators.pos) {
    const renderedItems = [];
    const children = $('#posResults').children;
    if (rows.length) for (let index = 0; index < children.length; index += 2) {
      renderedItems.push(children[index].outerHTML + (children[index + 1]?.outerHTML || ''));
    }
    modulePaginators.pos.setItems(renderedItems);
  }
}

async function voidAdminPosTransaction(invoiceId) {
  const invoice = invoices.find((item) => item.id === invoiceId && item.source === 'pos');
  if (!invoice || String(invoice.status || '').toLowerCase() !== 'paid') return;
  if (!confirm(`Void paid POS transaction ${invoiceId}?`)) return;
  try {
    await api('api/admin-workflow.php', { action: 'void_pos_transaction', invoiceId });
    invoice.status = 'voided';
    invoice.voidedAt = new Date().toISOString();
    invoice.voidedBy = actor.uid;
    renderPos();
  } catch (error) {
    alert(readableError(error));
  }
}

async function handleSuperAdminFlag(invoiceId) {
  const invoice = invoices.find((item) => item.id === invoiceId && item.source === 'pos');
  if (!isSuperAdminDashboard || !invoice) return;
  const isFlagged = Boolean(invoice.isFlagged);
  const reason = prompt(`${isFlagged ? 'Reason for removing the flag from' : 'Reason for flagging'} ${invoiceId}:`, invoice.flagReason || '');
  if (reason === null) return;
  if (!reason.trim()) return alert('Enter a reason to record this audit action.');
  try {
    await api('api/admin-workflow.php', { action: 'flag_pos_transaction', invoiceId, isFlagged: !isFlagged, reason: reason.trim() });
    invoice.isFlagged = !isFlagged;
    invoice.flagReason = !isFlagged ? reason.trim() : '';
    invoice.lastAuditAt = new Date().toISOString();
    invoice.lastAuditBy = actor.uid;
    invoice.lastAuditByName = usersById[actor.uid]?.name || usersById[actor.uid]?.username || actor.email;
    renderPos();
  } catch (error) {
    alert(readableError(error));
  }
}

async function handleSuperAdminVoid(invoiceId) {
  const invoice = invoices.find((item) => item.id === invoiceId && item.source === 'pos');
  if (!isSuperAdminDashboard || !invoice || String(invoice.status || '').toLowerCase() !== 'paid') return;
  if (!confirm(`Void paid POS transaction ${invoiceId}?`)) return;
  try {
    await api('api/admin-workflow.php', { action: 'void_pos_transaction', invoiceId });
    invoice.status = 'voided';
    invoice.voidedAt = new Date().toISOString();
    invoice.voidedBy = actor.uid;
    invoice.lastAuditAt = invoice.voidedAt;
    invoice.lastAuditBy = actor.uid;
    invoice.lastAuditByName = usersById[actor.uid]?.name || usersById[actor.uid]?.username || actor.email;
    renderPos();
  } catch (error) {
    alert(readableError(error));
  }
}

function exportSingleTransactionAudit(invoiceId) {
  const invoice = invoices.find((item) => item.id === invoiceId && item.source === 'pos');
  if (!invoice || !isSuperAdminDashboard) return;
  const logs = (adminRows.activity_logs || []).filter((item) => item.invoiceId === invoiceId);
  const rows = [{ recordType: 'transaction', ...invoice }, ...logs.map((item) => ({ recordType: 'audit_log', ...item }))];
  downloadText(`pos-audit-${invoiceId}.csv`, csv(rows));
}

function dateKey(row) {
  const created = row.createdAt?.toDate?.()?.toISOString?.() || row.createdAt;
  const date = row.date || created || row.updatedAt || '';
  return String(date).slice(0, 10);
}
function downloadText(name, content, type = 'text/csv;charset=utf-8') {
  const blob = new Blob(['\ufeff', content], { type }); const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = name; link.click(); setTimeout(() => URL.revokeObjectURL(link.href), 30000);
}
function csv(rows, headerOverride = null) {
  if (!rows.length && !headerOverride?.length) return '';
  const headers = headerOverride || [...new Set(rows.flatMap((row) => Object.keys(row)))];
  const cell = (value) => {
    let text = String(typeof value === 'object' && value !== null ? JSON.stringify(value) : value ?? '');
    if (/^[=+@\-\t\r]/.test(text)) text = `'${text}`;
    return `"${text.replaceAll('"', '""')}"`;
  };
  return [headers.map(cell).join(','), ...rows.map((row) => headers.map((key) => cell(row[key])).join(','))].join('\r\n');
}

function reportBucket(row, period) {
  const rawDate = dateKey(row);
  if (!rawDate) return 'Unknown';
  if (period === 'monthly') return rawDate.slice(0, 7);
  if (period === 'weekly') {
    const date = new Date(`${rawDate}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() - ((date.getUTCDay() + 6) % 7));
    return `Week of ${date.toISOString().slice(0, 10)}`;
  }
  return rawDate;
}

async function ensureChartJs() {
  if (window.Chart) return;
  if (!window.reportChartLoader) {
    window.reportChartLoader = new Promise((resolve) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js';
      script.onload = () => resolve(true);
      script.onerror = () => resolve(false);
      document.head.append(script);
    });
  }
  await window.reportChartLoader;
}

async function createReportChart(rows, period, type) {
  const canvas = document.createElement('canvas');
  canvas.width = 1600;
  canvas.height = 720;
  const buckets = {};
  rows.forEach((row) => {
    const key = reportBucket(row, period);
    const value = type === 'sales' ? Number(row.totalAmount ?? row.finalAmount ?? 0) : 1;
    buckets[key] = (buckets[key] || 0) + (Number.isFinite(value) ? value : 0);
  });
  const entries = Object.entries(buckets).sort(([a], [b]) => a.localeCompare(b));
  await ensureChartJs();
  if (window.Chart) {
    const chart = new window.Chart(canvas.getContext('2d'), {
      type: period === 'daily' ? 'line' : 'bar',
      data: { labels: entries.map(([label]) => label), datasets: [{
        label: ({ bookings: 'Bookings', feedback: 'Feedback submissions', inquiries: 'Customer inquiries', sales: 'Sales (PHP)' })[type] || 'Analytics',
        data: entries.map(([, count]) => count),
        borderColor: '#1267c5', backgroundColor: period === 'daily' ? 'rgba(18,103,197,.18)' : '#1267c5',
        fill: period === 'daily', tension: .25, borderWidth: 4
      }] },
      options: { responsive: false, animation: false, devicePixelRatio: 2,
        plugins: { legend: { display: true } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    });
    chart.update('none');
    const image = canvas.toDataURL('image/png');
    chart.destroy();
    return image;
  }
  // Keep PDF generation available if the optional Chart.js CDN is unreachable.
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.strokeStyle = '#d7e0eb'; ctx.lineWidth = 2;
  ctx.beginPath(); ctx.moveTo(100, 50); ctx.lineTo(100, 630); ctx.lineTo(1540, 630); ctx.stroke();
  const max = Math.max(1, ...entries.map(([, value]) => value));
  const slot = 1400 / Math.max(1, entries.length);
  entries.forEach(([label, value], index) => {
    const height = value / max * 500;
    ctx.fillStyle = '#1267c5'; ctx.fillRect(120 + index * slot, 630 - height, Math.max(8, slot * .6), height);
    ctx.fillStyle = '#243447'; ctx.font = '20px sans-serif';
    ctx.fillText(String(value), 120 + index * slot, 610 - height);
    ctx.save(); ctx.translate(120 + index * slot, 665); ctx.rotate(-.35); ctx.fillText(label, 0, 0); ctx.restore();
  });
  return canvas.toDataURL('image/png');
}

function reportDisplayRows(rows, type) {
  return rows.map((row) => {
    const user = usersById[row.customerId] || {};
    const service = servicesById[row.serviceId] || {};
    const branch = branchesById[row.branchId] || {};
    const amount = row.amount ?? row.totalAmount ?? row.finalAmount ?? row.price ?? service.price;
    if (type === 'sales') {
      const payment = paymentsByInvoice[row.id] || {};
      const total = Number(row.totalAmount ?? row.finalAmount ?? 0);
      const amountLabel = `PHP ${total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      return [dateKey(row) || '—', row.id || '—', row.customerName || (row.customerId ? user.name || user.username || row.customerId : 'Walk-in customer'), branch.name || row.branchName || row.branchId || '—', transactionMethod(row, payment), row.status || '—', amountLabel];
    }
    return [dateKey(row) || '—', user.name || user.username || row.customerName || row.customerEmail || row.customerId || '—', service.name || bookingServiceName(row) || '—', branch.name || row.branchName || row.branchId || '—', row.status || row.rating || '—', amount != null ? money(amount) : '—'];
  });
}

function reportDisplayHeaders(type) {
  return type === 'sales'
    ? ['Date', 'Transaction ID', 'Customer', 'Branch', 'Payment Method', 'Status', 'Amount (PHP)']
    : ['Date', 'Customer', 'Service Name', 'Branch', 'Status', 'Amount (PHP)'];
}

function openReportPDFPreview(reportHtml, chartImageData, existingWindow = null) {
  // The caller may reserve the popup during the user gesture so browsers do
  // not block it while the chart is being prepared asynchronously.
  const reportWindow = existingWindow || window.open('', '_blank');
  if (!reportWindow) {
    alert('Please allow popups to generate and print PDF reports.');
    return;
  }
  reportHtml = reportHtml.replace('__REPORT_CHART_IMAGE__', chartImageData);
  reportWindow.document.open();
  reportWindow.document.write(reportHtml);
  reportWindow.document.close();
  reportWindow.focus();

  // Wait for the embedded chart image before opening the print dialog.
  const printWhenReady = async () => {
    const chart = reportWindow.document.querySelector('.chart');
    try { if (chart) await chart.decode(); } catch {}
    reportWindow.setTimeout(() => reportWindow.print(), 500);
  };
  if (reportWindow.document.readyState === 'complete') printWhenReady();
  else reportWindow.addEventListener('load', printWhenReady, { once: true });
}

async function openVisualReport(rows, data, popup) {
  const chartImage = await createReportChart(rows, data.period, data.type);
  const bodyRows = reportDisplayRows(rows, data.type).map((cells) => `<tr>${cells.map((cell) => `<td>${esc(cell)}</td>`).join('')}</tr>`).join('');
  const title = `${({ bookings: 'BOOKINGS', feedback: 'CUSTOMER FEEDBACK', inquiries: 'CUSTOMER INQUIRIES', sales: 'SALES' })[data.type] || 'REPORT'} & ANALYTICS REPORT`;
  const tableHeaders = reportDisplayHeaders(data.type).map((label) => `<th>${esc(label)}</th>`).join('');
  const brand = isSuperAdminDashboard ? 'Super Admin' : 'Admin';
  const reportHtml = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>
    @page{size:A4 landscape;margin:14mm}*{box-sizing:border-box}body{font:12px Arial,sans-serif;color:#172334;margin:0}
    header{border-bottom:3px solid #1267c5;padding:0 0 12px;display:flex;justify-content:space-between;align-items:flex-start}
    h1{font-size:21px;margin:0 0 8px;letter-spacing:.04em}.brand{font-size:14px;font-weight:bold;color:#1267c5}
    .meta{color:#536273;margin:3px 0}.chart{width:100%;height:105mm;object-fit:contain;margin:10px 0}
    h2{font-size:15px;margin:10px 0 6px}table{border-collapse:collapse;width:100%;font-size:10px}th{background:#1267c5;color:#fff;text-align:left}
    th,td{border:1px solid #cbd5e1;padding:6px;overflow-wrap:anywhere}tbody tr:nth-child(even){background:#f2f6fb}
    thead{display:table-header-group}tr{break-inside:avoid}footer{margin-top:8px;color:#64748b;font-size:9px}
    @media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.no-print{display:none!important}}
  </style></head><body><header><div><h1>${esc(title)}</h1><p class="meta">Date range: ${esc(data.start)} to ${esc(data.end)} · Period: ${esc(data.period)}</p><p class="meta">Total records: ${rows.length}</p></div><div class="brand">${esc(brand)}<br>Operations Dashboard</div></header>
  <div class="no-print" style="margin:12px 0;text-align:right"><button onclick="window.print()" style="background:#1267c5;color:#fff;border:0;padding:8px 18px;border-radius:6px;cursor:pointer;font-weight:bold">Print / Save as PDF</button></div><img class="chart" src="__REPORT_CHART_IMAGE__" alt="${esc(data.period)} report chart"><h2>Report details</h2><table><thead><tr>${tableHeaders}</tr></thead><tbody>${bodyRows || `<tr><td colspan="${reportDisplayHeaders(data.type).length}">No records in the selected date range.</td></tr>`}</tbody></table><footer>Generated ${esc(new Date().toLocaleString('en-PH'))}</footer></body></html>`;
  openReportPDFPreview(reportHtml, chartImage, popup);
}

async function generateReport(event) {
  event.preventDefault(); const form = event.currentTarget; const data = Object.fromEntries(new FormData(form));
  if (!data.type || !data.period || !data.start || !data.end) return alert('Choose a report type, period, and both dates.');
  if (data.start > data.end) return alert('Start date must be on or before end date.');
  try {
    const source = data.type === 'bookings' ? await getRows('bookings')
      : data.type === 'feedback' ? await getRows('feedback')
        : data.type === 'inquiries' ? await getRows('inquiries')
          : invoices.filter((invoice) => invoice.source === 'pos' && String(invoice.status || '').toLowerCase() === 'paid');
    const rows = source.filter((row) => dateKey(row) >= data.start && dateKey(row) <= data.end);
    const printWindow = data.format === 'pdf' ? window.open('', '_blank') : null;
    if (data.format === 'pdf' && !printWindow) throw new Error('Allow pop-ups to print the report as PDF.');
    const id = `report_${Date.now()}`;
    await api('api/admin-workflow.php', { action: 'save_report', reportId: id, type: data.type, period: data.period, start: data.start, end: data.end, format: data.format, rowCount: rows.length });
    adminRows.reports = [...(adminRows.reports || []), { id, reportId: id, type: data.type, period: data.period, start: data.start, end: data.end, format: data.format, rowCount: rows.length, generatedBy: actor.uid, createdAt: new Date().toISOString() }];
    const output = $('#reportOutput');
    if (data.format === 'excel' || data.format === 'csv') {
      const exportRows = data.type === 'sales'
        ? rows.map((row) => {
          const values = reportDisplayRows([row], 'sales')[0];
          return Object.fromEntries(reportDisplayHeaders('sales').map((header, index) => [header, values[index]]));
        })
        : rows;
      downloadText(`${data.type}-${data.start}-${data.end}.csv`, csv(exportRows, data.type === 'sales' ? reportDisplayHeaders('sales') : null));
    }
    else if (data.format === 'pdf') {
      await openVisualReport(rows, data, printWindow);
    } else {
      const buckets = {};
      rows.forEach((row) => {
        const rawDate = dateKey(row); let key = rawDate || 'Unknown';
        if (rawDate && data.period === 'monthly') key = rawDate.slice(0, 7);
        if (rawDate && data.period === 'weekly') {
          const date = new Date(`${rawDate}T12:00:00Z`); const day = (date.getUTCDay() + 6) % 7;
          date.setUTCDate(date.getUTCDate() - day); key = `Week of ${date.toISOString().slice(0, 10)}`;
        }
        const value = data.type === 'sales' ? Number(row.totalAmount ?? row.finalAmount ?? 0) : 1;
        buckets[key] = (buckets[key] || 0) + (Number.isFinite(value) ? value : 0);
      });
      const max = Math.max(1, ...Object.values(buckets));
      output.innerHTML = `<h3>${esc(data.type)} · ${rows.length} records</h3><div class="report-bars">${Object.entries(buckets).sort(([a],[b]) => a.localeCompare(b)).map(([day,count]) => `<div class="report-bar" title="${esc(day)}: ${count}" style="height:${Math.max(18, count / max * 160)}px">${count}<br>${esc(day.slice(5))}</div>`).join('')}</div>`;
    }
    await loadReportHistory();
  } catch (error) { alert(readableError(error)); }
}

async function loadReportHistory() {
  try {
    reportHistoryData = (await getRows('reports')).sort((a, b) => String(b.createdAt || b.id).localeCompare(String(a.createdAt || a.id)));
    renderContinuousReportHistory();
    renderDashboardOverview();
  } catch (error) { console.error('Report history load failed:', error); }
}

function renderContinuousReportHistory(historyItems = reportHistoryData) {
  const list = $('#report-history-list');
  if (!list) {
    // Legacy admin report panels do not include the dual-column history layout.
    const legacyList = $('#reportHistory');
    if (legacyList) legacyList.innerHTML = historyItems.map((item) => `<p>${esc(item.type)} · ${esc(item.period)} · ${esc(item.start)}—${esc(item.end)} · ${Number(item.rowCount || 0)} rows</p>`).join('') || 'No reports generated.';
    return;
  }
  const midIndex = Math.ceil(historyItems.length / 2);
  const leftItems = historyItems.slice(0, midIndex);
  const rightItems = historyItems.slice(midIndex);
  const renderRow = (item) =>
    `<div class="report-history-row-item">${esc(item.type || 'bookings')} · ${esc(item.period || 'monthly')} · ${esc(item.start || '—')}—${esc(item.end || '—')} · ${Number(item.rowCount || 0)} rows</div>`;
  const leftContainer = $('#report-history-left-block');
  const rightContainer = $('#report-history-right-block');
  if (leftContainer) leftContainer.innerHTML = leftItems.map(renderRow).join('') || '<div class="report-history-empty">No reports generated.</div>';
  if (rightContainer) rightContainer.innerHTML = rightItems.map(renderRow).join('');
}

function renderCustomers() {
  const search = $('#customerSearch').value.trim().toLowerCase();
  const rows = customers.filter((item) => `${item.name || item.username || ''} ${item.email || ''}`.toLowerCase().includes(search));
  $('#customerResults').innerHTML = rows.map((item) => `<tr class="customer-record-row"><td>${esc(item.name || item.username)}</td><td>${esc(item.email)}</td><td>${esc(item.phone || item.contact)}</td><td>${esc(item.status || 'active')}</td><td><button class="customer-toggle ${item.status === 'disabled' ? 'btn-enable-customer' : 'btn-disable-customer'}" data-id="${esc(item.id)}">${item.status === 'disabled' ? 'Enable' : 'Disable'}</button></td></tr>`).join('') || '<tr><td colspan="5">No customer records.</td></tr>';
  if (isDashboardPage && modulePaginators.customers) {
    const renderedItems = rows.length ? [...$('#customerResults').children].map((row) => row.outerHTML) : [];
    modulePaginators.customers.setItems(renderedItems);
  }
}

function renderContinuousSystemLogs(logsData = null) {
  const leftContainer = $('#system-logs-left-block');
  const rightContainer = $('#system-logs-right-block');
  if (!leftContainer || !rightContainer) return;
  if (logsData === null) {
    allSystemLogs = [
      ...(adminRows.activity_logs || []).map((row) => ({ ...row, logSource: 'Activity' })),
      ...(adminRows.notifications || []).map((row) => ({ ...row, logSource: 'Notification' }))
    ].sort((a, b) => String(b.createdAt || b.timestamp || b.updatedAt || '').localeCompare(String(a.createdAt || a.timestamp || a.updatedAt || '')))
      .map((row) => {
        const rawDate = row.createdAt || row.timestamp || row.updatedAt;
        const date = rawDate?.toDate?.() || (rawDate ? new Date(rawDate) : null);
        const time = date && !Number.isNaN(date.getTime()) ? date.toLocaleString() : '—';
        const event = row.action || row.event || row.type || row.status || row.logSource;
        const details = row.message || row.description || [row.category && row.key ? row.category + ' / ' + row.key : '', row.email || row.userEmail || row.adminId || row.userId || row.uid].filter(Boolean).join(' · ') || row.id || '';
        return { time, event, details };
      });
    logsData = allSystemLogs;
  }

  const midIndex = Math.ceil(logsData.length / 2);
  const leftBatch = logsData.slice(0, midIndex);
  const rightBatch = logsData.slice(midIndex);
  const renderRow = (log) =>
    '<div class="system-log-row-item"><div class="system-log-time">' + esc(log.time || log.timestamp || '—') +
    '</div><div class="system-log-event">' + esc(log.event || '—') +
    '</div><div class="system-log-details">' + esc(log.details || '—') + '</div></div>';
  leftContainer.innerHTML = leftBatch.map(renderRow).join('') || '<div class="system-log-empty">No system logs found.</div>';
  rightContainer.innerHTML = rightBatch.map(renderRow).join('');
}

function notificationTimestamp(item) {
  const raw = item.timestamp || item.createdAt || item.updatedAt;
  const parsed = raw?.toDate?.() || (raw ? new Date(raw) : null);
  return parsed && !Number.isNaN(parsed.getTime()) ? parsed : null;
}

function formatNotificationMessage(item) {
  const customer = item.customerName || item.username || 'Customer';
  const staff = item.staffName || item.cashierName || item.adminName || 'Admin';
  const timestamp = notificationTimestamp(item);
  const date = item.date || (timestamp ? timestamp.toLocaleDateString('en-PH') : 'an unknown date');
  const time = item.time || (timestamp ? timestamp.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'an unknown time');
  const service = item.serviceName || item.service || 'Service';
  const amount = item.amount !== undefined && item.amount !== null
    ? `₱${Number(item.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
    : '₱0.00';
  switch (String(item.type || '').toLowerCase()) {
    case 'booking_confirmed':
    case 'confirmation':
      return `${customer}'s booking has been confirmed by ${staff}, ${date}, ${time}.`;
    case 'payment_success':
    case 'payment':
      return `${customer} has successfully paid their balance of ${amount} for \"${service}\", ${date}, ${time}.`;
    case 'booking_cancelled':
    case 'cancellation':
      return `${customer}'s booking has been cancelled by ${staff}, ${date}, ${time}.`;
    default:
      return item.message || item.description || item.title || '';
  }
}

function isAuthenticationNotification(item) {
  const type = String(item.type || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
  const metadata = [item.category, item.event, item.action, item.message, item.description, item.title].filter(Boolean).join(' ').toLowerCase();
  return ['otp_login', 'account_created', 'account_creation', 'auth'].includes(type)
    || /^(otp|auth|account_created|account_creation)(_|$)/.test(type)
    || /\botp\b|authentication|auth(?:entication)?|account creation|account_created|account_creation|sign.?in|login/i.test(metadata);
}

let currentNotifPage = 1;
const notifsPerPage = 15;

function changeNotifPage(direction) {
  currentNotifPage = direction;
  renderNotifications();
}
window.changeNotifPage = changeNotifPage;

function renderNotifications() {
  const target = $('#notificationResults');
  if (!target) return;
  const pagination = $('#notifications-pagination');
  const rows = [...(adminRows.notifications || [])]
    .filter((item) => !isAuthenticationNotification(item))
    .filter((item) => !item.recipientRole || item.recipientRole === 'admin')
    .sort((a, b) => String(b.createdAt || b.timestamp || b.updatedAt || '').localeCompare(String(a.createdAt || a.timestamp || a.updatedAt || '')))
    .slice(0, 100);
  if (!rows.length) {
    currentNotifPage = 1;
    target.innerHTML = '<div class="notification-list-empty">No operational notifications found.</div>';
    if (pagination) pagination.innerHTML = '';
    renderDashboardOverview();
    return;
  }
  const totalPages = Math.ceil(rows.length / notifsPerPage);
  if (currentNotifPage > totalPages) currentNotifPage = totalPages;
  if (currentNotifPage < 1) currentNotifPage = 1;
  const startIndex = (currentNotifPage - 1) * notifsPerPage;
  const pageItems = rows.slice(startIndex, startIndex + notifsPerPage);
  target.innerHTML = pageItems.map((item) => {
    const message = formatNotificationMessage(item);
    const timestamp = notificationTimestamp(item);
    const time = item.date || timestamp?.toLocaleString('en-PH') || '';
    const timeMarkup = time ? '<time class="notification-list-time">' + esc(time) + '</time>' : '';
    return `<div class="notification-list-item overview-notification-row" role="button" tabindex="0" data-notification-id="${esc(item.id)}"><span>${esc(message || 'Notification')}</span>${timeMarkup}</div>`;
  }).join('') || '<div class="notification-list-empty">No operational notifications found.</div>';
  if (pagination) pagination.innerHTML = renderNotificationPaginationControls(currentNotifPage, totalPages);
  renderDashboardOverview();
}

function renderNotificationPaginationControls(currentPage, totalPages) {
  return renderInteractivePagination(currentPage, totalPages, 'changeNotifPage');
}

function renderInteractivePagination(currentPage, totalPages, callbackName) {
  return renderFarBottomRightPagination(currentPage, totalPages, callbackName);
}

function renderFarBottomRightPagination(currentPage, totalPages, callbackName) {
  const buttonStyle = (disabled) => `width:32px!important;height:32px!important;min-width:32px!important;min-height:32px!important;border-radius:50%!important;border:1px solid rgba(255,255,255,.25)!important;background:transparent!important;color:#8da4c4!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:0!important;line-height:1!important;${disabled ? 'opacity:.3!important;cursor:not-allowed!important;' : 'cursor:pointer!important;'}`;
  return `<div class="pagination-bottom-right-wrapper" style="display:flex!important;justify-content:flex-end!important;align-items:center!important;width:100%!important;margin-top:auto!important;padding-top:20px!important;text-align:right!important"><div style="display:inline-flex!important;align-items:center!important;gap:10px!important;margin-left:auto!important"><button type="button" class="pagination-circle-btn" style="${buttonStyle(currentPage === 1)}" ${currentPage === 1 ? 'disabled' : ''} onclick="${callbackName}(${currentPage - 1})"><span style="font-size:.85rem!important;line-height:1!important;font-weight:600!important">&lt;</span></button><span style="color:#fff!important;font-weight:700!important;font-size:.95rem!important;letter-spacing:.3px!important;white-space:nowrap!important;margin:0 4px!important">Page <span id="jump-page-num" style="color:#38bdf8!important;text-decoration:underline!important;text-underline-offset:3px!important;cursor:pointer!important;font-weight:700!important" title="Click to jump to page" onclick="enablePageJumpInput(this, ${currentPage}, ${totalPages}, '${callbackName}')">${currentPage}</span> of ${totalPages}</span><button type="button" class="pagination-circle-btn" style="${buttonStyle(currentPage === totalPages)}" ${currentPage === totalPages ? 'disabled' : ''} onclick="${callbackName}(${currentPage + 1})"><span style="font-size:.85rem!important;line-height:1!important;font-weight:600!important">&gt;</span></button></div></div>`;
}

window.enablePageJumpInput = function (spanElem, currentPage, totalPages, callbackName) {
  const input = document.createElement('input');
  input.type = 'number';
  input.min = '1';
  input.max = String(totalPages);
  input.value = String(currentPage);
  input.style.cssText = 'width:45px;height:24px;font-size:.85rem;text-align:center;background:rgba(255,255,255,.15);color:#fff;border:1px solid #38bdf8;border-radius:4px;outline:none;padding:0';
  spanElem.replaceWith(input);
  input.focus();
  input.select();
  let committed = false;
  const commit = () => {
    if (committed) return;
    committed = true;
    let page = Number.parseInt(input.value, 10);
    if (!Number.isFinite(page)) page = 1;
    page = Math.min(Math.max(page, 1), totalPages);
    window[callbackName](page);
  };
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') commit();
    if (event.key === 'Escape') { committed = true; window[callbackName](currentPage); }
  });
  input.addEventListener('blur', commit);
};

function openNotificationDetail(item) {
  let dialog = document.getElementById('notificationDetailModal');
  if (!dialog) {
    dialog = document.createElement('dialog');
    dialog.id = 'notificationDetailModal';
    dialog.className = 'notification-detail-modal';
    dialog.setAttribute('aria-labelledby', 'notificationDetailTitle');
    dialog.innerHTML = '<h2 id="notificationDetailTitle">Notification details</h2><div class="notification-detail-content"></div>';
    document.body.append(dialog);
  }
  const fields = [
    ['Customer Name', item.customerName], ['Service Performed', item.serviceName],
    ['Service Cost (PHP)', item.serviceCost == null ? '' : Number(item.serviceCost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })],
    ['Branch Location', item.branchName], ['Scheduled Date', item.scheduledDate], ['Scheduled Time', item.scheduledTime],
    ['Vehicle Year', item.vehicleYear], ['Vehicle Make', item.vehicleMake], ['Vehicle Model', item.vehicleModel],
    ['Plate Number', item.vehiclePlateNumber], ['Status', item.bookingStatus], ['Payment Status', item.paymentStatus],
    ['Payment Balance (PHP)', item.paymentBalance == null ? '' : Number(item.paymentBalance).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })]
  ].filter(([, value]) => value !== undefined && value !== null && value !== '');
  const title = dialog.querySelector('#notificationDetailTitle');
  const content = dialog.querySelector('.notification-detail-content');
  if (title) title.textContent = item.message || formatNotificationMessage(item) || 'Notification';
  if (content) content.innerHTML = `<dl>${fields.map(([label, value]) => `<dt>${esc(label)}</dt><dd>${esc(value)}</dd>`).join('') || '<dd>No booking details are attached to this notification.</dd>'}</dl>`;
  dialog.showModal();
}

document.addEventListener('click', (event) => {
  const dialog = document.getElementById('notificationDetailModal');
  if (dialog?.open && event.target === dialog) dialog.close();
});

document.addEventListener('click', (event) => {
  const metricToggle = event.target.closest('#changeMetricBtn');
  const metricOption = event.target.closest('.metric-opt[data-metric]');
  const metricMenu = $('#overviewMetricMenu');
  if (metricToggle) {
    const willOpen = metricMenu?.hidden !== false;
    if (metricMenu) metricMenu.hidden = !willOpen;
    metricToggle.setAttribute('aria-expanded', String(willOpen));
    return;
  }
  if (metricOption) {
    overviewMetric = metricOption.dataset.metric;
    if (metricMenu) metricMenu.hidden = true;
    $('#changeMetricBtn')?.setAttribute('aria-expanded', 'false');
    renderDashboardOverview();
    return;
  }
  if (!event.target.closest('.overview-metric-control') && metricMenu?.hidden === false) {
    metricMenu.hidden = true;
    $('#changeMetricBtn')?.setAttribute('aria-expanded', 'false');
  }
  const row = event.target.closest('.overview-notification-row[data-notification-id]');
  if (!row) return;
  const item = (adminRows.notifications || []).find((notification) => notification.id === row.dataset.notificationId);
  if (item) openNotificationDetail(item);
});

document.addEventListener('keydown', (event) => {
  if (!['Enter', ' '].includes(event.key)) return;
  const row = event.target.closest?.('.overview-notification-row[data-notification-id]');
  if (!row) return;
  event.preventDefault();
  row.click();
});

window.addEventListener('resize', () => {
  if (isDashboardPage) renderDashboardOverview();
});
window.addEventListener('cooltime:dashboard-metrics-updated', (event) => {
  if (!isDashboardPage) return;
  const updatedBookings = event.detail?.bookings;
  if (Array.isArray(updatedBookings)) bookings = updatedBookings;
  renderDashboardOverview();
});
window.addEventListener('cooltime:dashboard-home-activated', () => renderDashboardOverview());

async function customerAction(event) {
  const button = event.target.closest('.customer-toggle'); if (!button) return;
  const current = customers.find((item) => item.id === button.dataset.id); if (!current) return;
  try { await api('api/admin-workflow.php', { action: 'set_customer_status', customerId: current.id, status: current.status === 'disabled' ? 'active' : 'disabled' }); await loadCoreData(); }
  catch (error) { alert(readableError(error)); }
}

async function exportBackup() {
  const names = ['users','bookings','vehicles','invoices','payments','services','branches','branch','staff','service_history','schedule_slots','feedback','inquiries','notifications','paymongo_intents','reports','activity_logs','system_settings'];
  try {
    const groups = await Promise.all(names.map(async (name) => (await getRows(name)).map((row) => ({ collection: name, documentId: row.id, ...row }))));
    downloadText(`cooltime-backup-${new Date().toISOString().slice(0,10)}.csv`, csv(groups.flat()));
  } catch (error) { alert(`Backup export failed: ${error.message}`); }
}

function parseCsvRecords(text) {
  const rows = [];
  let row = [], field = '', quoted = false;
  const source = String(text || '').replace(/^\uFEFF/, '');
  for (let index = 0; index < source.length; index += 1) {
    const char = source[index];
    if (quoted) {
      if (char === '"' && source[index + 1] === '"') { field += '"'; index += 1; }
      else if (char === '"') quoted = false;
      else field += char;
    } else if (char === '"' && field === '') quoted = true;
    else if (char === ',') { row.push(field); field = ''; }
    else if (char === '\n' || char === '\r') {
      if (char === '\r' && source[index + 1] === '\n') index += 1;
      row.push(field); rows.push(row); row = []; field = '';
    } else field += char;
  }
  if (field !== '' || row.length) { row.push(field); rows.push(row); }
  return rows;
}

async function handleDatabaseCSVImport(event) {
  const input = event.currentTarget;
  const file = input.files?.[0];
  if (!file) return;
  if (!/\.csv$/i.test(file.name)) {
    alert('Please select a valid .csv database backup file.');
    input.value = '';
    return;
  }
  if (!confirm(`Import customer records from "${file.name}"? Matching existing customer records will be updated.`)) {
    input.value = '';
    return;
  }

  try {
    const rows = parseCsvRecords(await file.text());
    if (rows.length < 2) throw new Error('The CSV file appears to be empty or missing data headers.');
    const headers = rows[0].map((header) => header.trim().toLowerCase());
    if (!headers.includes('email')) throw new Error('The CSV must include an email column.');
    const importedRecords = [];
    let ignoredRows = 0;
    for (const values of rows.slice(1)) {
      if (!values.some((value) => String(value || '').trim())) continue;
      const record = Object.fromEntries(headers.map((header, index) => [header, String(values[index] ?? '').trim()]));
      const collection = String(record.collection || '').toLowerCase();
      const roleValue = String(record.role || '').toLowerCase().replace(/[\s-]+/g, '_');
      if ((collection && collection !== 'users') || (roleValue && roleValue !== 'customer')) { ignoredRows += 1; continue; }
      const email = String(record.email || '').trim().toLowerCase();
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { ignoredRows += 1; continue; }
      const name = record.name || record.username || record.customer || record.customername || '';
      const phone = record.phone || record.contact || record.contactnumber || '';
      const status = String(record.status || 'active').toLowerCase();
      importedRecords.push({
        id: record.documentid || record.id || record.uid || record.userid || '',
        name, email, phone,
        status: ['active', 'disabled'].includes(status) ? status : ''
      });
    }
    if (!importedRecords.length) throw new Error('No valid customer records with email addresses were found in the CSV.');

    let importedCount = 0, skippedCount = ignoredRows;
    for (let offset = 0; offset < importedRecords.length; offset += 200) {
      const result = await api('api/admin-workflow.php', {
        action: 'import_customer_records',
        records: importedRecords.slice(offset, offset + 200)
      });
      importedCount += Number(result.imported || 0);
      skippedCount += Number(result.skipped || 0);
    }
    await loadCoreData();
    alert(`Customer import complete: ${importedCount} record(s) updated${skippedCount ? `, ${skippedCount} row(s) skipped` : ''}.`);
  } catch (error) {
    console.error('Customer CSV import failed:', error);
    alert(error.message || 'Failed to parse or import the CSV file. Check that it uses the database backup format.');
  } finally {
    input.value = '';
  }
}

function setAccountsPage(direction) {
  currentAccountsPage = direction;
  filterAccountRows();
}
window.setAccountsPage = setAccountsPage;

function renderAccounts() {
  const roleLabels = { customer: 'Customer', staff: 'Staff', admin: 'Admin', super_admin: 'Super Admin' };
  $('#accountResults').innerHTML = accounts.map((item, index) => {
    const detailsId = `account-edit-${index}`;
    const name = item.name || item.username || '';
    const role = item.role || 'customer';
    const status = item.status || 'active';
    const roleOptions = [['customer', 'Customer'], ['admin', 'Admin'], ['super_admin', 'Super Admin']];
    if (role === 'staff') roleOptions.splice(1, 0, ['staff', 'Staff']);
    return `<tr class="account-summary-row account-row-item" data-account-row data-role="${esc(role.replaceAll('_', ' '))}" tabindex="0" aria-expanded="false" aria-controls="${detailsId}"><td>${esc(name)}</td><td>${esc(item.email)}</td><td>${esc(roleLabels[role] || role)}</td><td>${esc(status)}</td></tr><tr class="account-edit-row"><td colspan="4"><div class="account-edit-accordion" id="${detailsId}" inert><form class="account-edit-form" data-id="${esc(item.id)}"><div class="account-edit-fields"><input name="name" value="${esc(name)}" placeholder="Username" aria-label="Username" required><input name="email" type="email" value="${esc(item.email)}" placeholder="Email" aria-label="Email" required><input name="password" type="password" placeholder="Password (leave blank to keep current)" aria-label="Password (leave blank to keep current)"><select name="role" aria-label="Role">${roleOptions.map(([value, label]) => `<option value="${value}"${role === value ? ' selected' : ''}>${label}</option>`).join('')}</select><select name="status" aria-label="Status"><option value="active"${status === 'active' ? ' selected' : ''}>Active</option><option value="disabled"${status === 'disabled' ? ' selected' : ''}>Disabled</option></select></div><div class="account-form-actions"><button class="btn-save-account" type="submit">Save changes</button><button class="btn-cancel-account-action btn-cancel-account-edit" type="button">Cancel</button><button class="account-delete btn-delete-account" type="button" data-id="${esc(item.id)}">Delete</button></div><output></output></form></div></td></tr>`;
  }).join('') || '<tr><td colspan="4">No accounts found.</td></tr>';
  filterAccountRows();
}

function filterAccountRows() {
  const searchTerm = ($('#account-search-input')?.value || '').trim().toLowerCase();
  const selectedRole = ($('#account-role-filter')?.value || '').trim().toLowerCase();
  const rows = [...document.querySelectorAll('#accountResults .account-row-item')];
  const matchingRows = rows.filter((row) => {
    const textContent = row.textContent.toLowerCase();
    const roleText = (row.dataset.role || textContent).toLowerCase();
    return textContent.includes(searchTerm) && (!selectedRole || roleText.includes(selectedRole));
  });
  const totalPages = Math.max(1, Math.ceil(matchingRows.length / accountsPerPage));
  currentAccountsPage = Math.min(Math.max(currentAccountsPage, 1), totalPages);
  const firstVisible = (currentAccountsPage - 1) * accountsPerPage;
  const visibleRows = new Set(matchingRows.slice(firstVisible, firstVisible + accountsPerPage));
  rows.forEach((row) => {
    const visible = visibleRows.has(row);
    row.style.display = visible ? '' : 'none';
    if (row.nextElementSibling?.classList.contains('account-edit-row')) row.nextElementSibling.style.display = visible ? '' : 'none';
  });
  const footer = $('#account-pagination');
  if (footer) footer.innerHTML = renderInteractivePagination(currentAccountsPage, totalPages, 'setAccountsPage');
}

async function saveAccount(event) {
  event.preventDefault(); const form = event.currentTarget; const data = Object.fromEntries(new FormData(form));
  const action = data.uid ? 'update' : 'create';
  if (action === 'create' && data.password.length < 6) return showMessage(form, 'New account password must be at least six characters.');
  try { const result = await api('api/admin-accounts.php', { action, status: 'active', ...data }); form.reset(); showMessage(form, `Account saved (${result.uid}).`); $('#accountCreateAccordion').classList.remove('expanded'); $('#accountCreateAccordion').inert = true; $('#createAccountToggle').setAttribute('aria-expanded', 'false'); await loadCoreData(); }
  catch (error) { showMessage(form, error.message); }
}

async function accountAction(event) {
  const cancel = event.target.closest('.btn-cancel-account-edit');
  if (cancel) {
    const editRow = cancel.closest('.account-edit-row');
    editRow?.classList.remove('expanded');
    const accordion = editRow?.querySelector('.account-edit-accordion');
    if (accordion) accordion.inert = true;
    editRow?.previousElementSibling?.setAttribute('aria-expanded', 'false');
    return;
  }
  const deleteButton = event.target.closest('.account-delete');
  if (deleteButton) {
    const item = accounts.find((row) => row.id === deleteButton.dataset.id); if (!item) return;
    if (confirm(`Delete Auth account ${item.email}? This cannot be undone.`)) {
      try { await api('api/admin-accounts.php', { action:'delete', uid:item.id, name:item.name || item.username, email:item.email, role:item.role, status:item.status || 'active' }); await loadCoreData(); }
      catch (error) { alert(readableError(error)); }
    }
    return;
  }
  const row = event.target.closest('[data-account-row]');
  if (row && !event.target.closest('button, a, input, select, textarea')) toggleAccountRow(row);
}

function toggleAccountRow(row) {
  const expanded = row.getAttribute('aria-expanded') !== 'true';
  row.setAttribute('aria-expanded', String(expanded));
  const editRow = row.nextElementSibling;
  editRow?.classList.toggle('expanded', expanded);
  const accordion = editRow?.querySelector('.account-edit-accordion');
  if (accordion) accordion.inert = !expanded;
}

async function saveAccountEdit(event) {
  const form = event.target.closest('.account-edit-form');
  if (!form) return;
  event.preventDefault();
  const data = Object.fromEntries(new FormData(form));
  if (data.password && data.password.length < 6) return showMessage(form, 'Password must be at least six characters, or leave it blank to keep the current password.');
  try {
    await api('api/admin-accounts.php', { action: 'update', uid: form.dataset.id, ...data });
    await loadCoreData();
  } catch (error) { showMessage(form, readableError(error)); }
}

async function loadSettings() {
  try {
    const settings = await getRows('system_settings');
    adminRows.system_settings = settings;
    const byKey = Object.fromEntries(settings.map((item) => [item.key || item.id, item.value]));
    const setInput = (selector, key, fallback) => {
      const input = $(selector);
      if (input) input.value = byKey[key] ?? fallback;
    };
    setInput('#setting-daily-slots', 'daily_slot_capacity', '10');
    setInput('#setting-field-plate', 'vehicle_plate_field', 'required');
    setInput('#setting-field-vehicle', 'vehicle_year_model_field', 'required');
    setInput('#setting-paymongo-public', 'paymongo_public_key', '');
    const mode = byKey.paymongo_mode === 'live' ? 'live' : 'test';
    const modeRadio = $(`#paymongo${mode === 'live' ? 'Live' : 'Test'}`);
    if (modeRadio) modeRadio.checked = true;
    loadNotificationTemplate();
    $('#settingsResults').innerHTML = settings.map((item) => {
      const key = item.key || item.id || '';
      const value = /secret|password|token|credential/i.test(key) ? '••••••••' : item.value;
      return `<p><strong>${esc(item.category)} / ${esc(key)}:</strong> ${esc(value)}</p>`;
    }).join('') || '<p>No settings saved.</p>';
  } catch (error) { $('#settingsResults').textContent = error.message; }
}

async function saveStructuredSettings(form, entries, successMessage) {
  const output = form.querySelector('output');
  if (entries.some(({ key, value }) => !/^[A-Za-z0-9_.-]{1,80}$/.test(key) || String(value).length > 1000 || String(value) === '')) {
    if (output) output.textContent = 'Check the setting values and try again.';
    return;
  }
  try {
    for (const entry of entries) {
      await api('api/admin-workflow.php', { action: 'save_setting', ...entry });
      const id = `${entry.category.toLowerCase()}_${entry.key}`;
      adminRows.system_settings = [...(adminRows.system_settings || []).filter((item) => item.id !== id), {
        id, ...entry, updatedBy: actor.uid, updatedAt: new Date().toISOString()
      }];
    }
    if (output) output.textContent = successMessage;
    await loadSettings();
  }
  catch (error) { if (output) output.textContent = readableError(error); }
}

async function saveBookingSettings(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const slots = Number($('#setting-daily-slots').value);
  if (!Number.isInteger(slots) || slots < 1 || slots > 500) {
    form.querySelector('output').textContent = 'Daily capacity must be a whole number from 1 to 500.';
    return;
  }
  await saveStructuredSettings(form, [
    { category: 'General', key: 'daily_slot_capacity', value: String(slots) },
    { category: 'General', key: 'vehicle_plate_field', value: $('#setting-field-plate').value },
    { category: 'General', key: 'vehicle_year_model_field', value: $('#setting-field-vehicle').value }
  ], 'Booking settings saved.');
}

async function savePayMongoSettings(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const mode = form.querySelector('[name="paymongoMode"]:checked')?.value || 'test';
  const publicKey = $('#setting-paymongo-public').value.trim();
  if (publicKey && !/^pk_(test|live)_/.test(publicKey)) {
    form.querySelector('output').textContent = 'Enter a valid PayMongo public key, or leave it blank.';
    return;
  }
  const entries = [{ category: 'Security', key: 'paymongo_mode', value: mode }];
  if (publicKey) entries.push({ category: 'Security', key: 'paymongo_public_key', value: publicKey });
  await saveStructuredSettings(form, entries, 'PayMongo dashboard settings saved. The server secret remains managed through its environment configuration.');
}

const defaultNotificationTemplates = {
  confirmation: ['Booking Confirmation - {{customer_name}}', 'Hello {{customer_name}}, your service appointment for {{service_name}} is set on {{scheduled_date}} at {{scheduled_time}}.'],
  cancellation: ['Appointment Cancellation - {{customer_name}}', 'Hello {{customer_name}}, your appointment for {{service_name}} has been cancelled.'],
  reschedule: ['Reschedule Successful - {{customer_name}}', 'Hello {{customer_name}}, your service appointment has been successfully rescheduled.'],
  reminder: ['Appointment Reminder - {{customer_name}}', 'Hello {{customer_name}}, your {{service_name}} appointment is about to start at {{scheduled_time}}.'],
  overdue: ['Payment Overdue - {{customer_name}}', 'Hello {{customer_name}}, your remaining balance is {{total_price}}. Please settle your bill soon.']
};

function loadNotificationTemplate() {
  const type = $('#template-type-select')?.value || 'confirmation';
  const settings = Object.fromEntries((adminRows.system_settings || []).map((item) => [item.key || item.id, item.value]));
  const defaults = defaultNotificationTemplates[type] || defaultNotificationTemplates.confirmation;
  const subject = $('#setting-template-subject');
  const body = $('#setting-template-body');
  if (subject) subject.value = settings[`template_${type}_subject`] ?? defaults[0];
  if (body) body.value = settings[`template_${type}_body`] ?? defaults[1];
}

async function saveTemplateSettings(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const type = $('#template-type-select').value;
  await saveStructuredSettings(form, [
    { category: 'Notifications', key: `template_${type}_subject`, value: $('#setting-template-subject').value.trim() },
    { category: 'Notifications', key: `template_${type}_body`, value: $('#setting-template-body').value.trim() }
  ], 'Notification template saved.');
}

initialize().catch((error) => {
  console.error('Admin tools failed to initialize:', error);
  // A backend 401 can be transient or unrelated to the Firebase session.
  // Only send an actually signed-out user to login; retain a signed-in user's
  // page and MFA state so the user can recover from service/network failures.
  if (error.status === 401 || error.status === 403) {
    redirectToLoginWhenSignedOut(() => {
      alert(`Admin tools could not load: ${error.message || 'Please check your administrator access.'}`);
    });
    return;
  }
  alert(`Admin tools could not load: ${error.message || 'Please check your administrator access.'}`);
});


