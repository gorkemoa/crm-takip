import { getStatusLabel } from './status.js';
import { getEntityLabel } from './activity.js';

const typeTitleMap = {
  project_created: 'Yeni proje oluşturuldu',
  stage_updated: 'Aşama güncellendi',
  task_assigned: 'Görev ataması yapıldı',
  task_status_changed: 'Görev durumu değişti',
  comment_added: 'Yeni yorum eklendi',
  idea_added: 'Yeni fikir eklendi',
  project_status_changed: 'Proje durumu değişti',
  project_member_updated: 'Proje ekibi güncellendi',
  member_joined: 'Ekibe yeni kullanıcı eklendi',
  design_asset_added: 'Yeni tasarım eklendi',
};

function shortText(value, limit = 140) {
  const text = String(value || '').trim();
  if (!text) return '';
  return text.length > limit ? `${text.slice(0, limit - 1).trimEnd()}…` : text;
}

export function getNotificationTitle(notification) {
  return typeTitleMap[notification?.type] || 'Yeni bildirim';
}

export function getNotificationDetail(notification) {
  const payload = notification?.payload || {};

  if (notification?.type === 'project_created') {
    return shortText(payload.title || payload.project_title || 'Proje oluşturuldu.');
  }

  if (notification?.type === 'stage_updated') {
    const parts = [
      shortText(payload.project_title || '', 80),
      shortText(payload.stage_name || '', 80),
      payload.status ? `Durum: ${getStatusLabel(payload.status)}` : '',
    ].filter(Boolean);
    return parts.join(' • ') || 'Aşama üzerinde değişiklik yapıldı.';
  }

  if (notification?.type === 'task_assigned') {
    const parts = [
      shortText(payload.title || 'Görev'),
      shortText(payload.project_title || '', 80),
      payload.status ? `Durum: ${getStatusLabel(payload.status)}` : '',
    ].filter(Boolean);
    return parts.join(' • ');
  }

  if (notification?.type === 'task_status_changed') {
    return `${shortText(payload.title || 'Görev')} • ${getStatusLabel(payload.from_status || '-')} → ${getStatusLabel(payload.to_status || '-')}`;
  }

  if (notification?.type === 'comment_added') {
    const entity = getEntityLabel(payload.entity_type || 'comment');
    const preview = shortText(payload.content_preview || '');
    const projectTitle = shortText(payload.project_title || '', 80);
    return [projectTitle, entity, preview].filter(Boolean).join(' • ');
  }

  if (notification?.type === 'idea_added') {
    return shortText(payload.title || 'Yeni fikir kaydı');
  }

  if (notification?.type === 'project_status_changed') {
    const projectTitle = shortText(payload.project_title || payload.title || 'Proje');
    return `${projectTitle} • ${getStatusLabel(payload.status || '-')}`;
  }

  if (notification?.type === 'project_member_updated') {
    const names = Array.isArray(payload.member_names)
      ? payload.member_names.slice(0, 3).map((item) => shortText(item, 28)).filter(Boolean).join(', ')
      : '';
    const memberName = shortText(payload.member_name || '', 40);
    const parts = [
      shortText(payload.project_title || 'Proje ekibi'),
      payload.member_status ? `Durum: ${getStatusLabel(payload.member_status)}` : '',
      memberName ? `Kişi: ${memberName}` : '',
      names ? `Kişiler: ${names}` : '',
      payload.removed ? 'Bir kişi ekipten çıkarıldı' : '',
    ].filter(Boolean);
    return parts.join(' • ');
  }

  if (notification?.type === 'member_joined') {
    return shortText(payload.name || 'Yeni kullanıcı eklendi');
  }

  if (notification?.type === 'design_asset_added') {
    const parts = [
      shortText(payload.entity_title || '', 80),
      shortText(payload.title || 'Tasarım görseli'),
    ].filter(Boolean);
    return parts.join(' • ');
  }

  return shortText(payload.title || payload.project_title || 'Bildirim detayları güncellendi.');
}

export function formatNotification(notification) {
  return {
    title: getNotificationTitle(notification),
    detail: getNotificationDetail(notification),
  };
}
