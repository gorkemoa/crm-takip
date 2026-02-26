import { api } from '../utils/api.js';
import { escapeHtml, formToObject, qs, qsa } from '../utils/dom.js';
import { formatDate } from '../utils/date.js';
import { showToast } from '../components/toast.js';
import { openModal, closeModal } from '../components/modal.js';
import { skeleton } from '../components/ui.js';
import {
  IDEA_STATUS_OPTIONS,
  IDEA_CATEGORY_OPTIONS,
  getIdeaStatusLabel,
  getIdeaStatusTone,
  getIdeaCategoryLabels,
  ideaStatusOptionTemplate,
  ideaCategoryChecklistTemplate,
} from '../utils/ideas.js';
import { formatActivity } from '../utils/activity.js';

const IDEA_DETAIL_TABS = [
  { key: 'genel', label: 'Genel Bakış', note: 'Fikir özeti ve temel detaylar' },
  { key: 'mvp', label: 'MVP', note: 'İlk sürüme girecek temel maddeler' },
  { key: 'yapilacaklar', label: 'Yapılacaklar', note: 'MVP sonrası geliştirme adımları' },
  { key: 'tasarim', label: 'Tasarım', note: 'Görseller, beğeni ve tasarım yorumları' },
  { key: 'yorumlar', label: 'Yorumlar', note: 'Ekip notları ve tartışmalar' },
  { key: 'aktivite', label: 'Aktivite', note: 'Fikir üzerinde yapılan işlemler' },
];

const IDEA_TAB_CONFIG = {
  genel: {
    include: 'none',
    needsComments: false,
    needsActivity: false,
    needsDesignAssets: false,
  },
  mvp: {
    include: 'none',
    needsComments: false,
    needsActivity: false,
    needsDesignAssets: false,
  },
  yapilacaklar: {
    include: 'none',
    needsComments: false,
    needsActivity: false,
    needsDesignAssets: false,
  },
  tasarim: {
    include: 'none',
    needsComments: false,
    needsActivity: false,
    needsDesignAssets: true,
  },
  yorumlar: {
    include: 'comments',
    commentsLimit: 300,
    needsComments: true,
    needsActivity: false,
    needsDesignAssets: false,
  },
  aktivite: {
    include: 'activity',
    activityLimit: 200,
    needsComments: false,
    needsActivity: true,
    needsDesignAssets: false,
  },
};

function normalizeTab(tab) {
  return IDEA_TAB_CONFIG[tab] ? tab : 'genel';
}

function normalizeTagPayload(value = '') {
  return String(value)
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
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

function renderDesignBoard(ideaId, assets, editable) {
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
              <form id="idea-design-upload-form" class="design-upload-form" data-idea-id="${ideaId}">
                <div class="design-upload-grid">
                  <label class="design-field design-field-title">Başlık
                    <input name="title" maxlength="180" placeholder="Örn: onboarding akışı" />
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

function renderHeader(idea, canManage) {
  const categoryBadges = getIdeaCategoryLabels(idea.categories || idea.category)
    .map((label) => `<span class="badge badge-neutral">Kategori: ${escapeHtml(label)}</span>`)
    .join('');
  const tags = (idea.tags || [])
    .map((tag) => `<span class="task-tag task-tag-stage">${escapeHtml(tag)}</span>`)
    .join('');

  return `
    <article class="card project-hero-card">
      <div class="row between top wrap gap-12">
        <div class="stack gap-8">
          <h1>${escapeHtml(idea.title)}</h1>
          <p class="muted">${escapeHtml(idea.description || 'Bu fikir için henüz açıklama girilmemiş.')}</p>
          <div class="row gap-8 wrap">
            <span class="badge badge-${getIdeaStatusTone(idea.status)}">${escapeHtml(getIdeaStatusLabel(idea.status))}</span>
            ${categoryBadges}
            <span class="badge badge-neutral">Etki: ${idea.impact}/5</span>
            <span class="badge badge-neutral">Zorluk: ${idea.effort}/5</span>
          </div>
          <div class="row gap-8 wrap">${tags || '<span class="muted small">Etiket yok</span>'}</div>
        </div>

        <div class="stack gap-8 project-hero-meta">
          <label>Fikir durumu
            <select id="idea-status-select" ${canManage ? '' : 'disabled'}>
              ${ideaStatusOptionTemplate(idea.status)}
            </select>
          </label>
          <p class="muted small">Oluşturan: ${escapeHtml(idea.creator_name || 'Bilinmeyen')}</p>
          <p class="muted small">Son güncelleme: ${formatDate(idea.updated_at)}</p>
          <div class="row gap-8 wrap project-header-actions">
            ${canManage ? '<button class="btn btn-ghost" data-action="idea-edit">Fikri Düzenle</button>' : ''}
            ${canManage ? '<button class="btn btn-ghost" data-action="idea-convert">Projeye Çevir</button>' : ''}
            ${canManage ? '<button class="btn btn-ghost btn-danger-text" data-action="idea-delete">Fikri Sil</button>' : ''}
          </div>
        </div>
      </div>
    </article>
  `;
}

function renderTabs(ideaId, activeTab) {
  const active = IDEA_DETAIL_TABS.find((item) => item.key === activeTab) || IDEA_DETAIL_TABS[0];
  return `
    <section class="tabs detail-tabs">
      ${IDEA_DETAIL_TABS.map(
        (tab) => `<a class="tab-link ${tab.key === activeTab ? 'active' : ''}" href="#/ideas/${ideaId}?tab=${tab.key}">${tab.label}</a>`
      ).join('')}
    </section>
    <p class="muted small detail-tab-note">${escapeHtml(active.note)}</p>
  `;
}

function renderOverview(idea) {
  const categories = getIdeaCategoryLabels(idea.categories || idea.category).join(', ');
  return `
    <section class="focus-layout">
      <div class="stack gap-12">
        <article class="card">
          <div class="card-head">
            <h3>Fikir Özeti</h3>
            <span class="badge badge-${getIdeaStatusTone(idea.status)}">${escapeHtml(getIdeaStatusLabel(idea.status))}</span>
          </div>
          <p>${escapeHtml(idea.description || 'Açıklama girilmemiş.')}</p>
        </article>
      </div>

      <aside class="stack gap-12 focus-side">
        <article class="card">
          <div class="card-head"><h3>Detaylar</h3></div>
          <ul class="list">
            <li><div class="row between wrap"><strong>Kategori(ler)</strong><span>${escapeHtml(categories)}</span></div></li>
            <li><div class="row between wrap"><strong>Etki</strong><span>${idea.impact}/5</span></div></li>
            <li><div class="row between wrap"><strong>Zorluk</strong><span>${idea.effort}/5</span></div></li>
            <li><div class="row between wrap"><strong>MVP</strong><span>${normalizeWorkItems(idea.mvp || []).length} madde</span></div></li>
            <li><div class="row between wrap"><strong>Yapılacaklar</strong><span>${normalizeWorkItems(idea.feature_todos || []).length} madde</span></div></li>
            <li><div class="row between wrap"><strong>Oluşturan</strong><span>${escapeHtml(idea.creator_name || 'Bilinmeyen')}</span></div></li>
            <li><div class="row between wrap"><strong>Oluşturma</strong><span>${formatDate(idea.created_at)}</span></div></li>
            <li><div class="row between wrap"><strong>Son Güncelleme</strong><span>${formatDate(idea.updated_at)}</span></div></li>
          </ul>
        </article>
      </aside>
    </section>
  `;
}

function renderWorkTab(idea, key, title, subtitle, editable) {
  const items = normalizeWorkItems(key === 'mvp' ? idea.mvp || [] : idea.feature_todos || []);
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

function renderDesignTab(idea, designAssets, editable) {
  const totalLikes = designAssets.reduce((sum, item) => sum + Number(item.like_count || 0), 0);
  const totalComments = designAssets.reduce((sum, item) => sum + Number(item.comment_count || 0), 0);
  const latestAsset = designAssets[0] || null;

  return `
    <section class="focus-layout">
      <div class="stack gap-12">
        ${renderDesignBoard(idea.id, designAssets, editable)}
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

function renderComments(comments, currentUser, canManageIdea) {
  const commentsHtml = comments.length
    ? comments
        .map((comment) => {
          const canManageComment = canManageIdea || Number(comment.user_id) === Number(currentUser.id);
          return `
            <li data-comment-id="${comment.id}">
              <div class="row between wrap">
                <strong>${escapeHtml(comment.user_name || 'Kullanıcı')}</strong>
                <span class="muted small">${formatDate(comment.created_at)}</span>
              </div>
              <p>${escapeHtml(comment.content)}</p>
              ${
                canManageComment
                  ? `<div class="row gap-8"><button class="btn btn-ghost tiny" data-action="comment-edit" data-comment-id="${comment.id}">Düzenle</button><button class="btn btn-ghost tiny btn-danger-text" data-action="comment-delete" data-comment-id="${comment.id}">Sil</button></div>`
                  : ''
              }
            </li>
          `;
        })
        .join('')
    : '<li class="muted">Henüz yorum yok.</li>';

  return `
    <article class="card">
      <div class="card-head">
        <h3>Fikir Yorumları</h3>
        <span class="badge badge-neutral">${comments.length}</span>
      </div>
      <ul class="list">${commentsHtml}</ul>
      <form id="idea-comment-form" class="stack-form mt-16">
        <textarea name="content" rows="3" required placeholder="Yorumunuzu yazın"></textarea>
        <button class="btn btn-primary" type="submit">Yorum Ekle</button>
      </form>
    </article>
  `;
}

function renderActivity(activity) {
  return `
    <article class="card">
      <div class="card-head">
        <h3>Fikir Aktivite Kaydı</h3>
        <span class="badge badge-neutral">${activity.length}</span>
      </div>
      <ul class="list">
        ${
          activity.length
            ? activity
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

function openIdeaFormModal(idea, onSaved) {
  openModal({
    title: 'Fikri Düzenle',
    confirmText: 'Güncelle',
    content: `
      <form id="idea-edit-form" class="stack-form">
        <label>Başlık
          <input name="title" required minlength="2" maxlength="180" value="${escapeHtml(idea.title || '')}" />
        </label>
        <label>Açıklama
          <textarea name="description" rows="3">${escapeHtml(idea.description || '')}</textarea>
        </label>
        <label>Kategori(ler)
          <div class="check-grid">
            ${ideaCategoryChecklistTemplate(idea.categories || idea.category || IDEA_CATEGORY_OPTIONS[0].value)}
          </div>
        </label>
        <div class="inline-form">
          <label>Durum
            <select name="status">
              ${ideaStatusOptionTemplate(idea.status || 'idea')}
            </select>
          </label>
        </div>
        <div class="inline-form">
          <label>Etki (1-5)
            <input name="impact" type="number" min="1" max="5" value="${idea.impact || 3}" />
          </label>
          <label>Zorluk (1-5)
            <input name="effort" type="number" min="1" max="5" value="${idea.effort || 3}" />
          </label>
        </div>
        <label>Etiketler (virgülle)
          <input name="tags" value="${escapeHtml((idea.tags || []).join(', '))}" />
        </label>
      </form>
    `,
    onConfirm: async () => {
      const form = qs('#idea-edit-form');
      if (!form) return;

      const payload = formToObject(form);
      payload.tags = normalizeTagPayload(payload.tags || '');
      payload.impact = Number(payload.impact || 3);
      payload.effort = Number(payload.effort || 3);
      payload.categories = Array.from(form.querySelectorAll('input[name="categories"]:checked')).map((item) => item.value);

      try {
        const response = await api.patch(`/ideas/${idea.id}`, payload);
        closeModal();
        await onSaved(response.idea);
        showToast({ type: 'success', message: 'Fikir güncellendi.' });
      } catch (error) {
        showToast({ type: 'error', message: error.message || 'Fikir güncellenemedi.' });
      }
    },
  });
}

export async function render(ctx) {
  if (!ctx.state.user) {
    ctx.router.navigate('/login');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  const ideaId = Number(ctx.params.id);
  const activeTab = normalizeTab(ctx.query.tab || 'genel');
  const tabConfig = IDEA_TAB_CONFIG[activeTab];

  return {
    title: 'Fikir Detayı',
    html: `
      <section id="idea-detail-header" class="stack gap-12">
        <div class="card">${skeleton(6)}</div>
      </section>

      ${renderTabs(ideaId, activeTab)}

      <section id="idea-detail-content" class="stack gap-12">
        <div class="card">${skeleton(7)}</div>
      </section>
    `,
    async onMount() {
      const headerRoot = qs('#idea-detail-header');
      const contentRoot = qs('#idea-detail-content');
      const currentUser = ctx.state.user;

      let idea = null;
      let comments = [];
      let activity = [];
      let designAssets = [];
      let canManageIdea = false;

      const load = async () => {
        const query = {
          include: tabConfig.include,
        };
        if (tabConfig.commentsLimit) {
          query.comments_limit = tabConfig.commentsLimit;
        }
        if (tabConfig.activityLimit) {
          query.activity_limit = tabConfig.activityLimit;
        }

        const requests = [api.get(`/ideas/${ideaId}`, query)];
        if (tabConfig.needsDesignAssets) {
          requests.push(
            api.get('/design-assets', {
              entity_type: 'idea',
              entity_id: ideaId,
            })
          );
        }

        const responses = await Promise.all(requests);
        const data = responses[0];
        const assetsData = tabConfig.needsDesignAssets ? responses[1] : null;

        idea = data.idea;
        comments = tabConfig.needsComments ? data.comments || [] : [];
        activity = tabConfig.needsActivity ? data.activity || [] : [];
        designAssets = tabConfig.needsDesignAssets ? assetsData?.assets || [] : [];
        canManageIdea = currentUser.role === 'admin' || Number(idea.created_by) === Number(currentUser.id);
      };

      const renderCurrentTab = () => {
        headerRoot.innerHTML = renderHeader(idea, canManageIdea);

        if (activeTab === 'yorumlar') {
          contentRoot.innerHTML = renderComments(comments, currentUser, canManageIdea);
          bindCommentEvents();
        } else if (activeTab === 'mvp') {
          contentRoot.innerHTML = renderWorkTab(
            idea,
            'mvp',
            'MVP Listesi',
            'İlk yayında mutlaka teslim edilmesi gereken net maddeler.',
            canManageIdea
          );
          bindOverviewEvents();
        } else if (activeTab === 'yapilacaklar') {
          contentRoot.innerHTML = renderWorkTab(
            idea,
            'feature_todos',
            'Yapılacak Özellikler',
            'MVP sonrasında planlanan özellik ve iyileştirme adımları.',
            canManageIdea
          );
          bindOverviewEvents();
        } else if (activeTab === 'tasarim') {
          contentRoot.innerHTML = renderDesignTab(idea, designAssets, canManageIdea);
          bindOverviewEvents();
        } else if (activeTab === 'aktivite') {
          contentRoot.innerHTML = renderActivity(activity);
        } else {
          contentRoot.innerHTML = renderOverview(idea);
        }

        bindHeaderEvents();
      };

      const bindHeaderEvents = () => {
        qs('#idea-status-select')?.addEventListener('change', async (event) => {
          if (!canManageIdea) return;

          const previous = idea.status;
          const next = event.currentTarget.value;
          idea.status = next;

          try {
            await api.patch(`/ideas/${idea.id}`, { status: next });
            showToast({ type: 'success', message: 'Fikir durumu güncellendi.' });
          } catch (error) {
            idea.status = previous;
            showToast({ type: 'error', message: error.message || 'Durum güncellenemedi.' });
          }

          renderCurrentTab();
        });

        qs('[data-action="idea-edit"]')?.addEventListener('click', () => {
          if (!canManageIdea) return;
          openIdeaFormModal(idea, async (updated) => {
            idea = {
              ...idea,
              ...updated,
            };
            renderCurrentTab();
          });
        });

        qs('[data-action="idea-convert"]')?.addEventListener('click', async () => {
          if (!canManageIdea) return;

          try {
            const result = await api.post(`/ideas/${idea.id}/convert-to-project`, {});
            showToast({ type: 'success', message: 'Fikir projeye dönüştürüldü.' });
            ctx.router.navigate(`/projects/${result.project_id}`);
          } catch (error) {
            showToast({ type: 'error', message: error.message || 'Dönüştürme başarısız.' });
          }
        });

        qs('[data-action="idea-delete"]')?.addEventListener('click', () => {
          if (!canManageIdea) return;

          openModal({
            title: 'Fikri Sil',
            confirmText: 'Sil',
            content: `
              <div class="stack gap-8">
                <p><strong>${escapeHtml(idea.title)}</strong> kalıcı olarak silinecek.</p>
                <p class="muted small">Bu işlem geri alınamaz.</p>
              </div>
            `,
            onConfirm: async () => {
              try {
                await api.delete(`/ideas/${idea.id}`);
                closeModal();
                showToast({ type: 'success', message: 'Fikir silindi.' });
                ctx.router.navigate('/ideas');
              } catch (error) {
                showToast({ type: 'error', message: error.message || 'Fikir silinemedi.' });
              }
            },
          });
        });
      };

      const bindOverviewEvents = () => {
        const persistWorkItems = async (nextMvp, nextTodos) => {
          const response = await api.patch(`/ideas/${idea.id}`, {
            mvp: nextMvp,
            feature_todos: nextTodos,
          });
          idea = {
            ...idea,
            ...response.idea,
          };
        };

        const getWorkLists = () => ({
          mvp: normalizeWorkItems(idea.mvp || []),
          feature_todos: normalizeWorkItems(idea.feature_todos || []),
        });

        qsa('[data-action="work-toggle"]').forEach((checkbox) => {
          checkbox.addEventListener('change', async () => {
            if (!canManageIdea) return;
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
            if (!canManageIdea) return;
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
            if (!canManageIdea) return;
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

        qs('#idea-design-upload-form')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const payload = formToObject(event.currentTarget);
          if (!payload.image_url) {
            showToast({ type: 'error', message: 'Görsel URL alanı zorunlu.' });
            return;
          }

          try {
            await api.post('/design-assets', {
              entity_type: 'idea',
              entity_id: idea.id,
              title: payload.title,
              image_url: payload.image_url,
              description: payload.description,
            });
            const assetsData = await api.get('/design-assets', { entity_type: 'idea', entity_id: idea.id });
            designAssets = assetsData.assets || [];
            renderCurrentTab();
            showToast({ type: 'success', message: 'Görsel eklendi.' });
          } catch (error) {
            showToast({ type: 'error', message: error.message || 'Görsel eklenemedi.' });
          }
        });

        qsa('[data-action="asset-like"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const assetId = Number(button.dataset.assetId);
            try {
              await api.post(`/design-assets/${assetId}/like`, {});
              const assetsData = await api.get('/design-assets', { entity_type: 'idea', entity_id: idea.id });
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
              const assetsData = await api.get('/design-assets', { entity_type: 'idea', entity_id: idea.id });
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
                  <textarea id="idea-design-comment-input" rows="3" placeholder="Yorum yaz"></textarea>
                </div>
              `,
              onConfirm: async () => {
                const input = qs('#idea-design-comment-input');
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
                const assetsData = await api.get('/design-assets', { entity_type: 'idea', entity_id: idea.id });
                designAssets = assetsData.assets || [];
                renderCurrentTab();
                showToast({ type: 'success', message: 'Yorum eklendi.' });
              },
            });
          });
        });
      };

      const bindCommentEvents = () => {
        qs('#idea-comment-form')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const payload = formToObject(event.currentTarget);
          if (!payload.content?.trim()) {
            showToast({ type: 'error', message: 'Yorum boş olamaz.' });
            return;
          }

          try {
            const response = await api.post('/comments', {
              entity_type: 'idea',
              entity_id: idea.id,
              content: payload.content,
            });
            comments = [...comments, response.comment];
            renderCurrentTab();
            showToast({ type: 'success', message: 'Yorum eklendi.' });
          } catch (error) {
            showToast({ type: 'error', message: error.message || 'Yorum eklenemedi.' });
          }
        });

        qsa('[data-action="comment-edit"]').forEach((button) => {
          button.addEventListener('click', () => {
            const commentId = Number(button.dataset.commentId);
            const comment = comments.find((item) => Number(item.id) === commentId);
            if (!comment) return;

            openModal({
              title: 'Yorumu Düzenle',
              confirmText: 'Kaydet',
              content: `<textarea id="idea-comment-edit-input" rows="4">${escapeHtml(comment.content)}</textarea>`,
              onConfirm: async () => {
                const input = qs('#idea-comment-edit-input');
                if (!input?.value?.trim()) {
                  showToast({ type: 'error', message: 'Yorum boş olamaz.' });
                  return;
                }

                try {
                  await api.patch(`/comments/${commentId}`, { content: input.value.trim() });
                  comments = comments.map((item) =>
                    Number(item.id) === commentId
                      ? {
                          ...item,
                          content: input.value.trim(),
                        }
                      : item
                  );
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

        qsa('[data-action="comment-delete"]').forEach((button) => {
          button.addEventListener('click', () => {
            const commentId = Number(button.dataset.commentId);
            openModal({
              title: 'Yorumu Sil',
              confirmText: 'Sil',
              content: '<p>Bu yorumu silmek istediğinize emin misiniz?</p>',
              onConfirm: async () => {
                try {
                  await api.delete(`/comments/${commentId}`);
                  comments = comments.filter((item) => Number(item.id) !== commentId);
                  closeModal();
                  renderCurrentTab();
                  showToast({ type: 'success', message: 'Yorum silindi.' });
                } catch (error) {
                  showToast({ type: 'error', message: error.message || 'Yorum silinemedi.' });
                }
              },
            });
          });
        });
      };

      try {
        await load();
        renderCurrentTab();
      } catch (error) {
        contentRoot.innerHTML = `
          <div class="empty-state">
            <h3>Fikir yüklenemedi</h3>
            <p>${escapeHtml(error.message || 'Lütfen tekrar deneyin.')}</p>
          </div>
        `;
      }
    },
  };
}
