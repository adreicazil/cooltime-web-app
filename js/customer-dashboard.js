import { onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { collection, doc, onSnapshot, query, serverTimestamp, updateDoc, where } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { auth, authReady, db } from './firebase-config.js';
import { hasOtpVerification } from './auth-guard.js';
import { normalizeBranchLabel, normalizeBranchText } from './branch-utils.js';

const dashboard = document.querySelector('.customer-dashboard');
const viewLinks = [...document.querySelectorAll('[data-dashboard-view]')];
const views = [...document.querySelectorAll('.customer-view')];

function activateView(id, updateHistory = true) {
  const view = views.find((item) => item.id === id) || views.find((item) => item.id === 'customerHome');
  if (!view) return;
  views.forEach((item) => { item.hidden = item !== view; });
  viewLinks.forEach((link) => {
    const active = link.dataset.dashboardView === view.id;
    link.classList.toggle('active', active);
    if (active) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
  if (updateHistory) history.replaceState(null, '', `#${view.id}`);
  dashboard?.querySelector('.customer-dashboard-content')?.scrollTo(0, 0);
}

viewLinks.forEach((link) => link.addEventListener('click', (event) => {
  event.preventDefault();
  activateView(link.dataset.dashboardView);
}));
window.addEventListener('hashchange', () => activateView(location.hash.slice(1), false));
activateView(location.hash.slice(1), false);

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const money = (value) => `PHP ${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const summaryInvoices = document.getElementById('customerInvoiceSummary');
const summaryBookings = document.getElementById('customerBookingSummary');
let invoices = [];
let bookings = [];
let customerNotifications = [];
let currentNotificationsPage = 1;
const NOTIFICATIONS_PER_PAGE = 15;
let stopCustomerNotificationListener = null;
let currentNotificationUserId = '';
let seenNotificationIds = new Set();
let notificationFallbackTimes = new Map();

const notificationList = document.getElementById('customerNotifications');
const notificationPagination = document.getElementById('customerNotificationsPagination');
const notificationDialog = document.getElementById('notificationDetailModal');
const notificationBellWrap = document.getElementById('customerNotificationBellWrap');
const notificationBell = document.getElementById('customerNotificationBell');
const notificationDot = document.getElementById('customerNotificationDot');
const notificationDropdown = document.getElementById('customerNotificationDropdown');
const notificationPreviewList = document.getElementById('customerNotificationPreviewList');
const notificationViewAll = document.getElementById('customerNotificationViewAll');
const notificationFields = [
  ['Customer Name', 'customerName'], ['Service Performed', 'serviceName'], ['Service Cost (PHP)', 'serviceCost'],
  ['Branch Location', 'branchName'], ['Scheduled Date', 'scheduledDate'], ['Scheduled Time', 'scheduledTime'],
  ['Vehicle Year', 'vehicleYear'], ['Vehicle Make', 'vehicleMake'], ['Vehicle Model', 'vehicleModel'],
  ['Plate Number', 'vehiclePlateNumber'], ['Status', 'bookingStatus'], ['Payment Status', 'paymentStatus'],
  ['Payment Balance (PHP)', 'paymentBalance']
];

function formatNotificationAmount(value) {
  return value === undefined || value === null || value === '' ? ''
    : Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function notificationTime(item) {
  for (const value of [item?.createdAt, item?.created_at, item?.timestamp]) {
    if (value?.toDate) {
      try {
        const date = value.toDate();
        if (!Number.isNaN(date.getTime())) return date;
      } catch { /* Try the next available timestamp field. */ }
    }
    if (typeof value === 'number' && Number.isFinite(value)) {
      const date = new Date(value);
      if (!Number.isNaN(date.getTime())) return date;
    }
    if (typeof value === 'string' && value.trim()) {
      const date = new Date(value);
      if (!Number.isNaN(date.getTime())) return date;
    }
  }
  return new Date(item?._receivedAtFallbackMs || Date.now());
}

function notificationTimeMs(item) {
  const date = notificationTime(item);
  return date && !Number.isNaN(date.getTime()) ? date.getTime() : 0;
}

function notificationTimeLabel(item) {
  const date = notificationTime(item);
  return date.toLocaleString('en-US', {
    month: '2-digit', day: '2-digit', year: 'numeric',
    hour: 'numeric', minute: '2-digit', second: '2-digit'
  });
}

function notificationIsSeen(item) {
  return item.seen === true || item.read === true || Boolean(item.readAt || item.seenAt) || seenNotificationIds.has(item.id);
}

function notificationStorageKey() {
  return currentNotificationUserId ? `cooltime:seenNotifications:${currentNotificationUserId}` : '';
}

function persistSeenNotificationIds() {
  const key = notificationStorageKey();
  if (!key) return;
  try { localStorage.setItem(key, JSON.stringify([...seenNotificationIds])); }
  catch (error) { console.warn('Notification seen state could not be saved locally.', error); }
}

function updateNotificationBell() {
  if (!notificationBell || !notificationDot) return;
  const unreadCount = customerNotifications.filter((item) => !notificationIsSeen(item)).length;
  notificationDot.hidden = unreadCount === 0;
  notificationBell.setAttribute('aria-label', unreadCount ? `Open notifications, ${unreadCount} unread` : 'Open notifications');
}

function renderNotificationPreview() {
  if (!notificationPreviewList) return;
  const recent = [...customerNotifications].sort((a, b) => notificationTimeMs(b) - notificationTimeMs(a)).slice(0, 5);
  notificationPreviewList.innerHTML = recent.length ? recent.map((item) => {
    const message = normalizeBranchText(item.message || item.title || 'Notification');
    const category = String(item.type || 'notification').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    const stamp = notificationTimeLabel(item);
    return `<button type="button" class="customer-notification-preview-row" data-notification-id="${escapeHtml(item.id)}"><span class="customer-notification-preview-meta"><span class="customer-notification-category">${escapeHtml(category)}</span><time>${escapeHtml(stamp)}</time></span><span class="customer-notification-preview-message">${escapeHtml(message)}</span></button>`;
  }).join('') : '<p class="customer-notification-preview-empty">You are all caught up.</p>';
}

function renderCustomerNotifications() {
  if (!notificationList) return;
  const totalPages = Math.max(1, Math.ceil(customerNotifications.length / NOTIFICATIONS_PER_PAGE));
  currentNotificationsPage = Math.min(Math.max(1, currentNotificationsPage), totalPages);
  const startIndex = (currentNotificationsPage - 1) * NOTIFICATIONS_PER_PAGE;
  const pageNotifications = customerNotifications.slice(startIndex, startIndex + NOTIFICATIONS_PER_PAGE);
  notificationList.innerHTML = customerNotifications.length
    ? pageNotifications.map((item, index) => {
      const stamp = notificationTimeLabel(item);
      const category = String(item.type || 'notification').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
      return `<button type="button" class="customer-notification-row" data-notification-index="${startIndex + index}"><span class="customer-notification-full-message"><span class="customer-notification-full-meta"><span class="customer-notification-category">${escapeHtml(category)}</span><time>${escapeHtml(stamp)}</time></span><span class="customer-notification-full-text">${escapeHtml(normalizeBranchText(item.message || item.title || 'Notification'))}</span></span></button>`;
    }).join('')
    : '<p class="customer-notification-empty">No notifications yet.</p>';
  if (notificationPagination) {
    notificationPagination.hidden = totalPages <= 1;
    notificationPagination.innerHTML = totalPages > 1 ? renderNotificationPagination(currentNotificationsPage, totalPages) : '';
  }
  renderNotificationPreview();
  updateNotificationBell();
}

function renderNotificationPagination(page, total) {
  const buttonStyle = (disabled) => `width:32px!important;height:32px!important;min-width:32px!important;min-height:32px!important;border-radius:50%!important;border:1px solid rgba(255,255,255,.25)!important;background:transparent!important;color:#8da4c4!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:0!important;line-height:1!important;${disabled ? 'opacity:.3!important;cursor:not-allowed!important;' : 'cursor:pointer!important;'}`;
  return `<div class="pagination-bottom-right-wrapper" style="display:flex!important;justify-content:flex-end!important;align-items:center!important;width:100%!important;margin-top:auto!important;padding-top:20px!important;text-align:right!important"><div style="display:inline-flex!important;align-items:center!important;gap:10px!important;margin-left:auto!important"><button type="button" class="pagination-circle-btn notification-prev-page" style="${buttonStyle(page === 1)}" ${page === 1 ? 'disabled' : ''} aria-label="Previous page"><span style="font-size:.85rem!important;line-height:1!important;font-weight:600!important">&lt;</span></button><span style="color:#fff!important;font-weight:700!important;font-size:.95rem!important;letter-spacing:.3px!important;white-space:nowrap!important;margin:0 4px!important">Page <span class="active-page" style="color:#38bdf8!important;text-decoration:underline!important;text-underline-offset:3px!important;font-weight:700!important">${page}</span> of ${total}</span><button type="button" class="pagination-circle-btn notification-next-page" style="${buttonStyle(page === total)}" ${page === total ? 'disabled' : ''} aria-label="Next page"><span style="font-size:.85rem!important;line-height:1!important;font-weight:600!important">&gt;</span></button></div></div>`;
}

notificationPagination?.addEventListener('click', (event) => {
  if (event.target.closest('.notification-prev-page')) currentNotificationsPage -= 1;
  else if (event.target.closest('.notification-next-page')) currentNotificationsPage += 1;
  else return;
  renderCustomerNotifications();
});

function showCustomerNotification(item) {
  if (!notificationDialog || !item) return;
  const title = notificationDialog.querySelector('#notificationDetailTitle');
  const content = notificationDialog.querySelector('.notification-detail-content');
  if (title) title.textContent = normalizeBranchText(item.message || item.title || 'Notification details');
  const values = notificationFields.map(([label, key]) => {
    const value = ['serviceCost', 'paymentBalance'].includes(key) ? formatNotificationAmount(item[key])
      : key === 'branchName' ? normalizeBranchLabel(item[key] || item.branch) : item[key];
    return value === undefined || value === null || value === '' ? '' : `<dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd>`;
  }).filter(Boolean).join('');
  if (content) content.innerHTML = `<dl>${values || '<dd>No booking details are attached to this notification.</dd>'}</dl>`;
  notificationDialog.showModal();
}

notificationList?.addEventListener('click', (event) => {
  const row = event.target.closest('[data-notification-index]');
  if (row) showCustomerNotification(customerNotifications[Number(row.dataset.notificationIndex)]);
});

notificationPreviewList?.addEventListener('click', (event) => {
  const row = event.target.closest('[data-notification-id]');
  if (!row) return;
  const item = customerNotifications.find((notification) => notification.id === row.dataset.notificationId);
  if (item) showCustomerNotification(item);
});

function closeNotificationDropdown() {
  if (!notificationDropdown || !notificationBell) return;
  notificationDropdown.hidden = true;
  notificationBell.setAttribute('aria-expanded', 'false');
}

async function markCustomerNotificationsSeen() {
  const unseen = customerNotifications.filter((item) => !notificationIsSeen(item));
  unseen.forEach((item) => seenNotificationIds.add(item.id));
  persistSeenNotificationIds();
  updateNotificationBell();
  await Promise.allSettled(unseen.map((item) => updateDoc(doc(db, 'notifications', item.id), {
    seen: true,
    readAt: serverTimestamp()
  })));
}

notificationBell?.addEventListener('click', async () => {
  if (!notificationDropdown) return;
  const opening = notificationDropdown.hidden;
  notificationDropdown.hidden = !opening;
  notificationBell.setAttribute('aria-expanded', String(opening));
  if (opening) {
    const rect = notificationBell.getBoundingClientRect();
    const maxRight = Math.max(10, window.innerWidth - Math.min(360, window.innerWidth - 20) - 10);
    const right = Math.min(maxRight, Math.max(10, window.innerWidth - rect.right));
    notificationDropdown.style.top = `${rect.bottom + 8}px`;
    notificationDropdown.style.right = `${right}px`;
    await markCustomerNotificationsSeen();
  }
});

notificationViewAll?.addEventListener('click', () => {
  closeNotificationDropdown();
  activateView('notifications');
});

document.addEventListener('click', (event) => {
  if (!notificationDropdown?.hidden && !notificationBellWrap?.contains(event.target)) closeNotificationDropdown();
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && notificationDropdown && !notificationDropdown.hidden) closeNotificationDropdown();
});

function showNotificationLoadError(error) {
  console.error('Customer notifications could not be loaded:', {
    code: error?.code || 'unknown',
    message: error?.message || String(error)
  });
  if (!notificationList) return;
  if (notificationPagination) {
    notificationPagination.hidden = true;
    notificationPagination.replaceChildren();
  }
  const message = error?.code === 'permission-denied'
    ? 'Your secure sign-in session has expired. Please log out and sign in again to view notifications.'
    : 'Notifications are temporarily unavailable. Please try again later.';
  notificationList.textContent = message;
}

async function verifySuccessfulPaymentReturn(user) {
  const url = new URL(window.location.href);
  const invoiceId = url.searchParams.get('invoice') || '';
  if (url.searchParams.get('payment') !== 'success' || !invoiceId) return;
  try {
    const response = await fetch('/api/verify-checkout-session.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await user.getIdToken()}` },
      body: JSON.stringify({ invoiceId }),
    });
    const raw = await response.text();
    const result = JSON.parse(raw);
    if (!response.ok || !result.ok) throw new Error(result.message || 'Payment verification could not be completed.');
    url.searchParams.delete('payment');
    url.searchParams.delete('invoice');
    history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
  } catch (error) {
    console.error('Payment return verification failed:', error);
  }
}

authReady.then(() => onAuthStateChanged(auth, (user) => {
  if (!notificationList) return;
  stopCustomerNotificationListener?.();
  stopCustomerNotificationListener = null;
  customerNotifications = [];
  currentNotificationsPage = 1;
  if (notificationPagination) {
    notificationPagination.hidden = true;
    notificationPagination.replaceChildren();
  }
  notificationFallbackTimes = new Map();
  closeNotificationDropdown();
  currentNotificationUserId = user?.uid || '';
  seenNotificationIds = new Set();
  if (!user) {
    if (notificationBell) notificationBell.hidden = true;
    renderNotificationPreview();
    updateNotificationBell();
    notificationList.textContent = 'Sign in to view your notifications.';
    return;
  }
  // Firestore rules require an active server-side MFA session. Do not issue a
  // query before the dashboard's OTP gate has completed.
  if (!hasOtpVerification(user)) {
    if (notificationBell) notificationBell.hidden = true;
    renderNotificationPreview();
    updateNotificationBell();
    notificationList.textContent = 'Complete email verification to view your notifications.';
    return;
  }
  void verifySuccessfulPaymentReturn(user);
  if (notificationBell) notificationBell.hidden = false;
  try {
    const storedIds = JSON.parse(localStorage.getItem(notificationStorageKey()) || '[]');
    if (Array.isArray(storedIds)) seenNotificationIds = new Set(storedIds.filter((id) => typeof id === 'string'));
  } catch (error) { console.warn('Notification seen state could not be loaded locally.', error); }
  notificationList.textContent = 'Loading notifications…';
  const customerNotificationsQuery = query(collection(db, 'notifications'), where('userId', '==', user.uid));
  stopCustomerNotificationListener = onSnapshot(customerNotificationsQuery, (snapshot) => {
    customerNotifications = snapshot.docs.map((item) => {
      if (!notificationFallbackTimes.has(item.id)) notificationFallbackTimes.set(item.id, Date.now());
      return { id: item.id, ...item.data(), _receivedAtFallbackMs: notificationFallbackTimes.get(item.id) };
    })
      .sort((a, b) => notificationTimeMs(b) - notificationTimeMs(a));
    renderCustomerNotifications();
    if (notificationDropdown && !notificationDropdown.hidden) markCustomerNotificationsSeen();
  }, (error) => {
    showNotificationLoadError(error);
  });
}), showNotificationLoadError).catch(showNotificationLoadError);

function invoiceInfo(invoice) {
  const total = Number(invoice.runningTotal ?? invoice.totalAmount ?? invoice.finalAmount ?? 0);
  const downpayment = String(invoice.serviceStatus || '').toLowerCase() === 'completed' ? Number(invoice.downpaymentAmount || 0) : 0;
  const balance = Math.max(0, Number(invoice.balance_due ?? invoice.balanceDue ?? total - downpayment));
  const status = String(invoice.status || 'pending');
  const cancelled = ['cancelled', 'canceled'].includes(status.toLowerCase());
  const paid = status.toLowerCase() === 'paid' || balance <= 0 || cancelled;
  const due = invoice.dueAt?.toDate?.() || (invoice.dueDate ? new Date(invoice.dueDate) : null);
  const overdue = !paid && (status.toLowerCase() === 'overdue' || (due && !Number.isNaN(due.getTime()) && due.getTime() < Date.now()));
  const rawCreated = invoice.createdAt ?? invoice.created_at;
  const created = rawCreated?.toDate ? rawCreated.toDate()
    : typeof rawCreated === 'number' ? new Date(Math.abs(rawCreated) < 1e12 ? rawCreated * 1000 : rawCreated)
      : typeof rawCreated === 'string' && rawCreated.trim()
        ? new Date(Number.isFinite(Number(rawCreated)) ? (Math.abs(Number(rawCreated)) < 1e12 ? Number(rawCreated) * 1000 : Number(rawCreated)) : rawCreated)
        : null;
  const dateParts = created && !Number.isNaN(created.getTime())
    ? new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(created)
    : [];
  const dateValues = Object.fromEntries(dateParts.map(({ type, value }) => [type, value]));
  const creationDate = dateParts.length ? `${dateValues.year}-${dateValues.month}-${dateValues.day}` : invoice.date || 'Date unavailable';
  return { total, balance, paid, overdue, status: cancelled ? 'Cancelled' : paid ? 'Paid' : overdue ? 'Overdue' : status, date: creationDate };
}

function renderInvoiceSummary() {
  const pending = invoices.map(invoiceInfo).filter((info) => !info.paid);
  const unpaidTotal = pending.reduce((sum, info) => sum + info.balance, 0);
  const overdueTotal = pending.filter((info) => info.overdue).reduce((sum, info) => sum + info.balance, 0);
  document.getElementById('pendingBillCount').textContent = String(pending.length);
  document.getElementById('unpaidAmount').textContent = money(unpaidTotal);
  document.getElementById('overdueAmount').textContent = money(overdueTotal);
  if (!summaryInvoices) return;
  const overviewInvoices = invoices.filter((invoice) => String(invoice.status || '').trim().toLowerCase() === 'pending');
  summaryInvoices.innerHTML = overviewInvoices.length ? overviewInvoices.map((invoice, index) => {
    const info = invoiceInfo(invoice);
    const statusKey = String(info.status || '').toLowerCase().replace(/[^a-z0-9-]/g, '-');
    const referenceNumber = invoice.referenceNumber || invoice.invoiceNumber || invoice.id;
    return `<article class="customer-summary-row customer-invoice-summary-row"><span class="invoice-summary-index">${index + 1}</span><div class="invoice-meta-col"><span class="invoice-id-text">${escapeHtml(referenceNumber)}</span><span class="invoice-date-text">${escapeHtml(info.date)}</span></div><span class="invoice-summary-total">${money(info.total)}</span><span class="summary-status ${escapeHtml(statusKey)}">${escapeHtml(info.status)}</span></article>`;
  }).join('') : '<p class="customer-summary-empty">No pending bills.</p>';
  summaryInvoices.querySelectorAll('.customer-invoice-summary-row .summary-status').forEach((element) => {
    const status = element.textContent.toLowerCase();
    if (status.includes('cancel')) element.style.setProperty('color', '#ff3b30', 'important');
    else if (status.includes('pend')) element.style.setProperty('color', '#ff9500', 'important');
    element.style.fontWeight = '600';
  });
}

function renderBookingSummary() {
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const upcoming = bookings.filter((booking) => {
    const date = new Date(`${booking.date || ''}T00:00:00`);
    return !Number.isNaN(date.getTime()) && date >= today && !['cancelled', 'completed'].includes(String(booking.status || '').toLowerCase());
  }).length;
  document.getElementById('upcomingBookingCount').textContent = String(upcoming);
  if (!summaryBookings) return;
  const overviewBookings = bookings.filter((booking) => ['pending', 'confirmed'].includes(String(booking.status || '').trim().toLowerCase()));
  summaryBookings.innerHTML = overviewBookings.length ? overviewBookings.map((booking, index) => {
    const rawStatus = String(booking.status || 'pending');
    const rawStatusKey = rawStatus.toLowerCase().replace(/[^a-z0-9-]/g, '-');
    const statusKey = rawStatusKey === 'in_progress' || rawStatusKey === 'in-progress' ? 'ongoing' : rawStatusKey;
    const status = statusKey === 'ongoing' ? 'Ongoing' : statusKey.charAt(0).toUpperCase() + statusKey.slice(1).replaceAll('-', ' ');
    return `<article class="customer-summary-row customer-booking-summary-row"><span class="booking-summary-index">${index + 1}</span><div class="customer-booking-summary-meta"><strong>${escapeHtml(booking.serviceName || booking.serviceId || 'Service')}</strong><span>${escapeHtml(booking.date || 'Date unavailable')} - ${escapeHtml(booking.timeSlot || '')}</span></div><span class="summary-status ${escapeHtml(statusKey)}">${escapeHtml(status)}</span></article>`;
  }).join('') : '<p class="customer-summary-empty">No pending or confirmed bookings.</p>';
}

window.addEventListener('customer:invoices', (event) => { invoices = event.detail || []; renderInvoiceSummary(); });
window.addEventListener('customer:bookings', (event) => { bookings = event.detail || []; renderBookingSummary(); });
window.addEventListener('customer:profile', (event) => {
  const greeting = document.getElementById('customer-greeting-text');
  if (greeting) greeting.textContent = `Hello ${event.detail?.username || event.detail?.name || 'customer'}`;
});
