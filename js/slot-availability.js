import { auth, authReady } from './firebase-config.js';

const STANDARD_SLOTS = [
  '09:00 AM - 11:00 AM',
  '11:00 AM - 01:00 PM',
  '01:00 PM - 03:00 PM',
  '03:00 PM - 05:00 PM',
];
const runBySelect = new WeakMap();
const refreshBySelect = new WeakMap();
const boundSelects = new WeakSet();

function normalizeBranch(value) {
  const branch = String(value || '').toLowerCase().replace(/[^a-z]/g, '');
  if (branch.includes('santarosa')) return 'santarosa';
  if (branch.includes('cabuyao')) return 'cabuyao';
  return '';
}

function manilaDateTime() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
  return {
    date: `${values.year}-${values.month}-${values.day}`,
    minutes: Number(values.hour) * 60 + Number(values.minute),
  };
}

function slotEndMinutes(slot) {
  const end = slot.split(' - ')[1] || '';
  const match = /^(\d{1,2}):(\d{2})\s*(AM|PM)$/i.exec(end.trim());
  if (!match) return Number.POSITIVE_INFINITY;
  const hour = Number(match[1]) % 12;
  return (hour + (match[3].toUpperCase() === 'PM' ? 12 : 0)) * 60 + Number(match[2]);
}

function syncSubmitButton(select) {
  const submit = select.form?.querySelector('[type="submit"]');
  if (submit) submit.disabled = !select.value || select.selectedOptions[0]?.disabled === true;
}

export async function updateAvailableSlots(dateInput, branchInput, slotSelectElement, { requireAuthentication = false } = {}) {
  if (!dateInput || !branchInput || !slotSelectElement) return;
  const run = (runBySelect.get(slotSelectElement) || 0) + 1;
  runBySelect.set(slotSelectElement, run);
  clearInterval(refreshBySelect.get(slotSelectElement));
  refreshBySelect.delete(slotSelectElement);

  if (!boundSelects.has(slotSelectElement)) {
    slotSelectElement.addEventListener('change', () => syncSubmitButton(slotSelectElement));
    boundSelects.add(slotSelectElement);
  }

  const date = dateInput.value;
  const branchId = normalizeBranch(branchInput.value);
  if (!date || !branchId) {
    slotSelectElement.replaceChildren(new Option('Select branch and date first', ''));
    slotSelectElement.disabled = true;
    syncSubmitButton(slotSelectElement);
    return;
  }

  slotSelectElement.replaceChildren(new Option('Checking availability…', ''));
  slotSelectElement.disabled = true;
  syncSubmitButton(slotSelectElement);

  const refresh = async () => {
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (requireAuthentication) {
        await authReady;
        if (!auth.currentUser) throw new Error('Please sign in again to check appointment availability.');
        headers.Authorization = `Bearer ${await auth.currentUser.getIdToken()}`;
      }
      const response = await fetch('api/slot-availability.php', {
        method: 'POST', headers,
        body: JSON.stringify({ branchId, date }),
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Availability could not be loaded.');
      if (runBySelect.get(slotSelectElement) !== run) return;

      const { date: today, minutes: nowMinutes } = manilaDateTime();
      const occupied = new Set(result.occupiedSlots || []);
      const hasAvailable = STANDARD_SLOTS.some((slot) => {
        const expired = date < today || (date === today && nowMinutes >= slotEndMinutes(slot));
        return !expired && !occupied.has(slot);
      });
      const options = [new Option('Select a time', '')];
      STANDARD_SLOTS.forEach((slot) => {
        const expired = date < today || (date === today && nowMinutes >= slotEndMinutes(slot));
        const unavailable = expired || occupied.has(slot);
        const option = new Option(`${slot}${unavailable ? ' (Unavailable)' : ''}`, slot);
        option.disabled = unavailable;
        if (unavailable) {
          option.style.color = '#9ca3af';
          option.style.backgroundColor = '#1f2937';
        }
        options.push(option);
      });
      slotSelectElement.replaceChildren(...options);
      slotSelectElement.disabled = false;
      syncSubmitButton(slotSelectElement);
      if (!hasAvailable) {
        const notice = slotSelectElement.form?.querySelector('[role="status"].availability-notice, #guest-booking-notice');
        if (notice) notice.textContent = 'No appointment times are available for this date. Please choose another date.';
      } else {
        const notice = slotSelectElement.form?.querySelector('[role="status"].availability-notice, #guest-booking-notice');
        if (notice) notice.textContent = '';
      }
    } catch (error) {
      if (runBySelect.get(slotSelectElement) !== run) return;
      console.error('Slot availability lookup failed:', error);
      slotSelectElement.replaceChildren(new Option('Availability unavailable', ''));
      slotSelectElement.disabled = true;
      syncSubmitButton(slotSelectElement);
    }
  };

  await refresh();
  if (runBySelect.get(slotSelectElement) === run) {
    refreshBySelect.set(slotSelectElement, setInterval(refresh, 15000));
  }
}
