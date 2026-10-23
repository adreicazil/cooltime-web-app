# CoolTime Firebase one-time setup

This project deliberately does not create privileged accounts from browser code.
Complete these steps in the existing `capstoneproject-a068f` Firebase project.

1. In **Authentication → Users**, create the Super Admin and Admin email/password accounts. Firebase generates a UID for each account.
2. In **Firestore Database → Data**, create matching documents at `users/{UID}` using each Firebase Authentication UID as the document ID. Use this minimum shape:

   ```text
   uid: the exact Firebase UID
   email: the matching Firebase Auth email
   username: administrator name
   name: administrator name
   role: super_admin       # Super Admin account
   status: active
   createdAt: timestamp
   updatedAt: timestamp
   ```

   Use `role: admin` for the Admin account. The canonical Super Admin role in
   this codebase is `super_admin` (with an underscore), not `superadmin`.
   The server account configured by `FIREBASE_CREDENTIALS` needs Firestore
   read/write access. It checks occupied slots and creates or revokes the
   short-lived `mfa_sessions/{UID}` record after email OTP verification.
3. Publish the repository's `firestore.rules` in **Firestore Database → Rules**.
4. Sign in as either privileged account, open `firestore-initializer/index.html`,
   and select **Initialize Firestore Database**. It creates missing records at
   `branches/cabuyao`, `branches/santarosa`, and `services/*`, and fills missing
   branch schedule settings (`operatingHours`, `slotDurationMinutes`, timezone,
   and capacity). It applies the business schedule of 08:00–17:00 every day,
   including holidays, and removes the old seeded lunch break. Valid slot
   durations are preserved; missing values default to 90 minutes. Generated
   appointments include 5:00 PM as the final start time.
5. In **Authentication → Sign-in method**, enable **Email/Password**. Add your
   deployed domain and `localhost` to **Authentication → Settings → Authorized
   domains** so Firebase password-reset emails work.

Never put a Firebase service-account JSON file, administrator password, or Gmail
OAuth credentials in frontend JavaScript, HTML, or Firestore documents.

## Operational workflow setup

Publish `firestore.rules` after reviewing the admin-only collections used by these workflows: `feedback`, `inquiries`, `reports`, `activity_logs`, and `system_settings`. The rules require an unexpired server-written OTP session for signed-in Firestore access. Configure `FIREBASE_API_KEY`, `FIREBASE_CREDENTIALS`, and the Gmail OAuth values in the server `.env`; the Gmail REST API sends the six-digit login code, and the Firebase service account manages the OTP session record. The login code expires after five minutes, allows five verification attempts, and can be requested at most three times per 15-minute window with a 60-second resend cooldown. Verified sessions expire after 12 hours and are revoked on logout or when a new login starts.

PayMongo documents `payment.paid`, `payment.failed`, and `checkout_session.payment.paid` events for this flow. In webhook settings, point to `api/paymongo-webhook.php` and subscribe to those events. Hosted Checkout sessions remain active until explicitly expired, so the signed `cancel_url` routes to `api/payment-return.php` to record a customer return without confirmed payment. `api/expire-stale-checkouts.php` also expires sessions after `PAYMONGO_CHECKOUT_TIMEOUT_MINUTES` (default 30) when called by a trusted scheduler. Set a long random `PAYMONGO_SWEEP_TOKEN` in the server `.env` and schedule a POST to that endpoint every 5–10 minutes with `Authorization: Bearer <token>`. The webhook remains authoritative for successful payment.

Admin workflows are available from `admin-tools.html`. Customer feedback and reports can be submitted without logging in at `feedback.html`; attachments are stored outside the web root (or in FEEDBACK_STORAGE_DIR) and retrieved through the admin-authenticated attachment endpoint.
