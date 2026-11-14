export function normalizeBranchId(value) {
  const normalized = String(value || '').trim().toLowerCase().replace(/[^a-z]/g, '');
  if (['cabuyao', 'cabuyaomain', 'cabuyaobranch', 'cabuyaomainbranch'].includes(normalized)) return 'cabuyao';
  if (['santarosa', 'santarosabranch'].includes(normalized)) return 'santa_rosa';
  return '';
}

export function normalizeBranchLabel(value, fallback = '—') {
  const id = normalizeBranchId(value);
  if (id === 'cabuyao') return 'Cabuyao';
  if (id === 'santa_rosa') return 'Santa Rosa';
  const label = String(value || '').trim().replace(/\s+branch\s*$/i, '').trim();
  return label || fallback;
}

export function normalizeBranchText(value) {
  return String(value ?? '')
    .replace(/Cabuyao\s+Main\s+Branch/gi, 'Cabuyao')
    .replace(/Cabuyao\s+Branch/gi, 'Cabuyao')
    .replace(/Santa\s+Rosa\s+Branch/gi, 'Santa Rosa');
}
