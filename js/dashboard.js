import { onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { collection, onSnapshot } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { auth, authReady, db } from './firebase-config.js';
import { getUserProfile } from './firestore.js';
import { logoutUser } from './auth.js';
import { setActiveNavigation, toggleSidebar } from './ui.js';
import { hasOtpVerification, normalizeRole } from './auth-guard.js';

setActiveNavigation();

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
}

function showFirestoreError(error) {
  console.error('Admin Firestore request failed:', error);
  let notice = document.getElementById('adminFirestoreNotice');
  if (!notice) {
    notice = document.createElement('p');
    notice.id = 'adminFirestoreNotice';
    notice.setAttribute('role', 'alert');
    notice.style.cssText = 'margin:12px;padding:12px;border:1px solid #b42318;border-radius:8px;background:#fff1f0;color:#7a271a';
    document.body.prepend(notice);
  }
  notice.textContent = error?.code === 'permission-denied' || /permission/i.test(error?.message || '')
    ? 'Firestore denied this admin read. Confirm this Firebase account is signed in, its users/{UID} document has an active admin or super_admin role, and the latest firestore.rules are published.'
    : `Admin data could not be loaded: ${error?.message || 'Unknown Firestore error.'}`;
}

function amountInPesos(record) {
  const amount = Number(record.finalAmount ?? record.amount ?? record.runningTotal ?? 0);
  // PayMongo amounts are centavos; existing POS records may already be pesos.
  const isPayMongoCentavos = String(record.method || '').startsWith('paymongo') || record.amountUnit === 'centavos';
  return Number.isFinite(amount) ? (isPayMongoCentavos && record.amount != null ? amount / 100 : amount) : 0;
}

function normalizeBranchId(value) {
  const id = String(value || '').toLowerCase().replace(/[\s-]+/g, '_');
  if (['cabuyao', 'cabuyao_main', 'cabuyao_branch'].includes(id)) return 'cabuyao';
  if (['santarosa', 'santa_rosa', 'santa_rosa_branch'].includes(id)) return 'santarosa';
  return id;
}

let bookingRows = [];
let paymentRows = [];
let userRows = [];
let signedInAdmin = null;
let signedInRole = '';
let manageableAccounts = [];

async function accountApi(path, payload) {
  const response = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await signedInAdmin.getIdToken(true)}` },
    body: JSON.stringify(payload)
  });
  const result = await response.json();
  if (!response.ok || !result.ok) throw new Error(result.message || 'Account request failed.');
  return result;
}

function renderManageableAccounts() {
  const tbody = document.getElementById('dashboardAccountRows');
  if (!tbody) return;
  if (!manageableAccounts.length) {
    tbody.innerHTML = '<tr><td colspan="6">No admin or employee accounts found.</td></tr>';
    return;
  }
  tbody.innerHTML = manageableAccounts.map((account) => {
    const created = account.createdAt ? new Date(account.createdAt).toLocaleDateString() : '—';
    const status = String(account.status || 'active').toLowerCase();
    const roleName = normalizeRole(account.role) === 'staff' ? 'Employee' : 'Admin';
    return `<tr><td>${escapeHtml(account.name || account.username || '')}</td><td>${escapeHtml(account.email || '')}</td><td>${roleName}</td><td>${escapeHtml(created)}</td><td>${escapeHtml(status)}</td><td><button class="action-btn account-status-action" data-id="${escapeHtml(account.id)}" data-action="${status === 'disabled' ? 'enable' : 'disable'}">${status === 'disabled' ? 'Enable' : 'Disable'}</button> <button class="action-btn account-delete-action" data-id="${escapeHtml(account.id)}">Delete</button></td></tr>`;
  }).join('');
}

async function loadManageableAccounts() {
  if (signedInRole !== 'super_admin') return;
  const tbody = document.getElementById('dashboardAccountRows');
  if (!tbody) return;
  try {
    const result = await accountApi('api/admin-workflow.php', { action: 'list_manageable_accounts' });
    manageableAccounts = result.accounts || [];
    renderManageableAccounts();
  } catch (error) {
    tbody.innerHTML = `<tr><td colspan="6">${escapeHtml(error.message)}</td></tr>`;
  }
}

function generateAccountPassword() {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
}

async function createManagedAccount(event) {
  event.preventDefault();
  if (signedInRole !== 'super_admin') return;
  const form = event.currentTarget;
  const output = form.querySelector('output');
  const fields = Object.fromEntries(new FormData(form));
  try {
    const result = await accountApi('api/admin-accounts.php', { action: 'create', ...fields });
    form.reset();
    form.elements.password.type = 'password';
    output.textContent = result.message || 'Admin account created successfully';
    await loadManageableAccounts();
  } catch (error) {
    output.textContent = error.message;
  }
}

async function manageAccountAction(event) {
  if (signedInRole !== 'super_admin') return;
  const button = event.target.closest('button');
  if (!button) return;
  const account = manageableAccounts.find((item) => item.id === button.dataset.id);
  if (!account) return;
  button.disabled = true;
  try {
    if (button.classList.contains('account-delete-action')) {
      if (!confirm(`Delete ${account.email || account.name}'s account? This cannot be undone.`)) { button.disabled = false; return; }
      await accountApi('api/admin-accounts.php', { action: 'delete', uid: account.id, name: account.name || account.username, email: account.email, role: account.role, status: account.status || 'active' });
    } else {
      const status = button.dataset.action === 'disable' ? 'disabled' : 'active';
      await accountApi('api/admin-accounts.php', { action: 'update', uid: account.id, name: account.name || account.username, email: account.email, role: account.role, status });
    }
    await loadManageableAccounts();
  } catch (error) {
    alert(error.message);
    button.disabled = false;
  }
}

function renderBranchAnalytics() {
  const branches = {
    cabuyao: { name: 'Cabuyao Main', bookings: 0, paid: 0, sales: 0 },
    santarosa: { name: 'Santa Rosa', bookings: 0, paid: 0, sales: 0 }
  };
  bookingRows.forEach((booking) => {
    const branch = branches[normalizeBranchId(booking.branchId)];
    if (branch) branch.bookings += 1;
  });
  let todaySales = 0;
  const todayStr = new Date().toISOString().split('T')[0];
  paymentRows.forEach((payment) => {
    const status = String(payment.status || '').trim().toLowerCase();
    if (!['paid', 'succeeded', 'completed'].includes(status)) return;
    const branch = branches[normalizeBranchId(payment.branchId)];
    const amount = amountInPesos(payment);
    if (branch) {
      branch.paid += 1;
      branch.sales += amount;
    }
    const paymentDate = payment.date || payment.paidAt || payment.createdAt;
    const dateValue = paymentDate?.toDate?.() || (paymentDate ? new Date(paymentDate) : null);
    const dateKey = typeof paymentDate === 'string' && /^\d{4}-\d{2}-\d{2}/.test(paymentDate)
      ? paymentDate.slice(0, 10)
      : dateValue && !Number.isNaN(dateValue.getTime()) ? dateValue.toISOString().slice(0, 10) : '';
    if (status === 'paid' && dateKey === todayStr) todaySales += amount;
  });
  calculateDashboardMetrics(bookingRows, userRows, bookingRows, paymentRows, todaySales);
  const body = document.getElementById('branchMetricsBody');
  if (body) body.innerHTML = Object.values(branches).map((branch) => `<tr><td>${escapeHtml(branch.name)}</td><td>${branch.bookings.toLocaleString()}</td><td>${branch.paid.toLocaleString()}</td><td>PHP ${Number(branch.sales).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td></tr>`).join('');
}

function calculateDashboardMetrics(bookings, users, walkInCustomers, posTransactions, paidSalesToday) {
  const emails = new Set();
  (users || []).filter((user) => String(user.role || '').trim().toLowerCase() === 'customer')
    .forEach((user) => { if (user.email) emails.add(String(user.email).trim().toLowerCase()); });
  (walkInCustomers || []).forEach((customer) => {
    const email = customer.email || customer.customerEmail;
    if (email) emails.add(String(email).trim().toLowerCase());
  });

  // paidSalesToday is supplied from the same live payment snapshot used for branch totals.
  const totalSales = Number.isFinite(paidSalesToday) ? paidSalesToday : (posTransactions || [])
    .filter((transaction) => String(transaction.status || '').trim().toLowerCase() === 'paid'
      && String(transaction.date || '').startsWith(new Date().toISOString().split('T')[0]))
    .reduce((sum, transaction) => sum + amountInPesos(transaction), 0);
  const metrics = {
    bookings: (bookings || []).length,
    customers: emails.size,
    accounts: (users || []).length,
    vehicles: (bookings || []).filter((booking) => String(booking.status || '').trim().toLowerCase() === 'confirmed').length,
    todaySales: `PHP ${totalSales.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  };
  Object.entries(metrics).forEach(([name, value]) => {
    document.querySelectorAll(`[data-metric="${name}"]:not(.metric-opt)`).forEach((element) => {
      element.textContent = typeof value === 'number' ? value.toLocaleString() : value;
    });
  });
  window.dispatchEvent(new CustomEvent('cooltime:dashboard-metrics-updated', {
    detail: { bookings: bookings || [], users: users || [], payments: posTransactions || [] }
  }));
}

function startBranchAnalytics() {
  const stopBookings = onSnapshot(collection(db, 'bookings'), (snapshot) => {
    bookingRows = snapshot.docs.map((item) => item.data());
    renderBranchAnalytics();
  }, showFirestoreError);
  const stopUsers = onSnapshot(collection(db, 'users'), (snapshot) => {
    userRows = snapshot.docs.map((item) => item.data());
    renderBranchAnalytics();
  }, showFirestoreError);
  const stopPayments = onSnapshot(collection(db, 'payments'), (snapshot) => {
    paymentRows = snapshot.docs.map((item) => item.data());
    renderBranchAnalytics();
  }, showFirestoreError);
  return () => { stopBookings(); stopUsers(); stopPayments(); };
}

function renderPendingBookings(snapshot) {
  const body = document.getElementById('pendingBookingsBody');
  if (!body) return;
  if (snapshot.empty) {
    body.innerHTML = '<tr><td colspan="7">No bookings found.</td></tr>';
    return;
  }
  body.innerHTML = snapshot.docs.map((item) => {
    const booking = item.data();
    const status = String(booking.status || 'pending').toLowerCase();
    const canApprove = status === 'pending';
    const canCancel = !['cancelled', 'completed', 'paid'].includes(status);
    return `<tr><td>${escapeHtml(item.id)}</td><td>${escapeHtml(booking.customerId)}</td><td>${escapeHtml(booking.serviceId)}</td><td>${escapeHtml(booking.date)} ${escapeHtml(booking.timeSlot)}</td><td>${escapeHtml(booking.branchId)}</td><td><span class="status ${escapeHtml(status)}">${escapeHtml(status.replaceAll('_', ' '))}</span></td><td>${canApprove ? `<button class="action-btn booking-action" data-id="${escapeHtml(item.id)}" data-status="confirmed">Approve</button> ` : ''}${canCancel ? `<button class="action-btn booking-action" data-id="${escapeHtml(item.id)}" data-status="cancelled">Cancel / release slot</button>` : ''}</td></tr>`;
  }).join('');
}

function startPendingBookings() {
  return onSnapshot(collection(db, 'bookings'), renderPendingBookings, showFirestoreError);
}

document.addEventListener('click', async (event) => {
  const button = event.target.closest('.booking-action');
  if (!button) return;
  button.disabled = true;
  try {
    if (button.dataset.status === 'cancelled') {
      const reason = await prompt('Enter the cancellation reason to include in the customer email:');
      if (!reason?.trim()) { button.disabled = false; return; }
      const response = await fetch('api/admin-workflow.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await signedInAdmin.getIdToken(true)}` },
        body: JSON.stringify({ action: 'cancel_booking', bookingId: button.dataset.id, reason })
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Cancellation could not be saved.');
      if (result.message) alert(result.message);
    } else {
      const response = await fetch('api/admin-workflow.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await signedInAdmin.getIdToken(true)}` },
        body: JSON.stringify({ action: 'approve_booking', bookingId: button.dataset.id })
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Booking approval could not be saved.');
      if (result.message) alert(result.message);
    }
  } catch (error) {
    console.error('Booking update failed:', error);
    alert('The booking could not be updated. Please try again.');
    button.disabled = false;
  }
});

async function initializeDashboard(user) {
  if (!user) return denyAccess();
  if (!hasOtpVerification(user)) { window.location.replace('otp.html'); return; }
  const profile = await getUserProfile(user.uid);
  const role = normalizeRole(profile?.role);
  const requiredRole = document.body.dataset.requiredRole;
  if (!profile || String(profile.status || 'active').trim().toLowerCase() !== 'active'
    || !['admin', 'super_admin'].includes(role)
    || (requiredRole && role !== normalizeRole(requiredRole))) return denyAccess();
  const username = String(profile.name || user.displayName || profile.username || user.email?.split('@')[0] || 'User').trim() || 'User';
  window.cooltimeDashboardIdentity = username;
  const greeting = document.getElementById(role === 'super_admin' ? 'superadmin-username-display' : 'admin-username-display');
  if (greeting) greeting.textContent = username;
  window.dispatchEvent(new CustomEvent('cooltime:dashboard-identity', { detail: { username } }));
  signedInAdmin = user;
  signedInRole = role;
  document.querySelector('.dashboard-shell')?.removeAttribute('hidden');
  if (role === 'super_admin') {
    document.querySelectorAll('.super-only').forEach((node) => { node.hidden = false; });
    document.getElementById('dashboardAccountForm')?.addEventListener('submit', createManagedAccount);
    document.getElementById('generateAccountPassword')?.addEventListener('click', () => {
      const input = document.querySelector('#dashboardAccountForm [name="password"]');
      if (input) { input.value = generateAccountPassword(); input.type = 'text'; }
    });
    document.getElementById('dashboardAccountRows')?.addEventListener('click', manageAccountAction);
    await loadManageableAccounts();
  }
  startPendingBookings();
  startBranchAnalytics();
}

function denyAccess() {
  alert('Access Denied');
  redirectToLoginWhenSignedOut();
}

function redirectToLoginWhenSignedOut() {
  authReady.then(() => {
    let unsubscribe = () => {};
    unsubscribe = onAuthStateChanged(auth, (user) => {
      unsubscribe();
      // A live Firebase user with a matching OTP UID must remain on this page,
      // including while Firestore/API services are temporarily unavailable.
      if (!user || sessionStorage.getItem('cooltimeOtpVerifiedUid') !== user.uid) {
        if (user) window.location.replace('otp.html');
        else window.location.href = 'login.html';
      }
    });
  }).catch((error) => console.error('Firebase auth initialization failed:', error));
}

authReady.then(() => onAuthStateChanged(auth, (user) => {
  initializeDashboard(user).catch((error) => {
    console.error('Dashboard initialization failed:', error);
    // A failed profile read (for example, a transient network error) does not
    // mean the Firebase session is invalid. Redirecting here made returning
    // from Operations & Reports appear to log the administrator out.
    showFirestoreError(error);
  });
}));

// A BFCache restore can fire before Firebase has finished restoring auth. Recheck
// the same Firebase user and OTP marker used during normal page startup, and
// leave the cached page in place when auth/profile services are still settling.
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    // BFCache restored this page during back/forward navigation. Keep the
    // restored session and let the regular Firebase observer handle auth.
    return;
  }
});

document.getElementById('menuBtn')?.addEventListener('click', toggleSidebar);
document.getElementById('logoutBtn')?.addEventListener('click', async (event) => {
  event.preventDefault();
  await logoutUser('login.html');
});
document.querySelectorAll('[data-dashboard-header-logout]').forEach((button) => {
  button.addEventListener('click', async (event) => {
    event.preventDefault();
    await logoutUser('login.html');
  });
});
