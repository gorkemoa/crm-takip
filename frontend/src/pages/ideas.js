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
  normalizeIdeaCategories,
} from '../utils/ideas.js';

const IDEA_BOARD_COLUMNS = IDEA_STATUS_OPTIONS.map((status) => ({
  key: status.value,
  label: status.label,
}));

function optionTemplate(options, selectedValue = '') {
  return options
    .map((option) => `<option value="${option.value}" ${selectedValue === option.value ? 'selected' : ''}>${option.label}</option>`)
    .join('');
}

function normalizeTagPayload(value = '') {
  return String(value)
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
}

function statusClips(selected = '') {
  const active = String(selected || '');
  const options = [{ value: '', label: 'Tümü' }, ...IDEA_STATUS_OPTIONS];
  return options
    .map((option) => `<button type="button" class="clip ${active === option.value ? 'active' : ''}" data-filter-status="${option.value}">${option.label}</button>`)
    .join('');
}

function categoryClipChecklist(selected = []) {
  const selectedSet = new Set(normalizeIdeaCategories(selected));
  return IDEA_CATEGORY_OPTIONS.map(
    (option) => `
      <label class="clip-check ${selectedSet.has(option.value) ? 'active' : ''}">
        <input type="checkbox" name="categories" value="${option.value}" ${selectedSet.has(option.value) ? 'checked' : ''} />
        <span>${option.label}</span>
      </label>
    `
  ).join('');
}

function collectSelected(form, name) {
  return Array.from(form.querySelectorAll(`input[name="${name}"]:checked`)).map((item) => item.value);
}

function buildIdeaFilters(form) {
  const base = formToObject(form);
  const categories = collectSelected(form, 'categories');
  return {
    q: base.q || '',
    status: base.status || '',
    sort: base.sort || 'updated_desc',
    categories: categories.join(','),
  };
}

function canManageIdea(user, idea) {
  if (!user || !idea) return false;
  return user.role === 'admin' || Number(idea.created_by) === Number(user.id);
}

function shortText(value, max = 130) {
  const text = String(value || '').trim();
  if (!text) return 'Açıklama girilmedi.';
  if (text.length <= max) return text;
  return `${text.slice(0, max - 1).trimEnd()}…`;
}

function renderIdeaMetrics(idea) {
  return `
    <div class="idea-metric-grid">
      <div class="idea-metric-box">
        <span>Etki</span>
        <strong>${idea.impact}/5</strong>
      </div>
      <div class="idea-metric-box">
        <span>Zorluk</span>
        <strong>${idea.effort}/5</strong>
      </div>
    </div>
  `;
}

function renderIdeaCard(idea, user) {
  const categoryBadges = getIdeaCategoryLabels(idea.categories || idea.category)
    .slice(0, 3)
    .map((label) => `<span class="badge badge-neutral">${escapeHtml(label)}</span>`)
    .join('');

  const tags = (idea.tags || [])
    .slice(0, 4)
    .map((tag) => `<span class="task-tag task-tag-stage">${escapeHtml(tag)}</span>`)
    .join('');

  const canManage = canManageIdea(user, idea);

  return `
    <article class="task-card task-card-classic idea-card" data-idea-id="${idea.id}">
      <div class="task-tags">
        <span class="badge badge-${getIdeaStatusTone(idea.status)}">${escapeHtml(getIdeaStatusLabel(idea.status))}</span>
        ${categoryBadges}
      </div>
      <h4>${escapeHtml(idea.title)}</h4>
      <p class="muted small">${escapeHtml(shortText(idea.description, 145))}</p>
      ${renderIdeaMetrics(idea)}
      <div class="row gap-8 wrap">${tags || '<span class="muted small">Etiket yok</span>'}</div>
      <div class="row between wrap gap-8">
        <span class="muted small">${escapeHtml(idea.creator_name || 'Bilinmeyen')} • ${formatDate(idea.updated_at)}</span>
        <div class="row gap-8 wrap">
          <a class="btn btn-ghost tiny" href="#/ideas/${idea.id}">Detay</a>
          ${canManage ? `<button class="btn btn-ghost tiny" data-action="idea-edit" data-idea-id="${idea.id}">Düzenle</button>` : ''}
          ${canManage ? `<button class="btn btn-ghost tiny btn-danger-text" data-action="idea-delete" data-idea-id="${idea.id}">Sil</button>` : ''}
          ${canManage ? `<button class="btn btn-primary tiny" data-action="idea-convert" data-idea-id="${idea.id}">Projeye Çevir</button>` : ''}
        </div>
      </div>
    </article>
  `;
}

function renderIdeasBoard(ideas, user) {
  const columns = IDEA_BOARD_COLUMNS.map((column) => {
    const list = ideas.filter((idea) => idea.status === column.key);
    return `
      <section class="kanban-col kanban-col-classic idea-board-col">
        <div class="kanban-col-head">
          <h3>${escapeHtml(column.label)}</h3>
          <span class="badge badge-neutral">${list.length}</span>
        </div>
        <div class="kanban-list">
          ${list.length ? list.map((idea) => renderIdeaCard(idea, user)).join('') : '<div class="kanban-empty">Fikir yok</div>'}
        </div>
      </section>
    `;
  }).join('');

  return `<section class="kanban-board idea-board">${columns}</section>`;
}

function openIdeaFormModal({ idea = null, onSaved }) {
  openModal({
    title: idea ? 'Fikir Düzenle' : 'Yeni Fikir',
    confirmText: idea ? 'Güncelle' : 'Kaydet',
    content: `
      <form id="idea-form" class="stack-form">
        <label>Başlık
          <input name="title" required minlength="2" maxlength="180" value="${escapeHtml(idea?.title || '')}" />
        </label>
        <label>Açıklama
          <textarea name="description" rows="3">${escapeHtml(idea?.description || '')}</textarea>
        </label>
        <label>Kategori(ler)
          <div class="check-grid">
            ${ideaCategoryChecklistTemplate(idea?.categories || idea?.category || IDEA_CATEGORY_OPTIONS[0].value)}
          </div>
        </label>
        <div class="inline-form">
          <label>Durum
            <select name="status">
              ${ideaStatusOptionTemplate(idea?.status || 'idea')}
            </select>
          </label>
        </div>
        <div class="inline-form">
          <label>Etki (1-5)
            <input name="impact" type="number" min="1" max="5" value="${idea?.impact || 3}" />
          </label>
          <label>Zorluk (1-5)
            <input name="effort" type="number" min="1" max="5" value="${idea?.effort || 3}" />
          </label>
        </div>
        <label>Etiketler (virgülle)
          <input name="tags" value="${escapeHtml((idea?.tags || []).join(', '))}" />
        </label>
      </form>
    `,
    onConfirm: async () => {
      const form = qs('#idea-form');
      if (!form) return;

      const payload = formToObject(form);
      payload.tags = normalizeTagPayload(payload.tags || '');
      payload.impact = Number(payload.impact || 3);
      payload.effort = Number(payload.effort || 3);
      payload.categories = Array.from(form.querySelectorAll('input[name="categories"]:checked')).map((item) => item.value);

      try {
        if (idea) {
          await api.patch(`/ideas/${idea.id}`, payload);
          showToast({ type: 'success', message: 'Fikir güncellendi.' });
        } else {
          await api.post('/ideas', payload);
          showToast({ type: 'success', message: 'Fikir eklendi.' });
        }

        closeModal();
        await onSaved();
      } catch (error) {
        showToast({ type: 'error', message: error.message || 'İşlem tamamlanamadı.' });
      }
    },
  });
}

export async function render(ctx) {
  if (!ctx.state.user) {
    ctx.router.navigate('/login');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  const initialCategories = normalizeIdeaCategories(ctx.query.categories || ctx.query.category || '');

  return {
    title: 'Fikirler',
    html: `
      <section class="card hub-panel hub-panel-combined">
        <div class="hub-panel-head row between wrap">
          <div>
            <h1>Fikir Merkezi</h1>
            <p>Fikirleri net durum akışında yönet, tek tıkla projeye dönüştür.</p>
          </div>
          <button id="new-idea-btn" class="btn btn-primary">Yeni Fikir</button>
        </div>

        <form id="idea-filters" class="project-filter-clip-form">
          <label class="project-filter-search">Arama
            <input type="search" name="q" placeholder="Başlık, açıklama veya kategori ara" value="${escapeHtml(ctx.query.q || '')}" />
          </label>
          <input type="hidden" name="status" value="${escapeHtml(ctx.query.status || '')}" />
          <div class="filter-block">
            <span class="muted small">Durum</span>
            <div class="filter-clips" id="idea-status-clips">${statusClips(ctx.query.status || '')}</div>
          </div>
          <div class="filter-block">
            <span class="muted small">Kategori</span>
            <div class="filter-clips filter-clips-check">
              ${categoryClipChecklist(initialCategories)}
            </div>
          </div>
          <details class="filter-advanced">
            <summary>Sıralama</summary>
            <label>
              <select name="sort">
                ${optionTemplate(
                  [
                    { value: 'updated_desc', label: 'Son güncellenenler' },
                    { value: 'created_desc', label: 'Yeni eklenenler' },
                    { value: 'impact_desc', label: 'Etkisi yüksek olanlar' },
                    { value: 'effort_asc', label: 'Zorluğu düşük olanlar' },
                    { value: 'title_asc', label: 'A-Z sıralı' },
                  ],
                  ctx.query.sort || 'updated_desc'
                )}
              </select>
            </label>
          </details>
          <button class="btn btn-primary" type="submit">Filtreyi Uygula</button>
        </form>
        <section id="ideas-list" class="hub-panel-body stack gap-12">
          <div class="card hub-list-card">${skeleton(8)}</div>
        </section>
      </section>
    `,
    async onMount() {
      const filtersForm = qs('#idea-filters');
      const listRoot = qs('#ideas-list');
      const currentUser = ctx.state.user;

      let ideas = [];

      const load = async () => {
        const data = await api.get('/ideas', buildIdeaFilters(filtersForm));
        ideas = data.ideas || [];

        if (!ideas.length) {
          listRoot.innerHTML = '<div class="empty-state"><h3>Fikir bulunamadı</h3><p>Filtreleri sıfırla ya da yeni fikir oluştur.</p></div>';
          return;
        }

        listRoot.innerHTML = renderIdeasBoard(ideas, currentUser);
        bindListActions();
      };

      const bindListActions = () => {
        qsa('[data-action="idea-edit"]').forEach((button) => {
          button.addEventListener('click', () => {
            const idea = ideas.find((item) => Number(item.id) === Number(button.dataset.ideaId));
            if (!idea) return;

            openIdeaFormModal({
              idea,
              onSaved: load,
            });
          });
        });

        qsa('[data-action="idea-delete"]').forEach((button) => {
          button.addEventListener('click', () => {
            const idea = ideas.find((item) => Number(item.id) === Number(button.dataset.ideaId));
            if (!idea) return;

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
                  await load();
                } catch (error) {
                  showToast({ type: 'error', message: error.message || 'Fikir silinemedi.' });
                }
              },
            });
          });
        });

        qsa('[data-action="idea-convert"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const ideaId = Number(button.dataset.ideaId);
            try {
              const result = await api.post(`/ideas/${ideaId}/convert-to-project`, {});
              showToast({ type: 'success', message: 'Fikir projeye dönüştürüldü.' });
              await load();
              ctx.router.navigate(`/projects/${result.project_id}`);
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Dönüştürme başarısız.' });
            }
          });
        });
      };

      filtersForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const query = new URLSearchParams(buildIdeaFilters(filtersForm)).toString();
        window.location.hash = `#/ideas?${query}`;
      });

      filtersForm?.querySelectorAll('[data-filter-status]').forEach((button) => {
        button.addEventListener('click', () => {
          const statusInput = filtersForm.querySelector('input[name="status"]');
          if (!statusInput) return;
          statusInput.value = button.dataset.filterStatus || '';
          filtersForm.querySelectorAll('[data-filter-status]').forEach((clip) => {
            clip.classList.toggle('active', clip === button);
          });
        });
      });

      filtersForm?.querySelectorAll('.clip-check input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
          input.closest('.clip-check')?.classList.toggle('active', input.checked);
        });
      });

      qs('#new-idea-btn')?.addEventListener('click', () => {
        openIdeaFormModal({
          idea: null,
          onSaved: load,
        });
      });

      await load();
    },
  };
}
