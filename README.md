# CoolTime Auto Aircon Capstone Frontend

Vanilla HTML, CSS, and JavaScript frontend prepared for Firebase Authentication and Firestore integration.

## Pages

- `index.html` - Home
- `about.html` - About Us
- `services.html` - Services
- `contact.html` - Contact Us
- `book-now.html` - Booking form placeholder
- `faq.html` - FAQ and CoolBot layout
- `login.html` - Unified login for Super Admin, Admin, and Customer accounts
- `super-admin.html` - Legacy URL that forwards to the unified login
- `customer-login.html` - Legacy URL that forwards to the unified login
- `otp.html` - Email OTP verification screen
- `register.html` - Registration placeholder
- `forgot-password.html` - Password-reset code request
- `reset-password.html` - Password-reset code verification and new password form
- `admin-dashboard.html` - Admin dashboard
- `super-dashboard.html` - Master dashboard
- `dashboard.html` - Redirects to the admin dashboard

## Project Structure

```text
project/
├── index.html
├── login.html
├── register.html
├── forgot-password.html
├── dashboard.html
├── css/
├── js/
├── assets/
└── firebase/
```

## Firebase Status

Firebase is configured with the CDN modular SDK in:

- `js/firebase-config.js`
- `js/auth.js`
- `js/firestore.js`
- `js/firebase-service.js`

The app uses ES module imports directly from the Firebase CDN and does not require npm, Vite, Webpack, or `node_modules`.

Before testing privileged dashboards or catalog initialization, complete the
one-time account and catalog setup in [FIREBASE_SETUP.md](FIREBASE_SETUP.md).

## Authentication and password reset setup

Firebase Authentication is the primary session authority. After a successful email/password login, the app reads only the authenticated user's canonical `users/{uid}` profile and routes by its Firestore role:

- `admin` → Admin dashboard
- `super_admin` → Super Admin dashboard
- `customer` → protected booking page

The configured Firebase provider is Email/Password. Customer profiles still retain their existing `username` field, but it cannot safely be used to find an account before sign-in: the published Firestore rules allow a user to read only their own profile. The unified form therefore uses the registered email address and password, without adding an insecure username lookup or a duplicate account system.

The **Forgot Password** page calls Firebase's `sendPasswordResetEmail()` API. Enable the Email/Password provider and ensure the deployed site domain is listed in Firebase Authentication's authorized domains. This flow does not require the PHP Gmail sender, PHP sessions, a Firebase service account, or an application-managed OTP.

The legacy PHP OTP endpoints remain in the repository for compatibility with any separately deployed server process, but no browser page calls them for login or password reset. Do not configure client-side code with Gmail OAuth or service-account credentials.

## Firestore rule required for registration

Customer registration first creates the Firebase Authentication account, then writes `users/{uid}`. If the second step says **Missing or insufficient permissions**, publish the `users` rule from [firestore.rules](firestore.rules) in Firebase Console: **Firestore Database → Rules → paste/merge the `match /users/{userId}` block → Publish**. Do not use a public `allow read, write: if true` rule.

If the error already happened once, the Firebase Authentication account may already exist but be missing its Firestore profile. Publish the rule, then submit the registration form again with the same email/password; the app will complete the missing profile. If it reports that an account already exists, use the unified login page instead.

## Firestore data model

The app uses the configured Firebase project in `js/firebase-config.js`; it does not create a second Firebase project or duplicate collections. Publish [firestore.rules](firestore.rules) before testing writes.

- `users/{uid}` — Firebase UID, username/name, email, role, contact, status, created/updated timestamps. Registration creates the authenticated customer profile automatically; passwords remain in Firebase Authentication only.
- `branches/{cabuyao|santarosa}` — name, address, capacity, status, `operatingHours`, `operatingDays`, `holidaysOpen`, `slotDurationMinutes`, timezone, and timestamps. The booking form also reads legacy `branch/{cabuyao|santa_rosa}` documents. Run `firestore-initializer/index.html` as an Admin/Super Admin to apply the 08:00–17:00 every-day schedule.
- `services/{serviceId}` — name, description, price, duration, status, timestamps.
- `vehicles/{vehicleId}` — customerId (Firebase UID), plate number, make, model, year, timestamps.
- `bookings/{bookingId}` — customerId, vehicleId, branchId, serviceId, selectedServices, date (`YYYY-MM-DD`), timeSlot, status, timestamps.
- `scheduleSlots/{slotId}` — branchId, date, timeSlot, capacity, bookedCount, available, timestamps.
- `invoices/{invoiceId}` — shared Web/POS record with invoiceId, customerId, branchId (`cabuyao` or `santa_rosa`), source, servicesAdded, serviceDetails, runningTotal, finalAmount/totalAmount in pesos, status, timestamps, and optional paymentId.
- `payments/{paymentId}` — invoiceId, customerId, branchId, amount, currency, amountUnit, method, status, referenceNumber, and paidAt.
- `serviceHistory/{historyId}` — booking/customer/vehicle IDs, completed services, parts, optional technician name, total amount, completedAt.
- `notifications/{notificationId}` — recipientRole, customer userId when applicable, event type, message, booking/payment detail fields, and createdAt.
- `otp/{hash}` — server-only audit record with userId, email, `codeHash`, purpose, expiration, used flag, and timestamp. No client can read or write it.
- `reports/{reportId}` — type, period, generated timestamp, and a compact summary. Reports should use bookings/payments/invoices/history as their source rather than copy all transactional data.

Web booking saves the booking and vehicle, then calls `api/create-web-invoice.php`. The endpoint verifies the Firebase ID token, reads the saved booking and service price from Firestore, and writes a retry-safe `web_{bookingId}` pending invoice. When PayMongo is configured, the browser sends that invoice ID to `api/create-checkout-session.php` and opens the resulting checkout session. Set `PAYMONGO_RETURN_URL` to the deployed HTTPS customer dashboard URL in the server `.env`.

Customer availability uses `api/slot-availability.php`: it verifies the Firebase ID token, queries active bookings server-side using the Firebase service account, and returns only counts by time slot. Customer browsers do not query or receive other customers' booking documents. The booking form refreshes this aggregate every 15 seconds while a branch/date is selected.

For payment confirmation, register `https://<your-domain>/api/paymongo-webhook.php` in PayMongo's webhook settings, subscribe to `checkout_session.payment.paid` and `payment.paid`, and store that endpoint's signing secret as `PAYMONGO_WEBHOOK_SECRET` in the server `.env`. The handler verifies PayMongo's signature over the raw request before atomically marking the invoice and payment record paid. PayMongo describes the signature format and checkout event fields in its [webhook security guide](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management) and [hosted checkout guide](https://docs.paymongo.com/docs/payment-channels-hosted-checkout).

The admin POS page at `pos.html` uses the Firestore service catalog, keeps a live cart total, and writes the same invoice schema. Cash checkout atomically creates a paid invoice and payment record; PayMongo checkout creates a pending invoice and receipt with a checkout link. Both branches use `cabuyao` and `santa_rosa` invoice IDs. Firestore rules restrict POS invoice/payment writes to administrators; the Web invoice API uses the server service account.

Administrative helpers in `js/firestore.js` provide the persistence operations for adding invoice service lines, recording payments, completing a service, and saving report metadata. They are intentionally not connected to the static dashboard placeholder controls, so dashboard UI and behavior are preserved.

## Run Locally

Because the project uses ES modules, run it through a local server:

```powershell
$env:FIREBASE_API_KEY = 'your_firebase_web_api_key'
$env:FIREBASE_SERVICE_ACCOUNT_FILE = 'C:\secure\cooltime-firebase-service-account.json'
$env:GMAIL_CLIENT_ID = 'your_google_oauth_client_id'
$env:GMAIL_CLIENT_SECRET = 'your_google_oauth_client_secret'
$env:GMAIL_REFRESH_TOKEN = 'your_google_oauth_refresh_token'
$env:SYSTEM_EMAIL_ADDRESS = 'cooltimeautoaircon2@gmail.com'
powershell -ExecutionPolicy Bypass -File scripts/dev-server.ps1
```

Alternatively, copy `.env.example` to `.env`, supply the real values, and keep `.env` out of source control. The PHP configuration loads that local file when process-level environment variables are not already set.

The development server defaults to `http://localhost:8000/`. It warns at startup when `.env` is missing; that warning must be resolved before OTP email can be delivered.

Then open `http://localhost:8000/`. The local server uses PHP because the OTP endpoints are PHP files.

To test, open `login.html` and sign in with a Firebase account that has the matching canonical `users/{uid}` Firestore role (`super_admin`, `admin`, or `customer`).

To test password reset, open `forgot-password.html`, enter the registered email, and use the Firebase password-reset link delivered by email. The browser response remains generic so it does not reveal whether an account exists.
## Central notification dispatcher

This project runs on PHP endpoints and dispatches mail through the existing Gmail API OAuth mailer (`api/gmail-mailer.php`); it does not have a Node/Nodemailer server. Set `SYSTEM_EMAIL_ADDRESS=cooltimeautoaircon2@gmail.com` in the server's `.env` and authorize that address as the OAuth sender (or as a Gmail send-as alias). Keep Gmail OAuth credentials on the server only.

Booking submission, cancellation, reschedule, and payment-overdue events write notification records to Firestore and dispatch email from the configured system sender. Customer notifications are scoped to the authenticated customer's UID. Admin and Super Admin receive system copies at the system address.

Appointment reminders are handled by `POST /api/notification-scheduler.php`. Set a long random `NOTIFICATION_CRON_TOKEN` in `.env` and invoke this endpoint every five minutes with `Authorization: Bearer <token>`. The job sends next-day reminders and same-day reminders during the final 30 minutes before an appointment. Do not expose this token in frontend code.

