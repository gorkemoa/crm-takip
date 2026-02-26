export const IDEA_STATUS_OPTIONS = [
  { value: 'idea', label: 'Fikir' },
  { value: 'researching', label: 'Araştırılıyor' },
  { value: 'planned', label: 'Planlandı' },
];

export const IDEA_CATEGORY_OPTIONS = [
  { value: 'web', label: 'Web' },
  { value: 'mobil', label: 'Mobil' },
  { value: 'urun_stratejisi', label: 'Ürün Stratejisi' },
  { value: 'e_ticaret', label: 'E-Ticaret' },
  { value: 'crm_automasyon', label: 'CRM ve Otomasyon' },
  { value: 'ai_ml', label: 'Yapay Zeka ve ML' },
  { value: 'ui_ux', label: 'UI/UX ve Tasarım' },
  { value: 'entegrasyon_api', label: 'Entegrasyon ve API' },
  { value: 'veri_raporlama', label: 'Veri ve Raporlama' },
  { value: 'performans', label: 'Performans Optimizasyonu' },
  { value: 'guvenlik', label: 'Güvenlik' },
  { value: 'pazarlama_buyume', label: 'Pazarlama ve Büyüme' },
  { value: 'musteri_deneyimi', label: 'Müşteri Deneyimi' },
];

const statusLabelMap = new Map(IDEA_STATUS_OPTIONS.map((item) => [item.value, item.label]));
const categoryLabelMap = new Map(IDEA_CATEGORY_OPTIONS.map((item) => [item.value, item.label]));
const statusToneMap = new Map([
  ['idea', 'neutral'],
  ['researching', 'info'],
  ['planned', 'warn'],
]);

export function getIdeaStatusLabel(value) {
  return statusLabelMap.get(value) || value;
}

export function getIdeaStatusTone(value) {
  return statusToneMap.get(value) || 'neutral';
}

export function normalizeIdeaCategories(value) {
  const aliases = {
    web_platformu: 'web',
    mobil_uygulama: 'mobil',
  };
  const allowed = new Set(IDEA_CATEGORY_OPTIONS.map((item) => item.value));
  const list = Array.isArray(value)
    ? value
    : String(value ?? '')
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);

  return list
    .map((item) => aliases[item] || item)
    .filter((item, index, items) => allowed.has(item) && items.indexOf(item) === index);
}

export function ideaStatusOptionTemplate(selected = 'idea') {
  return IDEA_STATUS_OPTIONS.map(
    (option) => `<option value="${option.value}" ${option.value === selected ? 'selected' : ''}>${option.label}</option>`
  ).join('');
}

export function getIdeaCategoryLabel(value) {
  const list = normalizeIdeaCategories(value);
  if (!list.length) return 'Kategori yok';
  return categoryLabelMap.get(list[0]) || list[0];
}

export function getIdeaCategoryLabels(value) {
  const list = normalizeIdeaCategories(value);
  if (!list.length) return ['Kategori yok'];
  return list.map((item) => categoryLabelMap.get(item) || item);
}

export function ideaCategoryOptionTemplate(selected = '') {
  return IDEA_CATEGORY_OPTIONS.map(
    (option) => `<option value="${option.value}" ${option.value === selected ? 'selected' : ''}>${option.label}</option>`
  ).join('');
}

export function ideaCategoryChecklistTemplate(selected = []) {
  const selectedSet = new Set(normalizeIdeaCategories(selected));
  return IDEA_CATEGORY_OPTIONS.map(
    (option) => `
      <label class="check-row">
        <input type="checkbox" name="categories" value="${option.value}" ${selectedSet.has(option.value) ? 'checked' : ''} />
        <span>${option.label}</span>
      </label>
    `
  ).join('');
}
