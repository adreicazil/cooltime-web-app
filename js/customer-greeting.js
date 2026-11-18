import { getAuthenticatedProfile } from './auth-guard.js';

const greetingContainer = document.getElementById('customerSharedGreeting');
const greeting = document.getElementById('customer-greeting-text');

function displayCustomerGreeting(profile = {}) {
  if (!greeting) return;
  const fullName = profile.name
    || [profile.firstName, profile.lastName].filter(Boolean).join(' ')
    || profile.username
    || 'customer';
  greeting.textContent = `Hello ${fullName}`;
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
