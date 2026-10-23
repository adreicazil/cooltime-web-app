import {
  createUserWithEmailAndPassword,
  sendPasswordResetEmail,
  signInWithEmailAndPassword,
  signOut
} from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { auth, authReady } from './firebase-config.js';
import { createUserProfile } from './firestore.js';

const loginForm = document.getElementById('loginForm');
const registerForm = document.getElementById('registerForm');
const forgotPasswordForm = document.getElementById('forgotPasswordForm');

function showMessage(message) {
  alert(message);
}

function getInputValue(form, selector) {
  return form.querySelector(selector)?.value.trim() || '';
}

function getPasswordValue(form, selector) {
  return form.querySelector(selector)?.value || '';
}

function authMessage(error, action) {
  const messages = {
    'auth/email-already-in-use': 'An account already exists for that email address.',
    'auth/invalid-email': 'Enter a valid email address.',
    'auth/invalid-credential': 'The email address or password is incorrect.',
    'auth/user-not-found': 'The email address or password is incorrect.',
    'auth/wrong-password': 'The email address or password is incorrect.',
    'auth/weak-password': 'Use a password with at least 6 characters.',
    'auth/too-many-requests': 'Too many attempts. Please wait a moment and try again.',
    'auth/network-request-failed': 'Network error. Check your connection and try again.',
    'permission-denied': 'Your account could not access its profile. Please contact an administrator.',
    'unavailable': 'The database is temporarily unavailable. Please try again.'
  };
  console.error(`CoolTime ${action} error:`, error);
  return messages[error?.code] || `We could not ${action}. Please try again.`;
}

if (loginForm) {
  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const loginButton = loginForm.querySelector('[type="submit"]');
    if (loginButton) loginButton.disabled = true;
    const email = getInputValue(loginForm, '#email');
    const password = getPasswordValue(loginForm, '#password');
    try {
      await authReady;
      const credential = await signInWithEmailAndPassword(auth, email, password);
      sessionStorage.removeItem('cooltimeOtpVerifiedUid');
      const response = await fetch('api/send-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await credential.user.getIdToken(true)}` },
        body: JSON.stringify({})
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'A verification code could not be sent.');
      sessionStorage.setItem('cooltimeOtpPendingEmail', result.email || credential.user.email || '');
      window.location.replace('otp.html');
    } catch (error) {
      if (auth.currentUser) await signOut(auth);
      if (String(error?.message || '').toLowerCase().includes('could not send a verification email')) {
        showMessage('Your account and password are okay, but we could not send the sign-in verification code. Please try logging in again later. Email verification is required to protect your account.');
      } else {
        showMessage(error.message && !error.code ? error.message : authMessage(error, 'log in'));
      }
    } finally {
      if (loginButton) loginButton.disabled = false;
    }
  });
}

if (registerForm) {
  registerForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const username = getInputValue(registerForm, '#username');
    const email = getInputValue(registerForm, '#email');
    const password = getPasswordValue(registerForm, '#password');
    const security = window.SecurityValidator;
    if (security?.isDisposableEmail(email)) {
      showMessage('Please use a permanent email address to create your account.');
      return;
    }
    if (security?.containsInappropriateContent(username)) {
      showMessage('Please choose a username without inappropriate language.');
      return;
    }
    const registerButton = registerForm.querySelector('[type="submit"]');
    if (registerButton) registerButton.disabled = true;
    try {
      await authReady;
      const credential = await createUserWithEmailAndPassword(auth, email, password);
      await createUserProfile(credential.user.uid, { username, email });
      await signOut(auth);
      showMessage('Customer account created successfully. Please log in.');
      window.location.replace('login.html');
    } catch (error) {
      if (error?.code === 'auth/email-already-in-use') {
        try {
          const credential = await signInWithEmailAndPassword(auth, email, password);
          const existingProfile = await getUserProfile(credential.user.uid);
          if (!existingProfile) {
            await createUserProfile(credential.user.uid, { username, email });
            await signOut(auth);
            showMessage('Your customer profile has been completed. Please log in.');
            window.location.replace('login.html');
            return;
          }
          await signOut(auth);
        } catch (recoveryError) {
          console.error('CoolTime profile-recovery error:', recoveryError);
        }
      }
      if (auth.currentUser) {
        // Authentication succeeded but the profile write did not. Do not report
        // a completed registration or leave an implicit logged-in session.
        await signOut(auth);
        console.error('CoolTime customer profile creation error:', error);
        showMessage('Your account was created, but its customer profile could not be saved. Please try again after the Firestore rules are published, or contact support.');
        return;
      }
      showMessage(authMessage(error, 'create your account'));
    } finally {
      if (registerButton) registerButton.disabled = false;
    }
  });
}

if (forgotPasswordForm) {
  forgotPasswordForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const email = getInputValue(forgotPasswordForm, '#email');
    try {
      await authReady;
      await sendPasswordResetEmail(auth, email);
    } catch (error) {
      // Keep the response generic so account existence is not exposed.
      console.error('CoolTime password-reset error:', error);
    }
    showMessage('If an account matches that email address, a password-reset link has been sent.');
    window.location.replace('login.html');
  });
}

export async function logoutUser(redirectUrl = 'login.html') {
  const user = auth.currentUser;
  if (user) {
    try {
      await fetch('api/revoke-otp-session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await user.getIdToken()}` },
        body: JSON.stringify({})
      });
    } catch (error) {
      console.error('CoolTime secure session revocation failed:', error);
    }
  }
  sessionStorage.removeItem('cooltimeOtpVerifiedUid');
  sessionStorage.removeItem('cooltimeOtpPendingEmail');
  await signOut(auth);
  window.location.replace(redirectUrl);
}
