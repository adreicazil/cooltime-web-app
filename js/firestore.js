import {
  collection, doc, getDoc, getDocs, query, serverTimestamp, setDoc, where, writeBatch
} from 'https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore.js';
import { db } from './firebase-config.js';

export const BRANCHES = Object.freeze({
  // Seed defaults. Branch admins may customize these fields in Firestore.
  cabuyao: {
    name: 'Cabuyao Main Branch', address: 'Cabuyao, Laguna', capacity: 4, status: 'active',
    operatingHours: { open: '08:00', close: '17:00' },
    operatingDays: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    holidaysOpen: true, slotDurationMinutes: 90, timezone: 'Asia/Manila'
  },
  santarosa: {
    name: 'Santa Rosa Branch', address: 'Santa Rosa, Laguna', capacity: 3, status: 'active',
    operatingHours: { open: '08:00', close: '17:00' },
    operatingDays: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    holidaysOpen: true, slotDurationMinutes: 90, timezone: 'Asia/Manila'
  }
});

export const SERVICES = Object.freeze({
  svc_cleaning: { name: 'General AC Cleaning', price: 1500, duration: '2 hours' },
  svc_freon: { name: 'Freon Charging', price: 800, duration: '1 hour' }
});

const required = (value, label) => {
  const result = String(value || '').trim();
  if (!result) throw new Error(`${label} is required.`);
  return result;
};

export async function createUserProfile(uid, { email, username, name = username }) {
  await setDoc(doc(db, 'users', uid), {
    uid,
    email: required(email, 'Email').toLowerCase(),
    name: required(name, 'Name'),
    username: required(username, 'Username'),
    role: 'customer',
    status: 'active',
    createdAt: serverTimestamp(),
    updatedAt: serverTimestamp()
  }, { merge: true });
}

export async function getUserProfile(uid) {
  const snapshot = await getDoc(doc(db, 'users', uid));
  return snapshot.exists() ? snapshot.data() : null;
}

export async function getCustomerVehicles(customerId) {
  const snapshot = await getDocs(query(collection(db, 'vehicles'), where('customerId', '==', customerId)));
  return snapshot.docs.map((item) => ({ id: item.id, ...item.data() }));
}

// Invoices store service IDs, not denormalized service objects. Resolve the IDs
// on read so a billing UI can display current service names and prices.
export async function getCustomerInvoices(customerId) {
  const snapshot = await getDocs(query(collection(db, 'invoices'), where('customerId', '==', customerId)));
  const invoices = snapshot.docs.map((item) => ({ id: item.id, ...item.data() }));
  const ids = [...new Set(invoices.flatMap((invoice) => Array.isArray(invoice.servicesAdded) ? invoice.servicesAdded : []))];
  const services = await Promise.all(ids.map(async (id) => {
    const service = await getDoc(doc(db, 'services', id));
    return [id, service.exists() ? { id: service.id, ...service.data() } : null];
  }));
  const serviceById = Object.fromEntries(services);
  return invoices.map((invoice) => {
    const servicesAdded = Array.isArray(invoice.servicesAdded) ? invoice.servicesAdded : [];
    return { ...invoice, services: servicesAdded.map((id) => serviceById[id]).filter(Boolean) };
  });
}

export async function createBooking({ customerId, branchId, serviceId, date, timeSlot, vehicle }) {
  const vehicleRef = doc(collection(db, 'vehicles'));
  const bookingRef = doc(collection(db, 'bookings'));
  const vehicleData = {
    customerId,
    make: required(vehicle?.make, 'Vehicle make'),
    model: required(vehicle?.model, 'Vehicle model'),
    plateNumber: required(vehicle?.plateNumber, 'Vehicle plate number').toUpperCase(),
    year: required(vehicle?.year, 'Vehicle year'),
    createdAt: serverTimestamp(),
    updatedAt: serverTimestamp()
  };
  const batch = writeBatch(db);
  batch.set(vehicleRef, vehicleData);
  batch.set(bookingRef, {
    customerId, branchId: required(branchId, 'Branch'), serviceId: required(serviceId, 'Service'),
    vehicleId: vehicleRef.id, date: required(date, 'Appointment date'), timeSlot: required(timeSlot, 'Time slot'),
    status: 'pending', createdAt: serverTimestamp(), updatedAt: serverTimestamp()
  });
  await batch.commit();
  return { bookingId: bookingRef.id, vehicleId: vehicleRef.id };
}

export async function seedCoreCatalog() {
  const batch = writeBatch(db);
  let updated = 0;
  let hasWrites = false;
  await Promise.all(Object.entries(BRANCHES).map(async ([id, value]) => {
    const reference = doc(db, 'branches', id);
    const snapshot = await getDoc(reference);
    if (!snapshot.exists()) {
      batch.set(reference, { branchId: id, ...value, createdAt: serverTimestamp(), updatedAt: serverTimestamp() });
      updated += 1;
      hasWrites = true;
      return;
    }
    const current = snapshot.data();
    const missingSettings = {};
    if (!Number.isInteger(Number(current.capacity)) || Number(current.capacity) < 1) missingSettings.capacity = value.capacity;
    if (!String(current.status || '').trim()) missingSettings.status = value.status;
    const hours = current.operatingHours;
    if (!hours || (hours.open || hours.start) !== value.operatingHours.open || (hours.close || hours.end) !== value.operatingHours.close) {
      missingSettings.operatingHours = value.operatingHours;
    }
    const oldBreak = Array.isArray(hours?.breaks) && hours.breaks.length === 1
      && hours.breaks[0].start === '12:00' && hours.breaks[0].end === '13:00';
    if (oldBreak) {
      missingSettings.operatingHours = value.operatingHours;
    }
    if (!Number.isInteger(Number(current.slotDurationMinutes)) || Number(current.slotDurationMinutes) <= 0) missingSettings.slotDurationMinutes = value.slotDurationMinutes;
    if (JSON.stringify(current.operatingDays || []) !== JSON.stringify(value.operatingDays)) missingSettings.operatingDays = value.operatingDays;
    if (current.holidaysOpen !== value.holidaysOpen) missingSettings.holidaysOpen = value.holidaysOpen;
    if (!String(current.timezone || '').trim()) missingSettings.timezone = value.timezone;
    if (Object.keys(missingSettings).length) {
      batch.update(reference, { ...missingSettings, updatedAt: serverTimestamp() });
      updated += 1;
      hasWrites = true;
    }
  }));
  await Promise.all(Object.entries(SERVICES).map(async ([id, value]) => {
    const reference = doc(db, 'services', id);
    if (!(await getDoc(reference)).exists()) {
      batch.set(reference, { serviceId: id, ...value, createdAt: serverTimestamp(), updatedAt: serverTimestamp() });
      hasWrites = true;
    }
  }));
  if (hasWrites) await batch.commit();
  return updated;
}
