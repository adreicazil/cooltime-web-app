# Auto Aircon Firestore Initializer

This page uses the project's existing Firebase configuration to seed only missing branch and service catalog documents.

It reuses the project's Firebase modular CDN setup. It does not use Node.js, npm, Express, React, Vue, Angular, Vite, Webpack, or any framework.

## Files

- `index.html` - Page with the Initialize Firestore Database button
- `style.css` - Page styling
- `script.js` - Firestore seed logic
- `firebase-config.js` - Firebase app and Firestore setup
- `README.md` - Setup notes

## Setup

1. Start the project PHP server and sign in as an Admin or Super Admin.
2. Open `firestore-initializer/index.html` in that same browser.
3. Click `Initialize Firestore Database`.

## Collections Created

- `branches` (canonical collection; the booking form can also read legacy `branch` documents)
- `services`

The live application creates `users`, `vehicles`, `bookings`, `invoices`,
`notifications`, and `scheduleSlots` as users act. Administrative processes
create `payments`, `serviceHistory`, and `reports`.

## Duplicate Prevention

The initializer checks existing fixed IDs and never overwrites service data or unrelated branch fields. For both branch documents it enforces the business schedule of 08:00–17:00, Monday through Sunday, including holidays. It also removes the prior seeded lunch break. Existing valid slot durations are preserved; missing or invalid values default to 90 minutes. The closing time is an inclusive final appointment start, so generated slots can include 5:00 PM.

## Firestore Rules During Testing

Published Firestore rules restrict seeding to Admin/Super Admin accounts. Do not use permissive development rules.
