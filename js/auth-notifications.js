import { onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { collection, doc, onSnapshot, query, serverTimestamp, updateDoc, where } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { auth, authReady, db } from './firebase-config.js';
import { getUserProfile } from './firestore.js';
import { hasOtpVerification, normalizeRole } from './auth-guard.js';

const wrap = document.getElementById('customerNotificationBellWrap');
const bell = document.getElementById('customerNotificationBell');
const dot = document.getElementById('customerNotificationDot');
const dropdown = document.getElementById('customerNotificationDropdown');
const previewList = document.getElementById('customerNotificationPreviewList');
const viewAll = document.getElementById('customerNotificationViewAll');
if (!wrap || !bell || !dot || !dropdown || !previewList) {
  // The customer dashboard owns a richer notification experience in its module.
} else {
  let stopListening = null;
  let activeUser = null;
  let items = [];
  let authStateVersion = 0;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const dateOf = (item) => {
    const value = item.createdAt || item.created_at || item.timestamp;
    if (value?.toDate) return value.toDate();
    const date = value ? new Date(value) : new Date();
    return Number.isNaN(date.getTime()) ? new Date() : date;
  };
  const isSeen = (item) => item.seen === true || item.read === true || Boolean(item.readAt || item.seenAt);

  function render() {
    const unread = items.filter((item) => !isSeen(item)).length;
    dot.hidden = unread === 0;
    bell.setAttribute('aria-label', unread ? `Open notifications, ${unread} unread` : 'Open notifications');
    const recent = [...items].sort((a, b) => dateOf(b) - dateOf(a)).slice(0, 5);
    previewList.innerHTML = recent.length ? recent.map((item) => {
      const date = dateOf(item);
      const category = String(item.type || item.category || 'notification').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
      return `<div class="customer-notification-preview-row"><span class="customer-notification-preview-meta"><span class="customer-notification-category">${escapeHtml(category)}</span><time>${escapeHtml(date.toLocaleString())}</time></span><span class="customer-notification-preview-message">${escapeHtml(item.message || item.title || 'Notification')}</span></div>`;
    }).join('') : '<p class="customer-notification-preview-empty">You are all caught up.</p>';
  }

  function closeDropdown() {
    dropdown.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
  }

  async function markSeen() {
    const unseen = items.filter((item) => !isSeen(item));
    items = items.map((item) => unseen.some((unread) => unread.id === item.id) ? { ...item, seen: true } : item);
    render();
    await Promise.allSettled(unseen.map((item) => updateDoc(doc(db, 'notifications', item.id), { seen: true, readAt: serverTimestamp() })));
  }

  bell.addEventListener('click', async () => {
    const opening = dropdown.hidden;
    dropdown.hidden = !opening;
    bell.setAttribute('aria-expanded', String(opening));
    if (!opening) return;
    const rect = bell.getBoundingClientRect();
    const maxRight = Math.max(10, window.innerWidth - Math.min(360, window.innerWidth - 20) - 10);
    dropdown.style.top = `${rect.bottom + 8}px`;
    dropdown.style.right = `${Math.min(maxRight, Math.max(10, window.innerWidth - rect.right))}px`;
    await markSeen();
  });
  document.addEventListener('click', (event) => {
    if (!dropdown.hidden && !wrap.contains(event.target)) closeDropdown();
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeDropdown(); });
  viewAll?.addEventListener('click', () => {
    const path = normalizeRole(document.body.dataset.accountRole);
    const target = path === 'admin' ? 'admin-dashboard.html#notifications'
      : path === 'super_admin' ? 'super-dashboard.html#notifications'
        : 'customer-dashboard.html#notifications';
    window.location.assign(target);
  });

  authReady.then(() => onAuthStateChanged(auth, async (user) => {
    const version = ++authStateVersion;
    stopListening?.();
    stopListening = null;
    activeUser = null;
    items = [];
    render();
    bell.hidden = true;
    closeDropdown();
    if (!user || !hasOtpVerification(user)) return;
    let role = 'customer';
    try {
      const profile = await getUserProfile(user.uid);
      role = normalizeRole(profile?.role);
      if (String(profile?.status || 'active').toLowerCase() !== 'active') return;
    } catch (error) {
      console.error('CoolTime notification profile lookup failed:', error);
      return;
    }
    if (version !== authStateVersion) return;
    if (!['customer', 'admin', 'super_admin'].includes(role)) return;
    document.body.dataset.accountRole = role;
    activeUser = user;
    bell.hidden = false;
    const notificationQuery = role === 'customer'
      ? query(collection(db, 'notifications'), where('userId', '==', user.uid))
      : query(collection(db, 'notifications'));
    stopListening = onSnapshot(notificationQuery, (snapshot) => {
      if (activeUser?.uid !== user.uid) return;
      items = snapshot.docs.map((entry) => ({ id: entry.id, ...entry.data() }))
        .filter((item) => role !== 'customer' || item.userId === user.uid)
        .filter((item) => role === 'super_admin' || !item.recipientRole || item.recipientRole === role);
      render();
    }, (error) => {
      bell.hidden = true;
      console.error('CoolTime navbar notifications could not be loaded:', error);
    });
  })).catch((error) => console.error('CoolTime notification initialization failed:', error));
}
