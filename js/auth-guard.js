import { onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { auth, authReady } from './firebase-config.js';
import { getUserProfile } from './firestore.js';

export const ROLE_REDIRECTS = Object.freeze({
  customer: 'customer-dashboard.html',
  admin: 'admin-dashboard.html',
  super_admin: 'super-dashboard.html'
});

export function normalizeRole(role) {
  return String(role || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
}

export function hasOtpVerification(user) {
  return Boolean(user && sessionStorage.getItem('cooltimeOtpVerifiedUid') === user.uid);
}

function firebaseUserOnce() {
  return new Promise((resolve) => {
    const unsubscribe = onAuthStateChanged(auth, (user) => {
      unsubscribe();
      resolve(user);
    });
  });
}

export async function getAuthenticatedUser() {
  await authReady;
  return firebaseUserOnce();
}

export async function getAuthenticatedProfile() {
  await authReady;
  const user = await firebaseUserOnce();
  if (!user) return { user: null, profile: null, role: '' };
  if (!hasOtpVerification(user)) return { user, profile: null, role: '' };
  const profile = await getUserProfile(user.uid);
  return { user, profile, role: normalizeRole(profile?.role) };
}

export async function requireRole(allowedRoles, redirectUrl = 'login.html') {
  const result = await getAuthenticatedProfile();
  if (result.user && !hasOtpVerification(result.user)) {
    window.location.replace('otp.html');
    return null;
  }
  if (!result.user || !result.profile || String(result.profile.status || 'active').toLowerCase() !== 'active' || !allowedRoles.includes(result.role)) {
    window.location.replace(redirectUrl);
    return null;
  }
  return result;
}
