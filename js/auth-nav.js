import { onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { auth, authReady } from './firebase-config.js';
import { getUserProfile } from './firestore.js';
import { hasOtpVerification, normalizeRole } from './auth-guard.js';
import { logoutUser } from './auth.js';

const AUTH_NAV_CACHE_KEY = 'cooltimeAuthNavState';

function displayName(profile, user) {
  return String(profile?.fullName || profile?.name || profile?.displayName || profile?.username || user?.displayName || user?.email?.split('@')[0] || 'User').trim() || 'User';
}

function dashboardLabel(name) {
  const cleanName = String(name || 'User').trim();
  return `${cleanName}${/[sS]$/.test(cleanName) ? "'" : "'s"} Dashboard`;
}

function updateDashboardIdentity(name, signedIn) {
  if (!signedIn || !name) return;
  const label = dashboardLabel(name);
  document.querySelectorAll('[data-auth-bookings]').forEach((link) => {
    link.textContent = label;
    link.href = 'customer-dashboard.html';
  });
  document.querySelectorAll('[data-auth-dashboard-heading]').forEach((heading) => { heading.textContent = label; });
  const pageTitle = document.querySelector('[data-auth-dashboard-title]');
  if (pageTitle) document.title = `${label} | CoolTime`;
}

function readCachedState() {
  for (const storageName of ['localStorage', 'sessionStorage']) {
    try {
      const storage = window[storageName];
      const value = JSON.parse(storage.getItem(AUTH_NAV_CACHE_KEY) || 'null');
      if (value && typeof value.signedIn === 'boolean') return value;
    } catch {
      // Storage can be unavailable or contain invalid data; Firebase remains authoritative.
    }
  }
  return null;
}

function cacheState(state) {
  for (const storageName of ['localStorage', 'sessionStorage']) {
    try {
      const storage = window[storageName];
      storage.setItem(AUTH_NAV_CACHE_KEY, JSON.stringify(state));
    } catch {
      // The navigation still works when browser storage is disabled.
    }
  }
}

const nav = document.querySelector('.main-nav');

if (nav) {
  const loginLink = nav.querySelector('[data-auth-session]');
  const bookingsLinks = [...document.querySelectorAll('[data-auth-bookings]')];
  const bookNowLink = nav.querySelector('a[href="book-now.html"]');
  const bookingsLink = bookingsLinks[0];
  if (bookingsLink) bookingsLink.href = 'my-bookings.html';
  const cachedState = readCachedState();
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';

  nav.querySelectorAll('a[href]').forEach((link) => {
    const target = link.getAttribute('href')?.split('#')[0];
    link.classList.toggle('active', Boolean(target && target === currentPage));
  });

  // Paint the last known state synchronously so the header does not wait for
  // Firebase's persisted-auth initialization on every page navigation.
  if (cachedState) {
    if (bookNowLink) bookNowLink.hidden = cachedState.signedIn;
    if (loginLink) {
      loginLink.textContent = cachedState.signedIn ? 'Log out' : 'Log in';
      loginLink.href = cachedState.signedIn ? '#' : 'login.html';
      loginLink.dataset.authenticated = String(cachedState.signedIn);
    }
    if (bookingsLink) {
      bookingsLink.href = cachedState.signedIn && cachedState.customer ? 'customer-dashboard.html' : 'my-bookings.html';
      bookingsLinks.forEach((link) => { link.hidden = !(cachedState.signedIn && cachedState.customer); });
      if (cachedState.customer) updateDashboardIdentity(cachedState.name, true);
    }
    nav.removeAttribute('data-auth-pending');
  }

  loginLink?.addEventListener('click', async (event) => {
    if (loginLink.dataset.authenticated !== 'true') return;
    event.preventDefault();
    loginLink.setAttribute('aria-disabled', 'true');
    try {
      await logoutUser('index.html');
    } catch (error) {
      loginLink.removeAttribute('aria-disabled');
      console.error('CoolTime log out failed:', error);
    }
  });

  authReady.then(() => onAuthStateChanged(auth, async (user) => {
    const signedIn = Boolean(user);
    if (bookNowLink) bookNowLink.hidden = signedIn;
    if (loginLink) {
      loginLink.textContent = signedIn ? 'Log out' : 'Log in';
      loginLink.href = signedIn ? '#' : 'login.html';
      loginLink.dataset.authenticated = String(signedIn);
      loginLink.removeAttribute('aria-disabled');
    }
    // Set the known auth state immediately; profile lookup only affects the
    // optional customer bookings link and must not delay the whole header.
    nav.removeAttribute('data-auth-pending');
    if (!signedIn || !hasOtpVerification(user)) {
      bookingsLinks.forEach((link) => { link.href = 'my-bookings.html'; link.hidden = true; });
      cacheState({ signedIn, customer: false });
      return;
    }
    let customer = false;
    let name = displayName(null, user);

    try {
      const profile = await getUserProfile(user.uid);
      customer = normalizeRole(profile?.role) === 'customer';
      name = displayName(profile, user);
    } catch (error) {
      console.error('CoolTime navigation profile lookup failed:', error);
    }
    bookingsLinks.forEach((link) => {
      link.href = customer ? 'customer-dashboard.html' : 'my-bookings.html';
      link.hidden = !customer;
    });
    if (customer) updateDashboardIdentity(name, true);
    cacheState({ signedIn, customer, name });
  })).catch((error) => {
    nav.removeAttribute('data-auth-pending');
    console.error('CoolTime auth navigation initialization failed:', error);
  });
}
