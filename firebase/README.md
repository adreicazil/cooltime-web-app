This project uses the Firebase CDN modular SDK with vanilla HTML, CSS, and JavaScript.

The shared Firebase setup lives in:

- `../js/firebase-config.js`
- `../js/auth.js`
- `../js/firestore.js`

No npm, Node.js, Vite, Webpack, or `node_modules` folder is required.


- Firebase App
- Firebase Analytics
- Firebase Authentication
- Cloud Firestore


Run the project from a local web server so ES module imports work correctly:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev-server.ps1
```

Then open:

```text
http://localhost:8000/
```
