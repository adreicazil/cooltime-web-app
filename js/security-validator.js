// Shared browser-side checks for public forms and account creation.
const DISPOSABLE_EMAIL_DOMAINS = [
  'tempmail.com', '10minutemail.com', 'guerrillamail.com', 'mailinator.com',
  'yopmail.com', 'trashmail.com', 'dispostable.com', 'bitproy.com', 'getnada.com'
];

const INAPPROPRIATE_WORDS = [
  'tangina', 'gago', 'tanga', 'bobo', 'ulol', 'puta', 'kantot', 'putangina',
  'fuck', 'shit', 'bitch', 'asshole', 'bastard', 'cunt', 'dick', 'pussy'
];

function isDisposableEmail(email) {
  if (typeof email !== 'string' || !email.includes('@')) return false;
  const domain = email.slice(email.lastIndexOf('@') + 1).toLowerCase().trim().replace(/\.$/, '');
  return DISPOSABLE_EMAIL_DOMAINS.some((banned) => domain === banned || domain.endsWith(`.${banned}`));
}

function isValidPHMobileNumber(phone) {
  if (typeof phone !== 'string') return false;
  const cleaned = phone.replace(/[\s\-()+]/g, '');
  return /^(639\d{9}|09\d{9})$/.test(cleaned);
}

function containsInappropriateContent(text) {
  if (typeof text !== 'string' || !text) return false;
  return INAPPROPRIATE_WORDS.some((word) => new RegExp(`\\b${word}\\b`, 'i').test(text));
}

function validateUploadedPhoto(file) {
  if (!file) return { valid: false, message: 'No file selected.' };
  const validExtensions = ['png', 'jpg', 'jpeg'];
  const validMimeTypes = ['image/png', 'image/jpeg'];
  const extension = String(file.name || '').split('.').pop().toLowerCase();
  const mime = String(file.type || '').toLowerCase();
  if (!validExtensions.includes(extension) || !validMimeTypes.includes(mime)) {
    return { valid: false, message: 'Only .png and .jpg photo attachments are allowed.' };
  }
  if (file.size > 5 * 1024 * 1024) return { valid: false, message: 'File size exceeds 5 MB limit.' };
  return { valid: true };
}

window.SecurityValidator = {
  isDisposableEmail,
  isValidPHMobileNumber,
  containsInappropriateContent,
  validateUploadedPhoto
};
