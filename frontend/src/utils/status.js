export const PROJECT_STATUS_OPTIONS = [
  { value: 'baslaniyor', label: 'Başlanıyor' },
  { value: 'tartisiliyor', label: 'Üzerinde Tartışılıyor' },
  { value: 'arastiriliyor', label: 'Araştırılıyor' },
  { value: 'tasarlaniyor', label: 'Tasarlanıyor' },
  { value: 'gelistiriliyor', label: 'Geliştiriliyor' },
  { value: 'test_ediliyor', label: 'Test Ediliyor' },
  { value: 'yayina_hazir', label: 'Yayına Hazır' },
  { value: 'yayinda', label: 'Yayında' },
  { value: 'bakimda', label: 'Bakımda' },
  { value: 'duraklatildi', label: 'Duraklatıldı' },
  { value: 'iptal_talebi', label: 'İptal Talebi' },
  { value: 'iptal_edildi', label: 'İptal Edildi' },
  { value: 'tamamlandi', label: 'Tamamlandı' },
];

export const PROJECT_TYPE_OPTIONS = [
  { value: 'web', label: 'Web' },
  { value: 'mobil', label: 'Mobil' },
  { value: 'app', label: 'Uygulama' },
  { value: 'tasarim', label: 'Tasarım' },
  { value: 'diger', label: 'Diğer' },
];

export const PRIORITY_OPTIONS = [
  { value: 'low', label: 'Düşük' },
  { value: 'med', label: 'Orta' },
  { value: 'high', label: 'Yüksek' },
];

export const STAGE_STATUS_OPTIONS = [
  { value: 'baslanmadi', label: 'Başlanmadı' },
  { value: 'baslaniyor', label: 'Başlanıyor' },
  { value: 'tartisiliyor', label: 'Üzerinde Tartışılıyor' },
  { value: 'arastiriliyor', label: 'Araştırılıyor' },
  { value: 'tasarlaniyor', label: 'Tasarlanıyor' },
  { value: 'gelistiriliyor', label: 'Geliştiriliyor' },
  { value: 'test_ediliyor', label: 'Test Ediliyor' },
  { value: 'engellendi', label: 'Engellendi' },
  { value: 'tamamlandi', label: 'Tamamlandı' },
];

export const TASK_STATUS_OPTIONS = [
  { value: 'backlog', label: 'Backlog' },
  { value: 'baslaniyor', label: 'Başlanıyor' },
  { value: 'tartisiliyor', label: 'Üzerinde Tartışılıyor' },
  { value: 'arastiriliyor', label: 'Araştırılıyor' },
  { value: 'tasarlaniyor', label: 'Tasarlanıyor' },
  { value: 'gelistiriliyor', label: 'Geliştiriliyor' },
  { value: 'test_ediliyor', label: 'Test Ediliyor' },
  { value: 'incelemede', label: 'İncelemede' },
  { value: 'engellendi', label: 'Engellendi' },
  { value: 'tamamlandi', label: 'Tamamlandı' },
];

export const IDEA_STATUS_OPTIONS_COMMON = [
  { value: 'idea', label: 'Fikir Aşamasında' },
  { value: 'researching', label: 'Araştırılıyor' },
  { value: 'planned', label: 'Planlandı' },
];

export const TASK_BOARD_COLUMNS = [
  {
    key: 'planlama',
    label: 'Planlama',
    statuses: ['backlog', 'baslaniyor', 'tartisiliyor', 'arastiriliyor'],
  },
  {
    key: 'uretim',
    label: 'Üretim',
    statuses: ['tasarlaniyor', 'gelistiriliyor', 'test_ediliyor'],
  },
  {
    key: 'gozden_gecirme',
    label: 'Gözden Geçirme',
    statuses: ['incelemede', 'engellendi'],
  },
  {
    key: 'tamamlanan',
    label: 'Tamamlanan',
    statuses: ['tamamlandi'],
  },
];

const allStatusEntries = [...PROJECT_STATUS_OPTIONS, ...STAGE_STATUS_OPTIONS, ...TASK_STATUS_OPTIONS, ...IDEA_STATUS_OPTIONS_COMMON];
const statusLabelMap = new Map(allStatusEntries.map((item) => [item.value, item.label]));
const projectTypeLabelMap = new Map(PROJECT_TYPE_OPTIONS.map((item) => [item.value, item.label]));
const priorityLabelMap = new Map(PRIORITY_OPTIONS.map((item) => [item.value, item.label]));

const toneMap = new Map([
  ['baslanmadi', 'neutral'],
  ['backlog', 'neutral'],
  ['baslaniyor', 'info'],
  ['tartisiliyor', 'warn'],
  ['arastiriliyor', 'info'],
  ['tasarlaniyor', 'info'],
  ['gelistiriliyor', 'info'],
  ['test_ediliyor', 'warn'],
  ['incelemede', 'warn'],
  ['yayina_hazir', 'warn'],
  ['yayinda', 'ok'],
  ['bakimda', 'info'],
  ['duraklatildi', 'danger'],
  ['iptal_talebi', 'warn'],
  ['iptal_edildi', 'danger'],
  ['engellendi', 'danger'],
  ['tamamlandi', 'ok'],
  ['idea', 'neutral'],
  ['researching', 'info'],
  ['planned', 'warn'],
]);

export function getStatusLabel(value) {
  return statusLabelMap.get(value) || value;
}

export function getStatusTone(value) {
  return toneMap.get(value) || 'neutral';
}

export function normalizeMultiSelect(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item).trim()).filter(Boolean);
  }

  if (value === null || value === undefined) {
    return [];
  }

  const raw = String(value).trim();
  if (!raw) return [];

  try {
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed)) {
      return parsed.map((item) => String(item).trim()).filter(Boolean);
    }
  } catch (error) {
    // not json, fallback below
  }

  return raw
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
}

export function normalizeProjectTypes(value) {
  const aliases = {
    design: 'tasarim',
    other: 'diger',
    mobile: 'mobil',
    mobil_uygulama: 'mobil',
  };
  const allowed = new Set(PROJECT_TYPE_OPTIONS.map((item) => item.value));

  return normalizeMultiSelect(value)
    .map((item) => aliases[item] || item)
    .filter((item, index, list) => allowed.has(item) && list.indexOf(item) === index);
}

export function getProjectTypeLabel(value) {
  return projectTypeLabelMap.get(value) || value;
}

export function getProjectTypeLabels(value) {
  const list = normalizeProjectTypes(value);
  if (!list.length) return ['Diğer'];
  return list.map((item) => getProjectTypeLabel(item));
}

export function getPriorityLabel(value) {
  return priorityLabelMap.get(value) || value;
}

export function getTaskColumn(taskStatus) {
  return TASK_BOARD_COLUMNS.find((column) => column.statuses.includes(taskStatus)) || TASK_BOARD_COLUMNS[0];
}

export function isCompletedStatus(value) {
  return value === 'tamamlandi';
}
