This page uses the project's existing Firebase configuration to seed only missing branch and service catalog documents.

It reuses the project's Firebase modular CDN setup. It does not use Node.js, npm, Express, React, Vue, Angular, Vite, Webpack, or any framework.


- `index.html` - Page with the Initialize Firestore Database button
- `style.css` - Page styling
- `script.js` - Firestore seed logic
- `firebase-config.js` - Firebase app and Firestore setup
- `README.md` - Setup notes


1. Start the project PHP server and sign in as an Admin or Super Admin.
2. Open `firestore-initializer/index.html` in that same browser.
3. Click `Initialize Firestore Database`.


- `branch` (the existing live collection name)
- `services`

The live application creates `users`, `vehicles`, `bookings`, `invoices`,
`notifications`, and `scheduleSlots` as users act. Administrative processes
create `payments`, `serviceHistory`, and `reports`.


The initializer checks existing fixed IDs and never overwrites branches or services.


Published Firestore rules restrict seeding to Admin/Super Admin accounts. Do not use permissive development rules.
