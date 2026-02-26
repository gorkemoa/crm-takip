import { api } from '../utils/api.js';
import { escapeHtml, formToObject, qs, qsa } from '../utils/dom.js';
import { formatDate } from '../utils/date.js';
import { showToast } from '../components/toast.js';
import { setState } from '../store/index.js';
import { openModal, closeModal } from '../components/modal.js';
import { skeleton } from '../components/ui.js';
import {
  PROJECT_STATUS_OPTIONS,
  PROJECT_TYPE_OPTIONS,
  STAGE_STATUS_OPTIONS,
  TASK_STATUS_OPTIONS,
  getStatusLabel,
  getStatusTone,
  getPriorityLabel,
  PRIORITY_OPTIONS,
  normalizeProjectTypes,
  getProjectTypeLabels,
} from '../utils/status.js';
import { formatActivity } from '../utils/activity.js';

const STAGE_PRESET_TITLES = [
  'Brief ve Hedef',
  'Araştırma',
  'Üzerinde Tartışma',
  'Tasarım',
  'Geliştirme',
  'İç Test',
  'Müşteri Onayı',
  'Yayın',
  'Bakım',
];

const STAGE_STATUS_TO_TASK_STATUS = {
  baslanmadi: 'backlog',
  baslaniyor: 'baslaniyor',
  tartisiliyor: 'tartisiliyor',
  arastiriliyor: 'arastiriliyor',
  tasarlaniyor: 'tasarlaniyor',
  gelistiriliyor: 'gelistiriliyor',
  test_ediliyor: 'test_ediliyor',
  engellendi: 'engellendi',
  tamamlandi: 'tamamlandi',
};

const DETAIL_TABS = [
  { key: 'genel', label: 'Genel Bakış', note: 'Özet, ekip ve proje yorumları' },
  { key: 'mvp', label: 'MVP', note: 'İlk sürüme girecek temel maddeler' },
  { key: 'yapilacaklar', label: 'Yapılacaklar', note: 'Bir sonraki sürüm özellik planı' },
  { key: 'tasarim', label: 'Tasarım', note: 'Görseller, beğeni ve tasarım yorumları' },
  { key: 'asamalar', label: 'Aşamalar', note: 'Aşama planı ve saat takibi' },
  { key: 'gorevler', label: 'Görev Panosu', note: 'Aşamaya göre koordineli görev akışı' },
  { key: 'aktivite', label: 'Aktivite', note: 'Değişim geçmişi' },
];

const DETAIL_TAB_CONFIG = {
  genel: {
    include: 'members,activity',
    includeMembers: true,
    includeStages: false,
    includeTasks: false,
    includeActivity: true,
    activityLimit: 18,
    needsUsers: true,
    needsComments: true,
    needsDesignAssets: false,
  },
  mvp: {
    include: 'none',
    includeMembers: false,
    includeStages: false,
    includeTasks: false,
    includeActivity: false,
    needsUsers: false,
    needsComments: false,
    needsDesignAssets: false,
  },
  yapilacaklar: {
    include: 'none',
    includeMembers: false,
    includeStages: false,
    includeTasks: false,
    includeActivity: false,
    needsUsers: false,
    needsComments: false,
    needsDesignAssets: false,
  },
  tasarim: {
    include: 'none',
    includeMembers: false,
    includeStages: false,
    includeTasks: false,
    includeActivity: false,
    needsUsers: false,
    needsComments: false,
    needsDesignAssets: true,
  },
  asamalar: {
    include: 'stages',
    includeMembers: false,
    includeStages: true,
    includeTasks: false,
    includeActivity: false,
    needsUsers: false,
    needsComments: false,
    needsDesignAssets: false,
  },
  gorevler: {
    include: 'members,stages,tasks',
    includeMembers: true,
    includeStages: true,
    includeTasks: true,
    includeActivity: false,
    taskLimit: 500,
    needsUsers: true,
    needsComments: false,
    needsDesignAssets: false,
  },
  aktivite: {
    include: 'activity',
    includeMembers: false,
    includeStages: false,
    includeTasks: false,
    includeActivity: true,
    activityLimit: 120,
    needsUsers: false,
    needsComments: false,
    needsDesignAssets: false,
  },
};

function initials(name = '') {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() || '')
    .join('');
}

function optionTemplate(options, selected = '') {
  return options
    .map((option) => `<option value="${option.value}" ${selected === option.value ? 'selected' : ''}>${option.label}</option>`)
    .join('');
}

function statusOptions(options, selected) {
  return optionTemplate(options, selected);
}

function assigneeOptions(users, selectedId = null) {
  return `
    <option value="">Atanmamış</option>
    ${users
      .map((user) => `<option value="${user.id}" ${Number(selectedId) === Number(user.id) ? 'selected' : ''}>${escapeHtml(user.name)}</option>`)
      .join('')}
  `;
}

function taskStageOptions(stages, selectedId = null) {
  const orderedStages = [...stages].sort((a, b) => a.order_index - b.order_index || a.id - b.id);
  return `
    <option value="" ${selectedId === null ? 'selected' : ''}>Aşamasız</option>
    ${orderedStages
      .map((stage) => `<option value="${stage.id}" ${Number(selectedId) === Number(stage.id) ? 'selected' : ''}>${escapeHtml(stage.name)}</option>`)
      .join('')}
  `;
}

function normalizeStageName(value = '') {
  return String(value).trim().toLocaleLowerCase('tr-TR');
}

function availableStageTitleOptions(stages, selectedName = '') {
  const selectedKey = normalizeStageName(selectedName);
  const existing = new Set(stages.map((stage) => normalizeStageName(stage.name)));

  const available = STAGE_PRESET_TITLES.filter((title) => {
    const key = normalizeStageName(title);
    return !existing.has(key) || key === selectedKey;
  });

  if (selectedName && !available.some((item) => normalizeStageName(item) === selectedKey)) {
    available.push(selectedName);
  }

  return available;
}

function stageTitleSelect(stages, selectedName = '') {
  const options = availableStageTitleOptions(stages, selectedName);
  return options.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');
}

function taskDefaultStatusForStage(stage) {
  if (!stage) {
    return 'backlog';
  }

  const mapped = STAGE_STATUS_TO_TASK_STATUS[stage.status];
  if (mapped && TASK_STATUS_OPTIONS.some((option) => option.value === mapped)) {
    return mapped;
  }

  return 'backlog';
}

function projectTypeChecklist(selected = []) {
  const selectedSet = new Set(normalizeProjectTypes(selected));
  return PROJECT_TYPE_OPTIONS.map(
    (option) => `
      <label class="check-row">
        <input type="checkbox" name="types" value="${option.value}" ${selectedSet.has(option.value) ? 'checked' : ''} />
        <span>${option.label}</span>
      </label>
    `
  ).join('');
}

function projectPriorityOptions(selected = 'med') {
  return optionTemplate(PRIORITY_OPTIONS, selected);
}

function collectSelected(form, name) {
  return Array.from(form.querySelectorAll(`input[name="${name}"]:checked`)).map((item) => item.value);
}

function parseNullableId(value) {
  if (value === '' || value === null || value === undefined) {
    return null;
  }

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function normalizeTab(tab) {
  return DETAIL_TAB_CONFIG[tab] ? tab : 'genel';
}

function renderHeader(project, permissions) {
  const canEdit = permissions.canEditProject;
  const canDelete = permissions.canDeleteProject;
  const typeBadges = getProjectTypeLabels(project.types || project.type)
    .map((label) => `<span class="badge badge-neutral">Tür: ${escapeHtml(label)}</span>`)
    .join('');

  return `
    <article class="card project-hero-card">
      <div class="row between top wrap gap-12">
        <div class="stack gap-8">
          <h1>${escapeHtml(project.title)}</h1>
          <p class="muted">${escapeHtml(project.description || 'Bu proje için henüz açıklama girilmemiş.')}</p>
          <div class="row gap-8 wrap">
            ${typeBadges}
            <span class="badge badge-neutral">Öncelik: ${escapeHtml(getPriorityLabel(project.priority))}</span>
            <span class="badge badge-${getStatusTone(project.status)}">${escapeHtml(getStatusLabel(project.status))}</span>
          </div>
        </div>

        <div class="stack gap-8 project-hero-meta">
          <label>Proje durumu
            <select id="project-status-select" ${canEdit ? '' : 'disabled'}>
              ${statusOptions(PROJECT_STATUS_OPTIONS, project.status)}
            </select>
          </label>
          <p class="muted small">Hedef tarih: ${project.due_date ? formatDate(project.due_date, { withTime: false }) : 'Belirtilmedi'}</p>
          <p class="muted small">Son güncelleme: ${formatDate(project.updated_at)}</p>
          <div class="row gap-8 wrap project-header-actions">
            ${canEdit ? '<button class="btn btn-ghost" data-action="project-edit">Projeyi Düzenle</button>' : ''}
            ${canDelete ? '<button class="btn btn-ghost btn-danger-text" data-action="project-delete">Projeyi Sil</button>' : ''}
          </div>
        </div>
      </div>
    </article>
  `;
}

function renderMembersBoard(project, members, users, canManageMembers, currentUserId) {
  const memberCards = members.length
    ? members
        .map((member) => {
          const canRemove = canManageMembers && Number(member.id) !== Number(project.created_by);
          return `
            <article class="member-status-box">
              <div class="row gap-12 top">
                <span class="avatar-pill">${escapeHtml(initials(member.name))}</span>
                <div class="stack gap-8 grow">
                  <div class="row between wrap">
                    <div>
                      <strong>${escapeHtml(member.name)}</strong>
                      <p class="muted small">${escapeHtml(member.role)}${Number(member.id) === Number(currentUserId) ? ' • Sen' : ''}</p>
                    </div>
                    ${canRemove ? `<button class="btn btn-ghost tiny" data-action="member-remove" data-user-id="${member.id}">Projeden Çıkar</button>` : ''}
                  </div>
                  <select data-action="member-status" data-user-id="${member.id}" ${canManageMembers ? '' : 'disabled'}>
                    ${statusOptions(PROJECT_STATUS_OPTIONS, member.member_status)}
                  </select>
                </div>
              </div>
            </article>
          `;
        })
        .join('')
    : '<div class="empty-state"><h3>Ekip üyesi yok</h3><p>Bu projeye henüz ekip atanmamış.</p></div>';

  const addableUsers = users.filter((user) => !members.some((member) => Number(member.id) === Number(user.id)));

  const addMemberForm = canManageMembers
    ? `
      <form id="project-add-member-form" class="inline-form">
        <label>Üye ekle
          <select name="user_id" required>
            <option value="">Kullanıcı seç</option>
            ${addableUsers
              .map((user) => `<option value="${user.id}">${escapeHtml(user.name)} (${escapeHtml(user.role)})</option>`)
              .join('')}
          </select>
        </label>
        <label>Başlangıç durumu
          <select name="member_status">
            ${statusOptions(PROJECT_STATUS_OPTIONS, 'baslaniyor')}
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Üye Ekle</button>
      </form>
    `
    : '';

  return `
    <article class="card">
      <div class="card-head">
        <h3>Ekip Durum Panosu</h3>
        <span class="badge badge-neutral">${members.length} kişi</span>
      </div>
      ${addMemberForm}
      <div class="member-status-grid mt-16">${memberCards}</div>
    </article>
  `;
}

function normalizeWorkItems(items = []) {
  if (!Array.isArray(items)) return [];
  return items
    .map((item) => {
      if (typeof item === 'string') {
        return { text: item.trim(), done: false };
      }
      if (!item || typeof item !== 'object') {
        return null;
      }
      const text = String(item.text || item.title || '').trim();
      if (!text) return null;
      return {
        text,
        done: Boolean(item.done || item.completed),
      };
    })
    .filter(Boolean);
}

function renderWorkItemsCard(title, key, items, editable) {
  const doneCount = items.filter((item) => item.done).length;
  const progress = items.length ? Math.round((doneCount / items.length) * 100) : 0;
  const rows = items.length
    ? items
        .map(
          (item, index) => `
            <li class="work-item ${item.done ? 'done' : ''}">
              <div class="work-item-check">
                <input type="checkbox" data-action="work-toggle" data-key="${key}" data-index="${index}" ${item.done ? 'checked' : ''} ${editable ? '' : 'disabled'} />
              </div>
              <span class="work-item-text">${escapeHtml(item.text)}</span>
              <span class="work-item-status ${item.done ? 'is-done' : 'is-pending'}">${item.done ? 'Tamamlandı' : 'Bekliyor'}</span>
              <div class="work-item-actions">
                ${editable ? `<button class="btn btn-ghost tiny btn-danger-text" data-action="work-remove" data-key="${key}" data-index="${index}">Sil</button>` : ''}
              </div>
            </li>
          `
        )
        .join('')
    : '<div class="work-empty">Henüz madde yok.</div>';

  return `
    <article class="card workboard-card">
      <div class="workboard-head">
        <div class="stack gap-8">
          <h3>${escapeHtml(title)}</h3>
          <p class="muted small">Toplam ${items.length} madde • Tamamlanan ${doneCount}</p>
        </div>
        <span class="badge badge-neutral">%${progress}</span>
      </div>
      <div class="work-table-head">
        <span>✓</span>
        <span>Madde</span>
        <span>Durum</span>
        <span>İşlem</span>
      </div>
      <ul class="work-item-list">${rows}</ul>
      ${
        editable
          ? `
            <form class="work-add-form" data-action="work-add-form" data-key="${key}">
              <input type="text" name="text" maxlength="220" placeholder="Yeni madde ekle" />
              <button class="btn btn-primary tiny" type="submit">Ekle</button>
            </form>
          `
          : ''
      }
    </article>
  `;
}

function renderDesignBoard(entityType, entityId, assets, editable) {
  const cards = assets.length
    ? assets
        .map(
          (asset) => `
            <article class="design-card">
              <div class="design-preview">
                <img src="${escapeHtml(asset.image_url)}" alt="${escapeHtml(asset.title || 'Tasarım görseli')}" loading="lazy" />
              </div>
              <div class="design-body">
                <h4>${escapeHtml(asset.title || 'Tasarım Görseli')}</h4>
                <p class="muted small">${escapeHtml(asset.description || 'Açıklama girilmedi.')}</p>
                <p class="muted small">Ekleyen: ${escapeHtml(asset.creator_name || '-')} • ${formatDate(asset.created_at)}</p>
                <div class="design-actions">
                  <button class="btn btn-ghost tiny" data-action="asset-like" data-asset-id="${asset.id}">${asset.is_liked ? 'Beğeniyi Kaldır' : 'Beğen'} (${asset.like_count || 0})</button>
                  <button class="btn btn-ghost tiny" data-action="asset-comments" data-asset-id="${asset.id}">Yorumlar (${asset.comment_count || 0})</button>
                  ${
                    asset.can_delete
                      ? `<button class="btn btn-ghost tiny btn-danger-text" data-action="asset-delete" data-asset-id="${asset.id}">Sil</button>`
                      : ''
                  }
                </div>
              </div>
            </article>
          `
        )
        .join('')
    : '<div class="empty-state"><h3>Tasarım yok</h3><p>Henüz görsel eklenmedi.</p></div>';

  return `
    <article class="card design-card-shell">
      <div class="card-head">
        <h3>Tasarım ve Görsel Alanı</h3>
        <span class="badge badge-neutral">${assets.length} görsel</span>
      </div>
      <section class="design-board">
        ${
          editable
            ? `
              <form id="design-upload-form" class="design-upload-form" data-entity-type="${entityType}" data-entity-id="${entityId}">
                <div class="design-upload-grid">
                  <label class="design-field design-field-title">Başlık
                    <input name="title" maxlength="180" placeholder="Örn: Mobil ana ekran v2" />
                  </label>
                  <label class="design-field design-field-url">Görsel URL
                    <input name="image_url" required placeholder="https://..." />
                  </label>
                  <label class="design-field design-field-description">Açıklama
                    <textarea name="description" rows="2" placeholder="Kısa not"></textarea>
                  </label>
                </div>
                <button class="btn btn-primary" type="submit">Görsel Ekle</button>
              </form>
            `
            : ''
        }
        <div class="design-grid">${cards}</div>
      </section>
    </article>
  `;
}

function renderOverview(data, comments, users, canManageMembers, currentUserId) {
  const project = data.project;
  const typeLabels = getProjectTypeLabels(project.types || project.type);

  const timeline = data.activity.length
    ? data.activity
        .slice(0, 12)
        .map((item) => {
          const row = formatActivity(item);
          return `
            <li>
              <strong>${escapeHtml(row.title)}</strong>
              <span>${escapeHtml(row.detail)}</span>
              <span class="muted small">${formatDate(item.created_at)}</span>
            </li>
          `;
        })
        .join('')
    : '<li class="muted">Bu proje için aktivite bulunmuyor.</li>';

  const commentsHtml = comments.length
    ? comments
        .map((comment) => {
          const canEdit = Number(comment.user_id) === Number(currentUserId) || canManageMembers;
          return `
            <li data-project-comment-id="${comment.id}">
              <div class="row between wrap">
                <strong>${escapeHtml(comment.user_name)}</strong>
                <span class="muted small">${formatDate(comment.created_at)}</span>
              </div>
              <p>${escapeHtml(comment.content)}</p>
              ${
                canEdit
                  ? `<div class="row gap-8"><button class="btn btn-ghost tiny" data-action="project-comment-edit" data-comment-id="${comment.id}">Düzenle</button><button class="btn btn-ghost tiny" data-action="project-comment-delete" data-comment-id="${comment.id}">Sil</button></div>`
                  : ''
              }
            </li>
          `;
        })
        .join('')
    : '<li class="muted">Henüz yorum yok.</li>';

  return `
    <section class="focus-layout">
      <div class="stack gap-12">
        ${renderMembersBoard(project, data.members, users, canManageMembers, currentUserId)}
        <article class="card">
          <div class="card-head"><h3>Proje Yorumları</h3></div>
          <ul class="list">${commentsHtml}</ul>
          <form id="project-comment-form" class="stack-form mt-16">
            <textarea name="content" rows="3" required placeholder="Yorum ekleyin"></textarea>
            <button type="submit" class="btn btn-primary">Yorum Gönder</button>
          </form>
        </article>
      </div>
      <aside class="stack gap-12 focus-side">
        <article class="card focus-meta-card">
          <div class="card-head">
            <h3>Proje Özeti</h3>
            <span class="badge badge-${getStatusTone(project.status)}">${escapeHtml(getStatusLabel(project.status))}</span>
          </div>
          <ul class="list">
            <li><div class="row between wrap"><strong>Tür</strong><span>${escapeHtml(typeLabels.join(', ') || '-')}</span></div></li>
            <li><div class="row between wrap"><strong>Öncelik</strong><span>${escapeHtml(getPriorityLabel(project.priority))}</span></div></li>
            <li><div class="row between wrap"><strong>Müşteri</strong><span>${escapeHtml(project.client || 'Belirtilmedi')}</span></div></li>
            <li><div class="row between wrap"><strong>Hedef Tarih</strong><span>${project.due_date ? formatDate(project.due_date, { withTime: false }) : 'Belirtilmedi'}</span></div></li>
            <li><div class="row between wrap"><strong>MVP</strong><span>${normalizeWorkItems(project.mvp || []).length} madde</span></div></li>
            <li><div class="row between wrap"><strong>Yapılacaklar</strong><span>${normalizeWorkItems(project.feature_todos || []).length} madde</span></div></li>
          </ul>
        </article>
        <article class="card">
          <div class="card-head">
            <h3>Son Hareketler</h3>
            <span class="badge badge-neutral">${data.activity.length}</span>
          </div>
          <ul class="list">${timeline}</ul>
        </article>
      </aside>
    </section>
  `;
}

function renderWorkTab(project, key, title, subtitle, editable) {
  const items = normalizeWorkItems(key === 'mvp' ? project.mvp || [] : project.feature_todos || []);
  const doneCount = items.filter((item) => item.done).length;
  const pendingCount = items.length - doneCount;
  const progress = items.length ? Math.round((doneCount / items.length) * 100) : 0;

  return `
    <section class="stack gap-12">
      <article class="card work-tab-summary">
        <div class="row between wrap">
          <div class="stack gap-8">
            <h3>${escapeHtml(title)}</h3>
            <p class="muted">${escapeHtml(subtitle)}</p>
          </div>
          <span class="badge badge-neutral">%${progress}</span>
        </div>
        <div class="focus-kpi-grid">
          <div class="focus-kpi"><strong>${items.length}</strong><span>Toplam</span></div>
          <div class="focus-kpi"><strong>${doneCount}</strong><span>Tamamlanan</span></div>
          <div class="focus-kpi"><strong>${pendingCount}</strong><span>Bekleyen</span></div>
        </div>
        <div class="focus-progress"><span style="width:${progress}%"></span></div>
      </article>
      <div class="stack gap-12">
        ${renderWorkItemsCard(title, key, items, editable)}
      </div>
    </section>
  `;
}

function renderDesignTab(project, designAssets, editable) {
  const totalLikes = designAssets.reduce((sum, item) => sum + Number(item.like_count || 0), 0);
  const totalComments = designAssets.reduce((sum, item) => sum + Number(item.comment_count || 0), 0);
  const latestAsset = designAssets[0] || null;

  return `
    <section class="focus-layout">
      <div class="stack gap-12">
        ${renderDesignBoard('project', project.id, designAssets, editable)}
      </div>
      <aside class="stack gap-12 focus-side">
        <article class="card focus-meta-card">
          <div class="card-head">
            <h3>Tasarım Özeti</h3>
            <span class="badge badge-neutral">${designAssets.length} görsel</span>
          </div>
          <div class="focus-kpi-grid">
            <div class="focus-kpi"><strong>${designAssets.length}</strong><span>Toplam Görsel</span></div>
            <div class="focus-kpi"><strong>${totalLikes}</strong><span>Toplam Beğeni</span></div>
            <div class="focus-kpi"><strong>${totalComments}</strong><span>Toplam Yorum</span></div>
          </div>
        </article>
        <article class="card focus-meta-card">
          <h4>Son Eklenen</h4>
          ${
            latestAsset
              ? `<p class="muted small"><strong>${escapeHtml(latestAsset.title || 'Başlıksız görsel')}</strong><br />${formatDate(latestAsset.created_at)}</p>`
              : '<p class="muted small">Henüz görsel eklenmedi.</p>'
          }
        </article>
      </aside>
    </section>
  `;
}

function renderStages(data, canManageStages) {
  const cards = data.stages.length
    ? data.stages
        .slice()
        .sort((a, b) => a.order_index - b.order_index || a.id - b.id)
        .map(
          (stage) => `
            <article class="card stage-card" data-stage-id="${stage.id}">
              <div class="row between top wrap">
                <div>
                  <h3>${escapeHtml(stage.name)}</h3>
                  <p class="muted small">Sıra: ${stage.order_index}</p>
                </div>
                <span class="badge badge-${getStatusTone(stage.status)}">${escapeHtml(getStatusLabel(stage.status))}</span>
              </div>
              <div class="inline-form mt-16">
                <select name="status" ${canManageStages ? '' : 'disabled'}>${statusOptions(STAGE_STATUS_OPTIONS, stage.status)}</select>
                <input name="est_hours" type="number" min="0" step="0.5" value="${stage.est_hours ?? ''}" placeholder="Tahmini saat" ${canManageStages ? '' : 'disabled'} />
                <input name="spent_hours" type="number" min="0" step="0.5" value="${stage.spent_hours ?? ''}" placeholder="Harcanan saat" ${canManageStages ? '' : 'disabled'} />
                ${canManageStages ? `<button class="btn btn-ghost" data-action="save-stage" data-stage-id="${stage.id}">Kaydet</button>` : ''}
                ${canManageStages ? `<button class="btn btn-ghost btn-danger-text" data-action="delete-stage" data-stage-id="${stage.id}">Sil</button>` : ''}
              </div>
            </article>
          `
        )
        .join('')
    : '<div class="empty-state"><h3>Aşama yok</h3><p>Bu proje için henüz aşama tanımlanmadı.</p></div>';

  return `
    <section class="row between wrap mb-12">
      <h2>Aşama Yönetimi</h2>
      ${canManageStages ? '<button class="btn btn-primary" id="new-stage-btn">Yeni Aşama</button>' : ''}
    </section>
    <section class="stage-grid">${cards}</section>
  `;
}

function getTaskStageColumns(data) {
  const orderedStages = [...data.stages].sort((a, b) => a.order_index - b.order_index || a.id - b.id);
  const columns = orderedStages.map((stage) => ({
    key: `stage-${stage.id}`,
    label: stage.name,
    stageId: Number(stage.id),
    stageStatus: stage.status,
  }));

  columns.push({
    key: 'stage-unassigned',
    label: 'Aşamasız',
    stageId: null,
    stageStatus: 'backlog',
  });

  return columns;
}

function renderKanbanTopbar(data, users, canManageMembers, canManageStages) {
  const stagePills = data.stages
    .slice()
    .sort((a, b) => a.order_index - b.order_index || a.id - b.id)
    .map((stage) => {
      const assigneePills = (stage.assignees || [])
        .map((assigneeId) => users.find((user) => Number(user.id) === Number(assigneeId)))
        .filter(Boolean)
        .slice(0, 2)
        .map((assignee) => `<span class="avatar-dot">${escapeHtml(initials(assignee.name))}</span>`)
        .join('');

      return `
        <div class="flow-pill">
          <span class="flow-pill-name">${escapeHtml(stage.name)}</span>
          <span class="badge badge-${getStatusTone(stage.status)}">${escapeHtml(getStatusLabel(stage.status))}</span>
          <span class="flow-pill-avatars">${assigneePills || '<span class="avatar-dot avatar-dot-muted">+</span>'}</span>
        </div>
      `;
    })
    .join('');

  const avatars = data.members
    .slice(0, 5)
    .map((member) => `<span class="avatar-pill avatar-pill-sm">${escapeHtml(initials(member.name))}</span>`)
    .join('');
  const remaining = data.members.length > 5 ? `<span class="avatar-pill avatar-pill-sm avatar-pill-muted">+${data.members.length - 5}</span>` : '';

  return `
    <article class="card kanban-header-card">
      <div class="stack gap-12">
        <div class="row between wrap">
          <h3>Görev Panosu</h3>
          <div class="row gap-8 wrap">
            ${canManageStages ? '<button class="btn btn-ghost tiny" data-action="open-stage-modal">Durum Ekle</button>' : ''}
            ${canManageMembers ? '<button class="btn btn-ghost tiny" data-action="open-add-member-modal">Kişi Ekle</button>' : ''}
          </div>
        </div>
        <div class="row gap-8 wrap">${avatars}${remaining || ''}</div>
        <div class="kanban-flow-row">${stagePills || '<span class="muted small">Aşama bulunamadı.</span>'}</div>
      </div>
    </article>
  `;
}

function renderTaskCard(task, users, stages) {
  const assignee = users.find((user) => Number(user.id) === Number(task.assignee_id));
  const commentsCount = Number(task.comment_count || 0);

  return `
    <article class="task-card task-card-classic" data-task-id="${task.id}">
      <div class="task-tags">
        <span class="task-tag task-tag-priority task-tag-priority-${escapeHtml(task.priority)}">${escapeHtml(getPriorityLabel(task.priority))}</span>
        <span class="badge badge-${getStatusTone(task.status)}">${escapeHtml(getStatusLabel(task.status))}</span>
      </div>
      <h4>${escapeHtml(task.title)}</h4>
      <p class="muted small">${escapeHtml(task.description || 'Açıklama eklenmemiş.')}</p>
      <div class="task-date-line">📅 ${task.due_date ? formatDate(task.due_date, { withTime: false }) : 'Tarih yok'}</div>
      <div class="task-bottom-line">
        <div class="row gap-8">
          <span class="avatar-dot">${escapeHtml(initials(assignee?.name || 'Atama'))}</span>
          <button class="task-link-btn" data-action="task-comments" data-task-id="${task.id}">💬 ${commentsCount} yorum</button>
        </div>
      </div>
      <div class="task-inline-controls task-inline-controls-3">
        <select class="compact" data-action="task-status" data-task-id="${task.id}">
          ${statusOptions(TASK_STATUS_OPTIONS, task.status)}
        </select>
        <select class="compact" data-action="task-assignee" data-task-id="${task.id}">
          ${assigneeOptions(users, task.assignee_id)}
        </select>
        <select class="compact" data-action="task-stage" data-task-id="${task.id}">
          ${taskStageOptions(stages, task.stage_id)}
        </select>
      </div>
    </article>
  `;
}

function renderTasks(data, users, canManageMembers, canManageStages) {
  const columns = getTaskStageColumns(data)
    .map((column) => {
      const tasks = data.tasks.filter((task) => {
        if (column.stageId === null) {
          return task.stage_id === null;
        }

        return Number(task.stage_id) === Number(column.stageId);
      });

      return `
      <section class="kanban-col kanban-col-classic">
        <div class="kanban-col-head">
          <h3>${escapeHtml(column.label)}</h3>
          <button class="btn btn-ghost tiny" data-action="lane-add-task" data-stage-id="${column.stageId ?? ''}">Yeni</button>
        </div>
        <div class="kanban-list">
          ${tasks.length ? tasks.map((task) => renderTaskCard(task, users, data.stages)).join('') : '<div class="kanban-empty">Görev yok</div>'}
          <button class="kanban-add-task-btn" data-action="lane-add-task" data-stage-id="${column.stageId ?? ''}">Yeni Görev Ekle</button>
        </div>
      </section>
    `;
    })
    .join('');

  return `
    <section class="stack gap-12">
      ${renderKanbanTopbar(data, users, canManageMembers, canManageStages)}
      <section class="kanban-board kanban-board-stageflow">${columns}</section>
    </section>
  `;
}

function renderActivity(data) {
  return `
    <article class="card">
      <div class="card-head">
        <h3>Proje Aktivite Kaydı</h3>
        <span class="badge badge-neutral">${data.activity.length}</span>
      </div>
      <ul class="list">
        ${
          data.activity.length
            ? data.activity
                .map((item) => {
                  const row = formatActivity(item);
                  return `
                    <li>
                      <strong>${escapeHtml(row.title)}</strong>
                      <span>${escapeHtml(row.detail)}</span>
                      <span class="muted small">${formatDate(item.created_at)}</span>
                    </li>
                  `;
                })
                .join('')
            : '<li class="muted">Aktivite bulunamadı.</li>'
        }
      </ul>
    </article>
  `;
}

function tabLink(projectId, tab, label, activeTab) {
  return `<a class="tab-link ${activeTab === tab ? 'active' : ''}" href="#/projects/${projectId}?tab=${tab}">${label}</a>`;
}

function renderTabs(projectId, activeTab) {
  return `
    <section class="tabs detail-tabs">
      ${DETAIL_TABS.map((tab) => tabLink(projectId, tab.key, tab.label, activeTab)).join('')}
    </section>
    <p class="muted small detail-tab-note">${escapeHtml((DETAIL_TABS.find((tab) => tab.key === activeTab) || DETAIL_TABS[0]).note)}</p>
  `;
}

export async function render(ctx) {
  if (!ctx.state.user) {
    ctx.router.navigate('/login');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  const projectId = Number(ctx.params.id);
  const activeTab = normalizeTab(ctx.query.tab || 'genel');

  return {
    title: 'Proje Detayı',
    html: `
      <section id="project-detail-header" class="stack gap-12">
        <div class="card">${skeleton(6)}</div>
      </section>

      ${renderTabs(projectId, activeTab)}

      <section id="project-detail-content" class="stack gap-12">
        <div class="card">${skeleton(8)}</div>
      </section>
    `,
    async onMount() {
      const tabConfig = DETAIL_TAB_CONFIG[activeTab];
      const canManageStages = ctx.state.user.role === 'admin';
      const headerRoot = qs('#project-detail-header');
      const contentRoot = qs('#project-detail-content');

      let users = ctx.state.users || [];
      let data = {
        project: null,
        members: [],
        stages: [],
        tasks: [],
        activity: [],
      };
      let comments = [];
      let designAssets = [];
      let canManageMembers = false;
      let canEditProject = false;
      let canDeleteProject = false;

      const loadTabData = async ({ includeComments = tabConfig.needsComments } = {}) => {
        const projectQuery = { include: tabConfig.include };
        if (tabConfig.activityLimit) {
          projectQuery.activity_limit = tabConfig.activityLimit;
        }
        if (tabConfig.taskLimit) {
          projectQuery.task_limit = tabConfig.taskLimit;
        }

        const requests = [api.get(`/projects/${projectId}`, projectQuery)];
        const requestKeys = ['project'];

        if (tabConfig.needsUsers && !users.length) {
          requests.push(api.get('/users'));
          requestKeys.push('users');
        }

        if (tabConfig.needsComments && includeComments) {
          requests.push(
            api.get('/comments', {
              entity_type: 'project',
              entity_id: projectId,
            })
          );
          requestKeys.push('comments');
        }

        if (tabConfig.needsDesignAssets) {
          requests.push(
            api.get('/design-assets', {
              entity_type: 'project',
              entity_id: projectId,
            })
          );
          requestKeys.push('design_assets');
        }

        const responses = await Promise.all(requests);
        const result = {};
        requestKeys.forEach((key, index) => {
          result[key] = responses[index];
        });

        const projectData = result.project;
        data = {
          project: projectData.project,
          members: tabConfig.includeMembers ? (projectData.members || []) : [],
          stages: tabConfig.includeStages ? (projectData.stages || []) : [],
          tasks: tabConfig.includeTasks ? (projectData.tasks || []) : [],
          activity: tabConfig.includeActivity ? (projectData.activity || []) : [],
        };

        if (tabConfig.needsUsers && result.users?.users) {
          users = result.users.users;
          setState({ users });
        }

        if (tabConfig.needsComments) {
          comments = includeComments ? (result.comments?.comments || []) : comments;
        } else {
          comments = [];
        }

        if (tabConfig.needsDesignAssets) {
          designAssets = result.design_assets?.assets || [];
        } else {
          designAssets = [];
        }

        const isOwner = Number(data.project.created_by) === Number(ctx.state.user.id);
        canManageMembers = ctx.state.user.role === 'admin' || isOwner;
        canEditProject = ctx.state.user.role === 'admin' || isOwner;
        canDeleteProject = ctx.state.user.role === 'admin' || isOwner;
      };

      const renderCurrentTab = () => {
        headerRoot.innerHTML = renderHeader(data.project, {
          canEditProject,
          canDeleteProject,
        });

        if (activeTab === 'asamalar') {
          contentRoot.innerHTML = renderStages(data, canManageStages);
          bindStageEvents();
        } else if (activeTab === 'gorevler') {
          contentRoot.innerHTML = renderTasks(data, users, canManageMembers, canManageStages);
          bindTaskEvents();
        } else if (activeTab === 'mvp') {
          contentRoot.innerHTML = renderWorkTab(
            data.project,
            'mvp',
            'MVP Listesi',
            'Canlıya çıkacak minimum değerli ve zorunlu teslim maddeleri.',
            canEditProject
          );
          bindOverviewEvents();
        } else if (activeTab === 'yapilacaklar') {
          contentRoot.innerHTML = renderWorkTab(
            data.project,
            'feature_todos',
            'Yapılacak Özellikler',
            'MVP sonrasında planlanan geliştirmeler ve iyileştirme kalemleri.',
            canEditProject
          );
          bindOverviewEvents();
        } else if (activeTab === 'tasarim') {
          contentRoot.innerHTML = renderDesignTab(data.project, designAssets, canEditProject || canManageMembers);
          bindOverviewEvents();
        } else if (activeTab === 'aktivite') {
          contentRoot.innerHTML = renderActivity(data);
        } else {
          contentRoot.innerHTML = renderOverview(data, comments, users, canManageMembers, ctx.state.user.id);
          bindOverviewEvents();
        }

        bindHeaderEvents();
      };

      const reloadComments = async () => {
        if (!tabConfig.needsComments) {
          comments = [];
          return;
        }

        const commentsData = await api.get('/comments', {
          entity_type: 'project',
          entity_id: projectId,
        });
        comments = commentsData.comments || [];
      };

      const openProjectEditModal = () => {
        if (!canEditProject) {
          return;
        }

        openModal({
          title: 'Projeyi Düzenle',
          confirmText: 'Güncelle',
          content: `
            <form id="project-edit-form" class="stack-form">
              <label>Proje adı
                <input name="title" required minlength="2" maxlength="180" value="${escapeHtml(data.project.title || '')}" />
              </label>
              <label>Açıklama
                <textarea name="description" rows="3">${escapeHtml(data.project.description || '')}</textarea>
              </label>
              <label>Tür(ler)
                <div class="check-grid">
                  ${projectTypeChecklist(data.project.types || data.project.type)}
                </div>
              </label>
              <div class="inline-form">
                <label>Durum
                  <select name="status">${statusOptions(PROJECT_STATUS_OPTIONS, data.project.status)}</select>
                </label>
                <label>Öncelik
                  <select name="priority">${projectPriorityOptions(data.project.priority || 'med')}</select>
                </label>
              </div>
              <div class="inline-form">
                <label>Müşteri
                  <input name="client" maxlength="180" value="${escapeHtml(data.project.client || '')}" />
                </label>
                <label>Hedef tarih
                  <input name="due_date" type="date" value="${escapeHtml(data.project.due_date || '')}" />
                </label>
              </div>
              <label>Etiketler (virgülle)
                <input name="tags" value="${escapeHtml((data.project.tags || []).join(', '))}" />
              </label>
            </form>
          `,
          onConfirm: async () => {
            const form = qs('#project-edit-form');
            if (!form) return;

            const payload = formToObject(form);
            payload.tags = String(payload.tags || '')
              .split(',')
              .map((item) => item.trim())
              .filter(Boolean);
            payload.types = collectSelected(form, 'types');
            payload.due_date = payload.due_date || '';

            try {
              const response = await api.patch(`/projects/${projectId}`, payload);
              data.project = {
                ...data.project,
                ...response.project,
              };
              closeModal();
              renderCurrentTab();
              showToast({ type: 'success', message: 'Proje güncellendi.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Proje güncellenemedi.' });
            }
          },
        });
      };

      const openProjectDeleteModal = () => {
        if (!canDeleteProject) {
          return;
        }

        openModal({
          title: 'Projeyi Sil',
          confirmText: 'Evet, Sil',
          content: `
            <div class="stack gap-8">
              <p><strong>${escapeHtml(data.project.title)}</strong> projesi kalıcı olarak silinecek.</p>
              <p class="muted small">Bu işlem geri alınamaz.</p>
            </div>
          `,
          onConfirm: async () => {
            try {
              await api.delete(`/projects/${projectId}`);
              closeModal();
              showToast({ type: 'success', message: 'Proje silindi.' });
              ctx.router.navigate('/projects');
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Proje silinemedi.' });
            }
          },
        });
      };

      const openStageCreateModal = () => {
        const options = stageTitleSelect(data.stages);
        if (!options) {
          showToast({ type: 'info', message: 'Tüm hazır aşama başlıkları zaten kullanılıyor.' });
          return;
        }

        openModal({
          title: 'Yeni Aşama Ekle',
          confirmText: 'Aşamayı Kaydet',
          content: `
            <form id="stage-create-form" class="stack-form">
              <label>Aşama başlığı
                <select name="name" required>
                  ${options}
                </select>
              </label>
              <label>Sıra
                <input name="order_index" type="number" min="0" value="${data.stages.length}" />
              </label>
              <label>Durum
                <select name="status">${statusOptions(STAGE_STATUS_OPTIONS, 'baslanmadi')}</select>
              </label>
            </form>
          `,
          onConfirm: async () => {
            const form = qs('#stage-create-form');
            if (!form) return;

            try {
              const payload = formToObject(form);
              payload.order_index = Number(payload.order_index || data.stages.length);
              const result = await api.post(`/projects/${projectId}/stages`, payload);
              if (result.stage) {
                data.stages = [...data.stages, result.stage].sort((a, b) => a.order_index - b.order_index || a.id - b.id);
              }
              closeModal();
              renderCurrentTab();
              showToast({ type: 'success', message: 'Aşama eklendi.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Aşama eklenemedi.' });
            }
          },
        });
      };

      const bindHeaderEvents = () => {
        qs('#project-status-select')?.addEventListener('change', async (event) => {
          if (!canEditProject) {
            return;
          }

          const newStatus = event.currentTarget.value;
          const previousStatus = data.project.status;
          data.project.status = newStatus;

          try {
            await api.patch(`/projects/${projectId}`, { status: newStatus });
            showToast({ type: 'success', message: 'Proje durumu güncellendi.' });
          } catch (error) {
            data.project.status = previousStatus;
            showToast({ type: 'error', message: error.message || 'Durum güncellenemedi.' });
          }

          renderCurrentTab();
        });

        qs('[data-action="project-edit"]')?.addEventListener('click', () => {
          openProjectEditModal();
        });

        qs('[data-action="project-delete"]')?.addEventListener('click', () => {
          openProjectDeleteModal();
        });
      };

      const bindOverviewEvents = () => {
        const persistWorkItems = async (nextMvp, nextTodos) => {
          const response = await api.patch(`/projects/${projectId}`, {
            mvp: nextMvp,
            feature_todos: nextTodos,
          });
          data.project = {
            ...data.project,
            ...response.project,
          };
        };

        const getWorkLists = () => ({
          mvp: normalizeWorkItems(data.project.mvp || []),
          feature_todos: normalizeWorkItems(data.project.feature_todos || []),
        });

        qsa('[data-action="work-toggle"]').forEach((checkbox) => {
          checkbox.addEventListener('change', async () => {
            if (!canEditProject) return;
            const key = checkbox.dataset.key;
            const index = Number(checkbox.dataset.index);
            const lists = getWorkLists();
            const target = key === 'mvp' ? lists.mvp : lists.feature_todos;
            if (!target[index]) return;
            target[index].done = checkbox.checked;

            try {
              await persistWorkItems(lists.mvp, lists.feature_todos);
              renderCurrentTab();
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Liste güncellenemedi.' });
            }
          });
        });

        qsa('[data-action="work-remove"]').forEach((button) => {
          button.addEventListener('click', async () => {
            if (!canEditProject) return;
            const key = button.dataset.key;
            const index = Number(button.dataset.index);
            const lists = getWorkLists();
            const target = key === 'mvp' ? lists.mvp : lists.feature_todos;
            if (!target[index]) return;
            target.splice(index, 1);

            try {
              await persistWorkItems(lists.mvp, lists.feature_todos);
              renderCurrentTab();
              showToast({ type: 'success', message: 'Madde kaldırıldı.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Madde kaldırılamadı.' });
            }
          });
        });

        qsa('[data-action="work-add-form"]').forEach((form) => {
          form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!canEditProject) return;
            const payload = formToObject(form);
            const text = String(payload.text || '').trim();
            if (!text) return;

            const key = form.dataset.key;
            const lists = getWorkLists();
            const target = key === 'mvp' ? lists.mvp : lists.feature_todos;
            target.push({ text, done: false });

            try {
              await persistWorkItems(lists.mvp, lists.feature_todos);
              renderCurrentTab();
              showToast({ type: 'success', message: 'Madde eklendi.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Madde eklenemedi.' });
            }
          });
        });

        qs('#design-upload-form')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const payload = formToObject(event.currentTarget);
          if (!payload.image_url) {
            showToast({ type: 'error', message: 'Görsel URL alanı zorunlu.' });
            return;
          }

          try {
            await api.post('/design-assets', {
              entity_type: 'project',
              entity_id: projectId,
              title: payload.title,
              image_url: payload.image_url,
              description: payload.description,
            });
            const assetsData = await api.get('/design-assets', { entity_type: 'project', entity_id: projectId });
            designAssets = assetsData.assets || [];
            renderCurrentTab();
            showToast({ type: 'success', message: 'Tasarım görseli eklendi.' });
          } catch (error) {
            showToast({ type: 'error', message: error.message || 'Görsel eklenemedi.' });
          }
        });

        qsa('[data-action="asset-like"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const assetId = Number(button.dataset.assetId);
            try {
              await api.post(`/design-assets/${assetId}/like`, {});
              const assetsData = await api.get('/design-assets', { entity_type: 'project', entity_id: projectId });
              designAssets = assetsData.assets || [];
              renderCurrentTab();
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Beğeni işlemi başarısız.' });
            }
          });
        });

        qsa('[data-action="asset-delete"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const assetId = Number(button.dataset.assetId);
            try {
              await api.delete(`/design-assets/${assetId}`);
              const assetsData = await api.get('/design-assets', { entity_type: 'project', entity_id: projectId });
              designAssets = assetsData.assets || [];
              renderCurrentTab();
              showToast({ type: 'success', message: 'Görsel silindi.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Görsel silinemedi.' });
            }
          });
        });

        qsa('[data-action="asset-comments"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const assetId = Number(button.dataset.assetId);
            const asset = designAssets.find((item) => Number(item.id) === assetId);
            if (!asset) return;

            const commentsData = await api.get('/comments', { entity_type: 'design_asset', entity_id: assetId });
            const assetComments = commentsData.comments || [];

            openModal({
              title: `Yorumlar • ${escapeHtml(asset.title || 'Tasarım')}`,
              confirmText: 'Yorum Ekle',
              content: `
                <div class="stack gap-12">
                  <ul class="list">
                    ${
                      assetComments.length
                        ? assetComments
                            .map((comment) => `<li><strong>${escapeHtml(comment.user_name)}</strong><p>${escapeHtml(comment.content)}</p></li>`)
                            .join('')
                        : '<li class="muted">Henüz yorum yok.</li>'
                    }
                  </ul>
                  <textarea id="design-comment-input" rows="3" placeholder="Yorum yaz"></textarea>
                </div>
              `,
              onConfirm: async () => {
                const input = qs('#design-comment-input');
                const text = String(input?.value || '').trim();
                if (!text) {
                  showToast({ type: 'error', message: 'Yorum boş olamaz.' });
                  return;
                }

                await api.post('/comments', {
                  entity_type: 'design_asset',
                  entity_id: assetId,
                  content: text,
                });

                closeModal();
                const assetsData = await api.get('/design-assets', { entity_type: 'project', entity_id: projectId });
                designAssets = assetsData.assets || [];
                renderCurrentTab();
                showToast({ type: 'success', message: 'Yorum eklendi.' });
              },
            });
          });
        });

        qs('#project-comment-form')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const payload = formToObject(event.currentTarget);

          try {
            await api.post('/comments', {
              entity_type: 'project',
              entity_id: projectId,
              content: payload.content,
            });
            await reloadComments();
            renderCurrentTab();
            showToast({ type: 'success', message: 'Yorum eklendi.' });
          } catch (error) {
            showToast({ type: 'error', message: error.message || 'Yorum eklenemedi.' });
          }
        });

        qsa('[data-action="project-comment-edit"]').forEach((button) => {
          button.addEventListener('click', () => {
            const commentId = Number(button.dataset.commentId);
            const existing = comments.find((comment) => Number(comment.id) === commentId);
            if (!existing) return;

            openModal({
              title: 'Yorumu Düzenle',
              confirmText: 'Kaydet',
              content: `<textarea id="project-comment-edit-input" rows="4">${escapeHtml(existing.content)}</textarea>`,
              onConfirm: async () => {
                const input = qs('#project-comment-edit-input');
                if (!input?.value?.trim()) {
                  showToast({ type: 'error', message: 'Yorum boş olamaz.' });
                  return;
                }

                try {
                  await api.patch(`/comments/${commentId}`, { content: input.value.trim() });
                  await reloadComments();
                  closeModal();
                  renderCurrentTab();
                  showToast({ type: 'success', message: 'Yorum güncellendi.' });
                } catch (error) {
                  showToast({ type: 'error', message: error.message || 'Yorum güncellenemedi.' });
                }
              },
            });
          });
        });

        qsa('[data-action="project-comment-delete"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const commentId = Number(button.dataset.commentId);
            try {
              await api.delete(`/comments/${commentId}`);
              await reloadComments();
              renderCurrentTab();
              showToast({ type: 'success', message: 'Yorum silindi.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Yorum silinemedi.' });
            }
          });
        });

        qsa('[data-action="member-status"]').forEach((select) => {
          select.addEventListener('change', async () => {
            const userId = Number(select.dataset.userId);
            const newStatus = select.value;

            try {
              const response = await api.patch(`/projects/${projectId}/members/${userId}`, { member_status: newStatus });
              data.members = response.members || data.members;
              renderCurrentTab();
              showToast({ type: 'success', message: 'Ekip üyesi durumu güncellendi.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Üye durumu güncellenemedi.' });
            }
          });
        });

        qsa('[data-action="member-remove"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const userId = Number(button.dataset.userId);
            try {
              const response = await api.delete(`/projects/${projectId}/members/${userId}`);
              data.members = response.members || data.members;
              renderCurrentTab();
              showToast({ type: 'success', message: 'Üye projeden çıkarıldı.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Üye çıkarılamadı.' });
            }
          });
        });

        qs('#project-add-member-form')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const payload = formToObject(event.currentTarget);
          if (!payload.user_id) {
            showToast({ type: 'error', message: 'Eklenecek kullanıcıyı seçin.' });
            return;
          }

          try {
            const response = await api.post(`/projects/${projectId}/members`, {
              member_ids: [Number(payload.user_id)],
              member_status: payload.member_status,
            });
            data.members = response.members || data.members;
            renderCurrentTab();
            showToast({ type: 'success', message: 'Yeni ekip üyesi eklendi.' });
          } catch (error) {
            showToast({ type: 'error', message: error.message || 'Üye eklenemedi.' });
          }
        });
      };

      const bindStageEvents = () => {
        qsa('[data-action="save-stage"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const stageId = Number(button.dataset.stageId);
            const card = button.closest('.stage-card');
            const payload = {
              status: qs('select[name="status"]', card)?.value,
              est_hours: qs('input[name="est_hours"]', card)?.value || null,
              spent_hours: qs('input[name="spent_hours"]', card)?.value || null,
            };

            try {
              const response = await api.patch(`/stages/${stageId}`, payload);
              const updated = response.stage;
              if (updated) {
                data.stages = data.stages.map((stage) => (Number(stage.id) === Number(stageId) ? updated : stage));
              }
              renderCurrentTab();
              showToast({ type: 'success', message: 'Aşama güncellendi.' });
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Aşama güncellenemedi.' });
            }
          });
        });

        qsa('[data-action="delete-stage"]').forEach((button) => {
          button.addEventListener('click', () => {
            const stageId = Number(button.dataset.stageId);
            const stage = data.stages.find((item) => Number(item.id) === stageId);
            if (!stage) {
              return;
            }

            openModal({
              title: 'Aşamayı Sil',
              confirmText: 'Sil',
              content: `<p><strong>${escapeHtml(stage.name)}</strong> aşaması silinecek. Bu aşamadaki görevler "Aşamasız" olur.</p>`,
              onConfirm: async () => {
                try {
                  await api.delete(`/stages/${stageId}`);
                  data.stages = data.stages.filter((item) => Number(item.id) !== stageId);
                  data.tasks = data.tasks.map((task) => {
                    if (Number(task.stage_id) === stageId) {
                      return {
                        ...task,
                        stage_id: null,
                      };
                    }
                    return task;
                  });
                  closeModal();
                  renderCurrentTab();
                  showToast({ type: 'success', message: 'Aşama silindi.' });
                } catch (error) {
                  showToast({ type: 'error', message: error.message || 'Aşama silinemedi.' });
                }
              },
            });
          });
        });

        qs('#new-stage-btn')?.addEventListener('click', () => {
          openStageCreateModal();
        });
      };

      const openTaskComments = async (taskId) => {
        const task = data.tasks.find((item) => Number(item.id) === Number(taskId));
        if (!task) return;

        const commentsData = await api.get('/comments', { entity_type: 'task', entity_id: taskId });
        const taskComments = commentsData.comments || [];

        openModal({
          title: `Yorumlar • ${escapeHtml(task.title)}`,
          confirmText: 'Yorum Ekle',
          content: `
            <div class="stack gap-12">
              <ul class="list">
                ${
                  taskComments.length
                    ? taskComments.map((comment) => `<li><strong>${escapeHtml(comment.user_name)}</strong><p>${escapeHtml(comment.content)}</p></li>`).join('')
                    : '<li class="muted">Henüz yorum yok.</li>'
                }
              </ul>
              <textarea id="task-comment-input" rows="3" placeholder="Yorum yaz"></textarea>
            </div>
          `,
          onConfirm: async () => {
            const input = qs('#task-comment-input');
            if (!input?.value?.trim()) {
              showToast({ type: 'error', message: 'Yorum boş olamaz.' });
              return;
            }

            await api.post('/comments', {
              entity_type: 'task',
              entity_id: taskId,
              content: input.value.trim(),
            });
            closeModal();
            showToast({ type: 'success', message: 'Yorum eklendi.' });
          },
        });
      };

      const bindTaskEvents = () => {
        const openTaskCreateModal = (defaultStageId = null) => {
          const stage = data.stages.find((item) => Number(item.id) === Number(defaultStageId));
          const defaultStatus = taskDefaultStatusForStage(stage);

          openModal({
            title: `Yeni Görev Ekle${stage ? ` • ${escapeHtml(stage.name)}` : ''}`,
            confirmText: 'Görevi Oluştur',
            content: `
              <form id="task-create-modal-form" class="stack-form">
                <label>Görev başlığı
                  <input name="title" required minlength="2" />
                </label>
                <label>Açıklama
                  <textarea name="description" rows="3"></textarea>
                </label>
                <div class="inline-form">
                  <label>Aşama
                    <select name="stage_id">
                      ${taskStageOptions(data.stages, defaultStageId)}
                    </select>
                  </label>
                  <label>Atanan kişi
                    <select name="assignee_id">
                      ${assigneeOptions(users)}
                    </select>
                  </label>
                </div>
                <div class="inline-form">
                  <label>Durum
                    <select name="status">
                      ${statusOptions(TASK_STATUS_OPTIONS, defaultStatus)}
                    </select>
                  </label>
                  <label>Öncelik
                    <select name="priority">
                      <option value="low">Düşük</option>
                      <option value="med" selected>Orta</option>
                      <option value="high">Yüksek</option>
                    </select>
                  </label>
                  <label>Teslim tarihi
                    <input name="due_date" type="date" />
                  </label>
                </div>
              </form>
            `,
            onConfirm: async () => {
              const form = qs('#task-create-modal-form');
              if (!form) return;

              const payload = formToObject(form);
              payload.assignee_id = parseNullableId(payload.assignee_id);
              payload.stage_id = parseNullableId(payload.stage_id);

              try {
                const result = await api.post(`/projects/${projectId}/tasks`, payload);
                if (result.task) {
                  data.tasks.push(result.task);
                }
                closeModal();
                renderCurrentTab();
                showToast({ type: 'success', message: 'Görev eklendi.' });
              } catch (error) {
                showToast({ type: 'error', message: error.message || 'Görev eklenemedi.' });
              }
            },
          });
        };

        qsa('[data-action="lane-add-task"]').forEach((button) => {
          button.addEventListener('click', () => {
            openTaskCreateModal(parseNullableId(button.dataset.stageId));
          });
        });

        qs('[data-action="open-stage-modal"]')?.addEventListener('click', () => {
          openStageCreateModal();
        });

        qs('[data-action="open-add-member-modal"]')?.addEventListener('click', () => {
          const addableUsers = users.filter((user) => !data.members.some((member) => Number(member.id) === Number(user.id)));

          if (!addableUsers.length) {
            showToast({ type: 'info', message: 'Eklenebilecek kullanıcı kalmadı.' });
            return;
          }

          openModal({
            title: 'Projeye Kişi Ekle',
            confirmText: 'Ekle',
            content: `
              <form id="member-add-inline-form" class="stack-form">
                <label>Kullanıcı
                  <select name="user_id" required>
                    <option value="">Kişi seç</option>
                    ${addableUsers.map((user) => `<option value="${user.id}">${escapeHtml(user.name)} (${escapeHtml(user.role)})</option>`).join('')}
                  </select>
                </label>
                <label>Durum
                  <select name="member_status">${statusOptions(PROJECT_STATUS_OPTIONS, 'baslaniyor')}</select>
                </label>
              </form>
            `,
            onConfirm: async () => {
              const form = qs('#member-add-inline-form');
              if (!form) return;
              const payload = formToObject(form);
              if (!payload.user_id) {
                showToast({ type: 'error', message: 'Kullanıcı seçin.' });
                return;
              }

              try {
                const response = await api.post(`/projects/${projectId}/members`, {
                  member_ids: [Number(payload.user_id)],
                  member_status: payload.member_status,
                });
                data.members = response.members || data.members;
                closeModal();
                renderCurrentTab();
                showToast({ type: 'success', message: 'Kişi projeye eklendi.' });
              } catch (error) {
                showToast({ type: 'error', message: error.message || 'Kişi eklenemedi.' });
              }
            },
          });
        });

        qsa('[data-action="task-status"]').forEach((select) => {
          select.addEventListener('change', async () => {
            const taskId = Number(select.dataset.taskId);
            const task = data.tasks.find((item) => Number(item.id) === taskId);
            if (!task) return;

            const previousStatus = task.status;
            const nextStatus = select.value;

            try {
              await api.patch(`/tasks/${taskId}`, { status: nextStatus });
              task.status = nextStatus;
              showToast({ type: 'success', message: 'Görev durumu güncellendi.' });
            } catch (error) {
              select.value = previousStatus;
              showToast({ type: 'error', message: error.message || 'Görev güncellenemedi.' });
            }
          });
        });

        qsa('[data-action="task-assignee"]').forEach((select) => {
          select.addEventListener('change', async () => {
            const taskId = Number(select.dataset.taskId);
            const task = data.tasks.find((item) => Number(item.id) === taskId);
            if (!task) return;

            const previousAssignee = task.assignee_id;
            const nextAssignee = parseNullableId(select.value);

            try {
              await api.patch(`/tasks/${taskId}`, { assignee_id: nextAssignee });
              task.assignee_id = nextAssignee;
              showToast({ type: 'success', message: 'Atama güncellendi.' });
            } catch (error) {
              task.assignee_id = previousAssignee;
              select.value = previousAssignee ? String(previousAssignee) : '';
              showToast({ type: 'error', message: error.message || 'Atama güncellenemedi.' });
            }
          });
        });

        qsa('[data-action="task-stage"]').forEach((select) => {
          select.addEventListener('change', async () => {
            const taskId = Number(select.dataset.taskId);
            const task = data.tasks.find((item) => Number(item.id) === taskId);
            if (!task) return;

            const previousStage = task.stage_id;
            const nextStage = parseNullableId(select.value);

            try {
              await api.patch(`/tasks/${taskId}`, { stage_id: nextStage });
              task.stage_id = nextStage;
              renderCurrentTab();
              showToast({ type: 'success', message: 'Görev aşaması güncellendi.' });
            } catch (error) {
              task.stage_id = previousStage;
              select.value = previousStage !== null ? String(previousStage) : '';
              showToast({ type: 'error', message: error.message || 'Aşama güncellenemedi.' });
            }
          });
        });

        qsa('[data-action="task-comments"]').forEach((button) => {
          button.addEventListener('click', async () => {
            await openTaskComments(Number(button.dataset.taskId));
          });
        });
      };

      try {
        await loadTabData();
        renderCurrentTab();
      } catch (error) {
        contentRoot.innerHTML = `
          <div class="empty-state">
            <h3>Proje verileri yüklenemedi</h3>
            <p>${escapeHtml(error.message || 'Lütfen tekrar deneyin.')}</p>
          </div>
        `;
      }
    },
  };
}
