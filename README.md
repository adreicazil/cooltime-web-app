Vanilla HTML, CSS, and JavaScript frontend prepared for Firebase Authentication and Firestore integration.


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

Firebase is configured with the CDN modular SDK in:

- `js/firebase-config.js`
- `js/auth.js`
- `js/firestore.js`
- `js/firebase-service.js`

The app uses ES module imports directly from the Firebase CDN and does not require npm, Vite, Webpack, or `node_modules`.

Before testing privileged dashboards or catalog initialization, complete the
one-time account and catalog setup in [FIREBASE_SETUP.md](FIREBASE_SETUP.md).

Firebase Authentication is the primary session authority. After a successful email/password login, the app reads only the authenticated user's canonical `users/{uid}` profile and routes by its Firestore role:

- `admin` → Admin dashboard
- `super_admin` → Super Admin dashboard
- `customer` → protected booking page

The configured Firebase provider is Email/Password. Customer profiles still retain their existing `username` field, but it cannot safely be used to find an account before sign-in: the published Firestore rules allow a user to read only their own profile. The unified form therefore uses the registered email address and password, without adding an insecure username lookup or a duplicate account system.

The **Forgot Password** page calls Firebase's `sendPasswordResetEmail()` API. Enable the Email/Password provider and ensure the deployed site domain is listed in Firebase Authentication's authorized domains. This flow does not require SMTP, PHP sessions, a Firebase service account, or an application-managed OTP.

The legacy PHP OTP endpoints remain in the repository for compatibility with any separately deployed server process, but no browser page calls them for login or password reset. Do not configure client-side code with SMTP or service-account credentials.

Customer registration first creates the Firebase Authentication account, then writes `users/{uid}`. If the second step says **Missing or insufficient permissions**, publish the `users` rule from [firestore.rules](firestore.rules) in Firebase Console: **Firestore Database → Rules → paste/merge the `match /users/{userId}` block → Publish**. Do not use a public `allow read, write: if true` rule.

If the error already happened once, the Firebase Authentication account may already exist but be missing its Firestore profile. Publish the rule, then submit the registration form again with the same email/password; the app will complete the missing profile. If it reports that an account already exists, use the unified login page instead.


The app uses the configured Firebase project in `js/firebase-config.js`; it does not create a second Firebase project or duplicate collections. Publish [firestore.rules](firestore.rules) before testing writes.

- `users/{uid}` — Firebase UID, username/name, email, role, contact, status, created/updated timestamps. Registration creates the authenticated customer profile automatically; passwords remain in Firebase Authentication only.
- `branch/{cabuyao|santa_rosa}` — name, address, capacity (4 or 3), status, timestamps. Open `firestore-initializer/index.html` while signed in as an Admin/Super Admin to create only missing branch/service catalog records; it never overwrites existing records. The app still reads legacy `branches` documents for compatibility.
- `services/{serviceId}` — name, description, price, duration, status, timestamps.
- `vehicles/{vehicleId}` — customerId (Firebase UID), plate number, make, model, year, timestamps.
- `bookings/{bookingId}` — customerId, vehicleId, branchId, serviceId, selectedServices, date, timeSlot, status, timestamps.
- `scheduleSlots/{slotId}` — branchId, date, timeSlot, capacity, bookedCount, available, timestamps.
- `invoices/{invoiceId}` — bookingId, customerId, servicesAdded, runningTotal, finalAmount, status, timestamps.
- `payments/{paymentId}` — invoiceId, bookingId, customerId, amount, method, status, referenceNumber, paidAt.
- `serviceHistory/{historyId}` — booking/customer/vehicle IDs, completed services, parts, optional technician name, total amount, completedAt.
- `notifications/{notificationId}` — userId, optional email, type, message, status, createdAt.
- `otp/{hash}` — server-only audit record with userId, email, `codeHash`, purpose, expiration, used flag, and timestamp. No client can read or write it.
- `reports/{reportId}` — type, period, generated timestamp, and a compact summary. Reports should use bookings/payments/invoices/history as their source rather than copy all transactional data.

`createBooking()` runs one Firestore transaction. It checks the branch capacity, increments exactly one slot, and creates the vehicle, booking, open invoice, and notification together. Firestore retries concurrent transactions; the check rejects a fifth Cabuyao booking or fourth Santa Rosa booking in the same slot. `cancelBooking()` performs the matching transactional slot decrement and booking cancellation.

Administrative helpers in `js/firestore.js` provide the persistence operations for adding invoice service lines, recording payments, completing a service, and saving report metadata. They are intentionally not connected to the static dashboard placeholder controls, so dashboard UI and behavior are preserved.


Because the project uses ES modules, run it through a local server:

```powershell
$env:FIREBASE_API_KEY = 'your_firebase_web_api_key'
$env:FIREBASE_SERVICE_ACCOUNT_FILE = 'C:\secure\cooltime-firebase-service-account.json'
$env:SMTP_HOST = 'smtp.gmail.com'
$env:SMTP_PORT = '587'
$env:SMTP_ENCRYPTION = 'tls'
$env:SMTP_USERNAME = 'your-gmail-address@gmail.com'
$env:SMTP_PASSWORD = 'your-gmail-app-password'
$env:SMTP_FROM_EMAIL = 'your-gmail-address@gmail.com'
$env:SMTP_FROM_NAME = 'Auto Aircon Service System'
powershell -ExecutionPolicy Bypass -File scripts/dev-server.ps1
```

Alternatively, copy `.env.example` to `.env`, supply the real values, and keep `.env` out of source control. The PHP configuration loads that local file when process-level environment variables are not already set.

The development server defaults to `http://localhost:8000/`. It warns at startup when `.env` is missing; that warning must be resolved before OTP email can be delivered.

Then open `http://localhost:8000/`. The local server uses PHP because the OTP endpoints are PHP files.

To test, open `login.html` and sign in with a Firebase account that has the matching canonical `users/{uid}` Firestore role (`super_admin`, `admin`, or `customer`).

To test password reset, open `forgot-password.html`, enter the registered email, and use the Firebase password-reset link delivered by email. The browser response remains generic so it does not reveal whether an account exists.
