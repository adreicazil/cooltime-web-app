import { onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { collection, doc, getDocs, serverTimestamp, setDoc, writeBatch } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { auth, authReady, db } from './firebase-config.js';
import { SERVICES } from './firestore.js';
import { getUserProfile } from './firestore.js';
import { logoutUser } from './auth.js';
import { hasOtpVerification } from './auth-guard.js';

const layout = document.querySelector('.pos-layout');
const catalog = document.getElementById('serviceCatalog');
const cartRoot = document.getElementById('cartItems');
const totalRoot = document.getElementById('cartTotal');
const checkoutButton = document.getElementById('posCheckout');
const statusRoot = document.getElementById('posStatus');
const cart = new Map();
const money = (amount) => `₱${Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[character]));
let currentUser;

async function loadCatalog() {
  try {
    const snapshot = await getDocs(collection(db, 'services'));
    const services = snapshot.empty
      ? Object.entries(SERVICES).map(([id, service]) => ({ id, ...service }))
      : snapshot.docs.map((item) => ({ id: item.id, ...item.data() }));
    const available = services.filter((service) => Number(service.price) > 0 && String(service.status || 'active').toLowerCase() === 'active');
    catalog.innerHTML = available.map((service) => `<article class="service-card"><h3>${escapeHtml(service.name || service.id)}</h3><strong>${money(service.price)}</strong><button class="btn btn-primary add-service" data-id="${escapeHtml(service.id)}" type="button">Add to bill</button></article>`).join('');
    catalog.addEventListener('click', (event) => {
      const button = event.target.closest('.add-service');
      if (!button) return;
      const service = available.find((item) => item.id === button.dataset.id);
      const current = cart.get(service.id);
      cart.set(service.id, { ...service, quantity: (current?.quantity || 0) + 1 });
      renderCart();
    });
  } catch (error) {
    console.error('POS catalog load failed:', error);
    catalog.textContent = 'Services could not be loaded. Check the Firestore service catalog.';
  }
}

function totalAmount() {
  return [...cart.values()].reduce((sum, service) => sum + Number(service.price) * service.quantity, 0);
}

function renderCart() {
  const rows = [...cart.values()];
  cartRoot.innerHTML = rows.length ? rows.map((service) => `<div class="cart-line"><span>${escapeHtml(service.name || service.id)}<small> × ${service.quantity}</small></span><input class="cart-quantity" aria-label="Quantity for ${escapeHtml(service.name || service.id)}" type="number" min="1" max="99" value="${service.quantity}" data-id="${escapeHtml(service.id)}"><strong>${money(service.price * service.quantity)}</strong></div>`).join('') : 'Select services to start a bill.';
  totalRoot.textContent = money(totalAmount());
  checkoutButton.disabled = rows.length === 0;
}

cartRoot.addEventListener('change', (event) => {
  if (!event.target.matches('.cart-quantity')) return;
  const item = cart.get(event.target.dataset.id);
  const quantity = Math.max(1, Math.min(99, Number.parseInt(event.target.value, 10) || 1));
  cart.set(item.id, { ...item, quantity });
  renderCart();
});

async function checkout() {
  checkoutButton.disabled = true;
  statusRoot.textContent = 'Saving invoice…';
  const lines = [...cart.values()].map((service) => ({ id: service.id, name: service.name || service.id, price: Number(service.price), quantity: service.quantity, lineTotal: Number(service.price) * service.quantity }));
  const total = totalAmount();
  const branchId = document.getElementById('branchSelect').value;
  const invoiceRef = doc(collection(db, 'invoices'));
  const paymentMethod = document.getElementById('paymentMethod').value;
  const customerName = document.getElementById('walkInName').value.trim() || 'Walk-in customer';
  const paymentRef = paymentMethod === 'cash' ? doc(collection(db, 'payments')) : null;
  const invoice = {
    invoiceId: invoiceRef.id, customerId: 'walk-in', customerName, branchId,
    source: 'pos', bookingId: null, servicesAdded: lines.map((line) => line.id), serviceDetails: lines,
    runningTotal: total, finalAmount: total, totalAmount: total,
    status: paymentMethod === 'cash' ? 'paid' : 'pending',
    paymentMethod, ...(paymentRef ? { paymentId: paymentRef.id } : {}),
    createdBy: currentUser.uid, createdAt: serverTimestamp(), updatedAt: serverTimestamp()
  };
  try {
    const batch = writeBatch(db);
    batch.set(invoiceRef, invoice);
    if (paymentMethod === 'cash') {
      batch.set(paymentRef, {
        invoiceId: invoiceRef.id, customerId: 'walk-in', branchId, amount: total, currency: 'PHP',
        amountUnit: 'pesos', method: 'cash', status: 'paid', referenceNumber: invoiceRef.id,
        createdAt: serverTimestamp(), paidAt: serverTimestamp()
      });
    }
    await batch.commit();
    renderReceipt(invoiceRef.id, invoice);
    if (paymentMethod === 'paymongo') {
      const response = await fetch('api/create-checkout-session.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await currentUser.getIdToken()}` },
        body: JSON.stringify({ invoiceId: invoiceRef.id })
      });
      const result = await response.json();
      if (!response.ok || !result.checkoutUrl) throw new Error(result.message || 'PayMongo checkout could not be started. The pending invoice was saved.');
      const payLink = document.getElementById('receiptPayLink');
      payLink.href = result.checkoutUrl;
      payLink.hidden = false;
    }
    statusRoot.textContent = `${paymentMethod === 'cash' ? 'Paid' : 'Pending payment'} invoice ${invoiceRef.id} saved.`;
    cart.clear();
    renderCart();
  } catch (error) {
    console.error('POS checkout failed:', error);
    statusRoot.textContent = error.message || 'Checkout failed.';
  } finally {
    checkoutButton.disabled = cart.size === 0;
  }
}

function renderReceipt(id, invoice) {
  const preview = document.getElementById('receiptPreview');
  document.getElementById('receiptPayLink').hidden = true;
  document.getElementById('receiptBody').innerHTML = `<p><strong>CoolTime Auto Aircon</strong><br>Branch: ${escapeHtml(invoice.branchId === 'cabuyao' ? 'Cabuyao Main' : 'Santa Rosa')}</p><p>Invoice: ${escapeHtml(id)}<br>Customer: ${escapeHtml(invoice.customerName)}<br>Status: ${escapeHtml(invoice.status)}</p><table><thead><tr><th>Service</th><th>Qty</th><th>Amount</th></tr></thead><tbody>${invoice.serviceDetails.map((line) => `<tr><td>${escapeHtml(line.name)}</td><td>${line.quantity}</td><td>${money(line.lineTotal)}</td></tr>`).join('')}</tbody><tfoot><tr><th colspan="2">Total</th><th>${money(invoice.totalAmount)}</th></tr></tfoot></table><p>Thank you for choosing CoolTime.</p>`;
  preview.hidden = false;
  preview.scrollIntoView({ behavior: 'smooth' });
}

checkoutButton.addEventListener('click', checkout);
document.getElementById('printReceipt').addEventListener('click', () => window.print());
document.getElementById('posLogout').addEventListener('click', () => logoutUser('login.html'));

authReady.then(() => new Promise((resolve) => {
  const unsubscribe = onAuthStateChanged(auth, (user) => { unsubscribe(); resolve(user); });
})).then(async (user) => {
  if (!user) { window.location.replace('login.html'); return; }
  if (!hasOtpVerification(user)) { window.location.replace('otp.html'); return; }
  const profile = await getUserProfile(user.uid);
  if (!['admin', 'super_admin'].includes(String(profile?.role || '').toLowerCase())) { window.location.replace('login.html'); return; }
  currentUser = user;
  layout.hidden = false;
  await loadCatalog();
}).catch((error) => {
  console.error('POS initialization failed:', error);
  statusRoot.textContent = 'POS could not start. Please sign in with an admin account.';
});
