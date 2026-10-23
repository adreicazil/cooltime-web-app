// Compatibility exports for older imports.
// New files should import directly from firebase-config.js.

import { app, auth, db, firebaseConfig } from './firebase-config.js';

export function getFirebaseConfig() {
  return firebaseConfig;
}

export { app, auth, db };
