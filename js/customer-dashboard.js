import { onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { collection, onSnapshot, query, where } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { auth, db } from './firebase-config.js';

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
let stopCustomerNotificationListener = null;

const notificationList = document.getElementById('customerNotifications');
const notificationDialog = document.getElementById('notificationDetailModal');
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

function renderCustomerNotifications() {
  if (!notificationList) return;
  notificationList.innerHTML = customerNotifications.length
    ? customerNotifications.map((item, index) => {
      const date = item.createdAt?.toDate?.() || (item.createdAt ? new Date(item.createdAt) : null);
      const stamp = date && !Number.isNaN(date.getTime()) ? date.toLocaleString('en-PH') : '';
      return `<button type="button" class="customer-notification-row" data-notification-index="${index}"><span>${escapeHtml(item.message || item.title || 'Notification')}</span>${stamp ? `<time>${escapeHtml(stamp)}</time>` : ''}</button>`;
    }).join('')
    : '<p class="customer-notification-empty">No notifications yet.</p>';
}

function showCustomerNotification(item) {
  if (!notificationDialog || !item) return;
  const title = notificationDialog.querySelector('#notificationDetailTitle');
  const content = notificationDialog.querySelector('.notification-detail-content');
  if (title) title.textContent = item.message || item.title || 'Notification details';
  const values = notificationFields.map(([label, key]) => {
    const value = ['serviceCost', 'paymentBalance'].includes(key) ? formatNotificationAmount(item[key]) : item[key];
    return value === undefined || value === null || value === '' ? '' : `<dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd>`;
  }).filter(Boolean).join('');
  if (content) content.innerHTML = `<dl>${values || '<dd>No booking details are attached to this notification.</dd>'}</dl>`;
  notificationDialog.showModal();
}

notificationList?.addEventListener('click', (event) => {
  const row = event.target.closest('[data-notification-index]');
  if (row) showCustomerNotification(customerNotifications[Number(row.dataset.notificationIndex)]);
});

onAuthStateChanged(auth, (user) => {
  if (!notificationList) return;
  stopCustomerNotificationListener?.();
  stopCustomerNotificationListener = null;
  if (!user) {
    customerNotifications = [];
    renderCustomerNotifications();
    return;
  }
  const customerNotificationsQuery = query(collection(db, 'notifications'), where('userId', '==', user.uid));
  stopCustomerNotificationListener = onSnapshot(customerNotificationsQuery, (snapshot) => {
    customerNotifications = snapshot.docs.map((item) => ({ id: item.id, ...item.data() }))
      .sort((a, b) => (b.createdAt?.toMillis?.() || 0) - (a.createdAt?.toMillis?.() || 0));
    renderCustomerNotifications();
  }, (error) => {
    console.error('Customer notifications could not be loaded:', error);
    notificationList.innerHTML = '<p class="customer-notification-empty">Notifications are temporarily unavailable.</p>';
  });
});

function invoiceInfo(invoice) {
  const total = Number(invoice.runningTotal ?? invoice.totalAmount ?? invoice.finalAmount ?? 0);
  const downpayment = String(invoice.serviceStatus || '').toLowerCase() === 'completed' ? Number(invoice.downpaymentAmount || 0) : 0;
  const balance = Math.max(0, Number(invoice.balance_due ?? invoice.balanceDue ?? total - downpayment));
  const status = String(invoice.status || 'pending');
  const cancelled = ['cancelled', 'canceled'].includes(status.toLowerCase());
  const paid = status.toLowerCase() === 'paid' || balance <= 0 || cancelled;
  const due = invoice.dueAt?.toDate?.() || (invoice.dueDate ? new Date(invoice.dueDate) : null);
  const overdue = !paid && (status.toLowerCase() === 'overdue' || (due && !Number.isNaN(due.getTime()) && due.getTime() < Date.now()));
  const created = invoice.createdAt?.toDate?.() || (invoice.createdAt ? new Date(invoice.createdAt) : null);
  return { total, balance, paid, overdue, status: cancelled ? 'Cancelled' : paid ? 'Paid' : overdue ? 'Overdue' : status, date: created && !Number.isNaN(created.getTime()) ? created.toLocaleDateString() : invoice.date || 'Date unavailable' };
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
    return `<article class="customer-summary-row customer-invoice-summary-row"><span class="invoice-summary-index">${index + 1}</span><div class="invoice-meta-col"><span class="invoice-id-text">${escapeHtml(invoice.id)}</span><span class="invoice-date-text">${escapeHtml(info.date)}</span></div><span class="invoice-summary-total">${money(info.total)}</span><span class="summary-status ${escapeHtml(statusKey)}">${escapeHtml(info.status)}</span></article>`;
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
