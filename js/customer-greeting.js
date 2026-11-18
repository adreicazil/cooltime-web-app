import { getAuthenticatedProfile } from './auth-guard.js';

const greetingContainer = document.getElementById('customerSharedGreeting');
const greeting = document.getElementById('customer-greeting-text');
const sidebarName = document.getElementById('sidebar-customer-name');

function displayCustomerGreeting(profile = {}) {
  const fullName = profile.fullName || profile.name || profile.displayName
    || [profile.firstName, profile.lastName].filter(Boolean).join(' ')
    || profile.username
    || window.firebaseUser?.displayName
    || window.firebaseUser?.email?.split('@')[0]
    || 'customer';
  const dashboardLabel = `${fullName}${/[sS]$/.test(fullName) ? "'" : "'s"} Dashboard`;
  if (greeting) greeting.textContent = dashboardLabel;
  if (sidebarName) sidebarName.textContent = fullName;
  document.title = `${dashboardLabel} | CoolTime`;
  greetingContainer?.removeAttribute('hidden');
}

window.addEventListener('customer:profile', (event) => displayCustomerGreeting(event.detail));

try {
  const { profile, role } = await getAuthenticatedProfile();
  if (role === 'customer' && profile && String(profile.status || 'active').toLowerCase() === 'active') {
    displayCustomerGreeting(profile);
  }
} catch (error) {
  console.warn('Customer greeting could not be loaded.', error);
}
