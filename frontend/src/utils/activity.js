import { getStatusLabel } from './status.js';

const actionMap = {
  create: 'oluşturdu',
  update: 'güncelledi',
  delete: 'sildi',
  assign: 'atama yaptı',
  unassign: 'atamayı kaldırdı',
  move_stage: 'aşama taşıdı',
  move: 'taşıdı',
  comment: 'yorum ekledi',
  convert: 'projeye dönüştürdü',
};

const entityMap = {
  project: 'Proje',
  stage: 'Aşama',
  task: 'Görev',
  idea: 'Fikir',
  design_asset: 'Tasarım',
  comment: 'Yorum',
  user: 'Kullanıcı',
  project_member: 'Proje Üyesi',
};

function toStatusTransition(meta = {}) {
  if (meta.from_status || meta.to_status) {
    return `${getStatusLabel(meta.from_status || '-')}` + ' → ' + `${getStatusLabel(meta.to_status || '-')}`;
  }
  if (meta.before_status || meta.after_status) {
    return `${getStatusLabel(meta.before_status || '-')}` + ' → ' + `${getStatusLabel(meta.after_status || '-')}`;
  }
  if (meta.member_status) {
    return getStatusLabel(meta.member_status);
  }
  return null;
}

function safeText(value) {
  if (value === null || value === undefined) return '';
  return String(value).trim();
}

function shortText(value, limit = 120) {
  const text = safeText(value);
  if (!text) return '';
  return text.length > limit ? `${text.slice(0, limit - 1).trimEnd()}...` : text;
}

function entityTitleFromMeta(meta = {}) {
  if (safeText(meta.title)) return `"${safeText(meta.title)}"`;
  if (safeText(meta.name)) return safeText(meta.name);
  if (safeText(meta.project_title)) return `Proje: ${safeText(meta.project_title)}`;
  if (safeText(meta.stage_name)) return `Aşama: ${safeText(meta.stage_name)}`;
  if (safeText(meta.task_title)) return `Görev: ${safeText(meta.task_title)}`;
  if (safeText(meta.idea_title)) return `Fikir: ${safeText(meta.idea_title)}`;
  if (safeText(meta.username)) return `Kullanıcı: ${safeText(meta.username)}`;
  return '';
}

export function getEntityLabel(entityType) {
  return entityMap[entityType] || entityType || 'Kayıt';
}

export function getActionLabel(action) {
  return actionMap[action] || action || 'işlem yaptı';
}

export function formatActivity(item) {
  const actor = item?.actor_name || 'Kullanıcı';
  const action = getActionLabel(item?.action);
  const entityLabel = getEntityLabel(item?.entity_type);
  const meta = item?.meta || {};

  const title = `${actor} ${action}`;

  const parts = [];
  const metaTitle = entityTitleFromMeta(meta);
  if (metaTitle) parts.push(metaTitle);

  if (Array.isArray(meta.member_names) && meta.member_names.length) {
    const visible = meta.member_names.slice(0, 3).map((item) => safeText(item)).filter(Boolean);
    const suffix = meta.member_names.length > visible.length ? ` +${meta.member_names.length - visible.length}` : '';
    if (visible.length) {
      parts.push(`Kişiler: ${visible.join(', ')}${suffix}`);
    }
  }

  if (safeText(meta.member_name)) {
    parts.push(`Kişi: ${safeText(meta.member_name)}`);
  }

  if (Array.isArray(meta.member_ids) && meta.member_ids.length) {
    parts.push(`Etkilenen kişi sayısı: ${meta.member_ids.length}`);
  }
  if (meta.removed === true) {
    parts.push('Ekipten çıkarma yapıldı');
  }

  const statusTransition = toStatusTransition(meta);
  if (statusTransition) parts.push(`Durum: ${statusTransition}`);

  if (safeText(meta.content_preview)) {
    parts.push(`Not: ${shortText(meta.content_preview, 110)}`);
  }

  if (!parts.length) {
    parts.push(entityLabel);
  }

  return {
    title,
    detail: parts.join(' • '),
    entityLabel,
    actionLabel: action,
    entityRef: entityLabel,
  };
}
