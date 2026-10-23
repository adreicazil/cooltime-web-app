import { getAuthenticatedProfile, hasOtpVerification, ROLE_REDIRECTS } from './auth-guard.js';

getAuthenticatedProfile().then(({ user, role }) => {
  window.location.replace(user && !hasOtpVerification(user) ? 'otp.html' : (ROLE_REDIRECTS[role] || 'login.html'));
}).catch((error) => {
  console.error('CoolTime dashboard redirect error:', error);
  window.location.replace('login.html');
});
