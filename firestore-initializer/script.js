import { seedCoreCatalog } from '../js/firestore.js';

const initializeBtn = document.getElementById('initializeBtn');
const statusMessage = document.getElementById('statusMessage');
const logList = document.getElementById('logList');

function setStatus(message, type = 'idle') {
  statusMessage.textContent = message;
  statusMessage.className = `status-message ${type}`;
}

function addLog(message) {
  const item = document.createElement('li');
  item.textContent = message;
  logList.appendChild(item);
}

initializeBtn.addEventListener('click', async () => {
  initializeBtn.disabled = true;
  logList.innerHTML = '';
  setStatus('Creating any missing branches and services…', 'idle');
  try {
    const updatedBranches = await seedCoreCatalog();
    addLog(`Core branches and services are ready. Applied or confirmed the 08:00–17:00 daily schedule for ${updatedBranches} branch record(s).`);
    setStatus('Core Firestore catalog and branch schedules are ready.', 'success');
  } catch (error) {
    console.error(error);
    addLog('Sign in as an Admin or Super Admin first, then verify the published Firestore rules.');
    setStatus(`Unable to initialize catalog: ${error.message}`, 'error');
  } finally {
    initializeBtn.disabled = false;
  }
});
