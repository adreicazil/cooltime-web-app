import { signOut } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { auth, authReady } from './firebase-config.js';
import { ROLE_REDIRECTS, normalizeRole } from './auth-guard.js';
import { getUserProfile } from './firestore.js';

const form = document.getElementById('otpForm');
const codeInput = document.getElementById('otpCode');
const message = document.getElementById('otpMessage');
const verifyButton = document.getElementById('verifyOtpBtn');
const resendButton = document.getElementById('resendOtpBtn');
const intro = document.getElementById('otpIntro');
let currentUser = null;

function setMessage(text, isError = false) {
  message.textContent = text;
  message.classList.toggle('error', isError);
}

async function postOtp(endpoint, payload = {}) {
  const response = await fetch(`api/${endpoint}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await currentUser.getIdToken()}` },
    body: JSON.stringify(payload)
  });
  const result = await response.json();
  if (!response.ok || !result.ok) throw new Error(result.message || 'Email verification could not be completed.');
  return result;
}

async function sendCode() {
  if (!currentUser) return;
  resendButton.disabled = true;
  setMessage('Sending a verification code…');
  try {
    const result = await postOtp('send-otp.php');
    intro.textContent = `Enter the 6-digit code sent to ${result.email || sessionStorage.getItem('cooltimeOtpPendingEmail') || 'your registered email address'}.`;
    codeInput.value = '';
    setMessage('The code expires in 5 minutes.');
  } catch (error) {
    setMessage(error.message, true);
  } finally {
    resendButton.disabled = false;
  }
}

await authReady;
currentUser = auth.currentUser;
if (!currentUser) {
  window.location.replace('login.html');
} else {
  intro.textContent = `Enter the 6-digit code sent to ${sessionStorage.getItem('cooltimeOtpPendingEmail') || currentUser.email || 'your registered email address'}.`;
  codeInput.focus();
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!currentUser) return;
  verifyButton.disabled = true;
  resendButton.disabled = true;
  setMessage('Verifying your code…');
  try {
    await postOtp('verify-otp.php', { code: codeInput.value.trim() });
    const profile = await getUserProfile(currentUser.uid);
    const role = normalizeRole(profile?.role);
    if (String(profile?.status || 'active').toLowerCase() !== 'active' || !ROLE_REDIRECTS[role]) {
      try {
        await fetch('api/revoke-otp-session.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await currentUser.getIdToken()}` }, body: JSON.stringify({})
        });
      } catch (revokeError) { console.error('CoolTime OTP session revocation failed:', revokeError); }
      await signOut(auth);
      sessionStorage.removeItem('cooltimeOtpPendingEmail');
      throw new Error('This account is not permitted to sign in. Contact an administrator for help.');
    }
    sessionStorage.setItem('cooltimeOtpVerifiedUid', currentUser.uid);
    sessionStorage.removeItem('cooltimeOtpPendingEmail');
    window.location.replace(ROLE_REDIRECTS[role]);
  } catch (error) {
    setMessage(error.message || 'The code could not be verified. Please try again.', true);
    verifyButton.disabled = false;
    resendButton.disabled = false;
    codeInput.select();
  }
});

resendButton.addEventListener('click', sendCode);
document.getElementById('backToLogin').addEventListener('click', async (event) => {
  event.preventDefault();
  await signOut(auth);
  sessionStorage.removeItem('cooltimeOtpPendingEmail');
  sessionStorage.removeItem('cooltimeOtpVerifiedUid');
  window.location.replace('login.html');
});
