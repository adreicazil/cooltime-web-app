// Firebase CDN modular SDK imports.
// These URLs let the project run from a normal local web server without npm.
import { initializeApp, getApp, getApps } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-app.js';
import { getAnalytics, isSupported } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-analytics.js';
import { browserLocalPersistence, getAuth, setPersistence } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-auth.js';
import { getFirestore } from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';

// Firebase project settings from the Firebase console.
export const firebaseConfig = {
  apiKey: 'AIzaSyBGWqb9b6ErVNo_xKHCzjZi0bKLgU7Ghlw',
  authDomain: 'capstoneproject-a068f.firebaseapp.com',
  projectId: 'capstoneproject-a068f',
  storageBucket: 'capstoneproject-a068f.firebasestorage.app',
  messagingSenderId: '720278876587',
  appId: '1:720278876587:web:12f73aa10afe11a4d6e2ee',
  measurementId: 'G-XXGCK0S1LY'
};

// Initialize only CoolTime's named app. Do not accidentally reuse an unrelated
// Firebase default app that another script may have placed on the page.
const APP_NAME = 'cooltime';
export const app = getApps().some((existingApp) => existingApp.name === APP_NAME)
  ? getApp(APP_NAME)
  : initializeApp(firebaseConfig, APP_NAME);

// Shared Firebase services used by the rest of the project.
export const auth = getAuth(app);
export const db = getFirestore(app);

// Firebase's persisted authenticated user is the only client-side session source.
export const authReady = setPersistence(auth, browserLocalPersistence);

// Analytics is optional and only starts in browsers where Firebase supports it.
export const analytics = isSupported().then((supported) => {
  return supported ? getAnalytics(app) : null;
});
