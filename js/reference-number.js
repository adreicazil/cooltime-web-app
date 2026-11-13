const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

export function createReferenceNumber(paymentSource, branch, date = new Date()) {
  const normalized = String(branch || '').toLowerCase().replace(/[^a-z]/g, '');
  const branchCode = ({ cabuyao: 'CBY', cabuyaomain: 'CBY', cabuyaobranch: 'CBY', cabuyaomainbranch: 'CBY', santarosa: 'RSA', santarosabranch: 'RSA' })[normalized]
    || normalized.slice(0, 3).toUpperCase();
  if (!/^[A-Z]{3}$/.test(branchCode)) throw new Error('A valid branch code is required to create a reference number.');
  const source = String(paymentSource || '').toUpperCase();
  if (!['OTC', 'PMO'].includes(source)) throw new Error('A valid payment source is required to create a reference number.');
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
  const value = Object.fromEntries(parts.map(({ type, value: part }) => [type, part]));
  const dateCode = `${value.year}${value.month}${value.day}`;
  const random = new Uint32Array(4);
  if (globalThis.crypto?.getRandomValues) crypto.getRandomValues(random);
  else for (let i = 0; i < random.length; i++) random[i] = Math.floor(Math.random() * 0xffffffff);
  const code = [...random].map((number) => ALPHABET[number % ALPHABET.length]).join('');
  return `${source}-${branchCode}-${dateCode}-${code}`;
}
