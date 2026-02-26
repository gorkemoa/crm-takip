import { api } from '../utils/api.js';
import { escapeHtml, formToObject, qs } from '../utils/dom.js';
import { formatDate } from '../utils/date.js';
import { openModal, closeModal } from '../components/modal.js';
import { showToast } from '../components/toast.js';
import { skeleton } from '../components/ui.js';
import {
  PROJECT_STATUS_OPTIONS,
  PROJECT_TYPE_OPTIONS,
  getStatusLabel,
  getStatusTone,
  getPriorityLabel,
  getProjectTypeLabels,
  normalizeProjectTypes,
} from '../utils/status.js';
import { setState } from '../store/index.js';

function initials(name = '') {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() || '')
    .join('');
}

function optionTemplate(options, selectedValue = '') {
  return options
    .map((option) => `<option value="${option.value}" ${selectedValue === option.value ? 'selected' : ''}>${option.label}</option>`)
    .join('');
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

function collectSelected(form, name) {
  return Array.from(form.querySelectorAll(`input[name="${name}"]:checked`)).map((item) => item.value);
}

const PROJECT_BOARD_LANES = [
  {
    key: 'planlama',
    label: 'Planlama',
    statuses: ['baslaniyor', 'tartisiliyor', 'arastiriliyor'],
  },
  {
    key: 'uretim',
    label: 'Üretim',
    statuses: ['tasarlaniyor', 'gelistiriliyor', 'test_ediliyor'],
  },
  {
    key: 'yayin_bakim',
    label: 'Yayın / Bakım',
    statuses: ['yayina_hazir', 'yayinda', 'bakimda', 'duraklatildi'],
  },
  {
    key: 'iptal',
    label: 'İptal',
    statuses: ['iptal_talebi', 'iptal_edildi'],
  },
  {
    key: 'tamamlanan',
    label: 'Tamamlanan',
    statuses: ['tamamlandi'],
  },
];

function shortText(value, max = 110) {
  const text = String(value || '').trim();
  if (text.length <= max) return text;
  return `${text.slice(0, max - 1).trimEnd()}…`;
}

function renderProjectMemberDots(members = []) {
  if (!members.length) {
    return '<span class="muted small">Ekip yok</span>';
  }

  const visible = members
    .slice(0, 3)
    .map((member) => `<span class="avatar-dot project-board-avatar-dot">${escapeHtml(initials(member.name))}</span>`)
    .join('');

  const hidden = members.length > 3 ? `<span class="avatar-dot avatar-dot-muted">+${members.length - 3}</span>` : '';
  return `${visible}${hidden}`;
}

function renderProjectBoardCard(project) {
  const typeTags = getProjectTypeLabels(project.types || project.type)
    .map((label) => `<span class="task-tag task-tag-stage">${escapeHtml(label)}</span>`)
    .join('');

  const tags = (project.tags || [])
    .slice(0, 3)
    .map((tag) => `<span class="task-tag task-tag-stage">${escapeHtml(tag)}</span>`)
    .join('');

  return `
    <article class="task-card task-card-classic project-board-card">
      <div class="task-tags">
        ${typeTags}
        <span class="task-tag task-tag-priority task-tag-priority-${escapeHtml(project.priority)}">${escapeHtml(getPriorityLabel(project.priority))}</span>
      </div>
      <h4>${escapeHtml(project.title)}</h4>
      <p class="muted small">${escapeHtml(shortText(project.description || 'Açıklama girilmedi.', 140))}</p>
      <div class="row gap-8 wrap">${tags}</div>
      <div class="task-bottom-line">
        <div class="row gap-8">${renderProjectMemberDots(project.members || [])}</div>
        <span class="badge badge-${getStatusTone(project.status)}">${escapeHtml(getStatusLabel(project.status))}</span>
      </div>
      <div class="row between muted small wrap">
        <span>Açık görev: ${project.open_task_count}</span>
        <span>${project.due_date ? `Hedef: ${formatDate(project.due_date, { withTime: false })}` : 'Hedef yok'}</span>
      </div>
      <a class="btn btn-ghost tiny" href="#/projects/${project.id}">Detayı Aç</a>
    </article>
  `;
}

function renderProjectsBoard(projects) {
  const columns = PROJECT_BOARD_LANES.map((lane) => {
    const laneProjects = projects.filter((project) => lane.statuses.includes(project.status));

    return `
      <section class="kanban-col kanban-col-classic project-board-col">
        <div class="kanban-col-head">
          <h3>${lane.label}</h3>
          <span class="badge badge-neutral">${laneProjects.length}</span>
        </div>
        <div class="kanban-list">
          ${laneProjects.length ? laneProjects.map(renderProjectBoardCard).join('') : '<div class="kanban-empty">Proje yok</div>'}
        </div>
      </section>
    `;
  }).join('');

  return `<section class="kanban-board kanban-board-classic project-board">${columns}</section>`;
}

function membersChecklist(users, selected = []) {
  return users
    .map(
      (user) => `
        <label class="check-row">
          <input type="checkbox" name="member_ids" value="${user.id}" ${selected.includes(user.id) ? 'checked' : ''} />
          <span>${escapeHtml(user.name)} <small class="muted">(${escapeHtml(user.role)})</small></span>
        </label>
      `
    )
    .join('');
}

export async function render(ctx) {
  if (!ctx.state.user) {
    ctx.router.navigate('/login');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  return {
    title: 'Projeler',
    html: `
      <section class="card hub-panel hub-panel-combined">
        <div class="hub-panel-head row between wrap">
          <div>
            <h1>Proje Merkezi</h1>
            <p>Durum akışı, ekip takibi ve sade yönetim paneli.</p>
          </div>
          <button class="btn btn-primary" id="new-project-btn">Yeni Proje Oluştur</button>
        </div>

        <section id="projects-list" class="hub-panel-body stack gap-12">
          <div class="card hub-list-card">${skeleton(8)}</div>
        </section>
      </section>
    `,
    async onMount() {
      const listRoot = qs('#projects-list');

      let users = ctx.state.users || [];
      if (!users.length) {
        const usersResponse = await api.get('/users');
        users = usersResponse.users || [];
        setState({ users });
      }

      const load = async () => {
        const data = await api.get('/projects');
        const items = data.projects || [];

        if (!items.length) {
          listRoot.innerHTML = `
            <div class="empty-state">
              <h3>Proje bulunamadı</h3>
              <p>Henüz proje bulunmuyor. Yeni proje oluşturabilirsiniz.</p>
            </div>
          `;
          return;
        }

        listRoot.innerHTML = renderProjectsBoard(items);
      };

      qs('#new-project-btn')?.addEventListener('click', () => {
        openModal({
          title: 'Yeni Proje Oluştur',
          confirmText: 'Projeyi Kaydet',
          content: `
            <form id="project-create-form" class="stack-form">
              <label>Proje adı
                <input name="title" required minlength="2" maxlength="180" />
              </label>
              <label>Açıklama
                <textarea name="description" rows="3" placeholder="Projenin kapsamını yazın"></textarea>
              </label>
              <div class="inline-form">
                <label>Proje durumu
                  <select name="status">
                    ${optionTemplate(PROJECT_STATUS_OPTIONS, 'baslaniyor')}
                  </select>
                </label>
                <label>Öncelik
                  <select name="priority">
                    <option value="low">Düşük</option>
                    <option value="med" selected>Orta</option>
                    <option value="high">Yüksek</option>
                  </select>
                </label>
              </div>
              <label>Tür(ler)
                <div class="check-grid">${projectTypeChecklist(['web'])}</div>
              </label>
              <label>Etiketler (virgülle)
                <input name="tags" placeholder="crm, mobil, landing" />
              </label>
              <label>Hedef tarih
                <input name="due_date" type="date" />
              </label>
              <label>Projeye eklenecek ekip üyeleri</label>
              <div class="check-grid">${membersChecklist(users)}</div>
            </form>
          `,
          onConfirm: async () => {
            const form = qs('#project-create-form');
            if (!form) return;

            const payload = formToObject(form);
            payload.tags = (payload.tags || '')
              .split(',')
              .map((item) => item.trim())
              .filter(Boolean);
            payload.types = collectSelected(form, 'types');
            payload.member_ids = Array.from(form.querySelectorAll('input[name="member_ids"]:checked')).map((input) => Number(input.value));

            try {
              await api.post('/projects', payload);
              closeModal();
              showToast({ type: 'success', message: 'Proje başarıyla oluşturuldu.' });
              await load();
            } catch (error) {
              showToast({ type: 'error', message: error.message || 'Proje oluşturulamadı.' });
            }
          },
        });
      });

      await load();
    },
  };
}
