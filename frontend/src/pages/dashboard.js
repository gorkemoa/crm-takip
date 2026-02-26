import { api } from '../utils/api.js';
import { formatDate, fromNowDays } from '../utils/date.js';
import { escapeHtml } from '../utils/dom.js';
import { skeleton } from '../components/ui.js';
import { getStatusLabel, getStatusTone, getPriorityLabel, getProjectTypeLabels } from '../utils/status.js';
import { formatActivity, getActionLabel, getEntityLabel } from '../utils/activity.js';

const PROJECT_LANES = [
  {
    key: 'plan_arastirma',
    label: 'Planlama ve Araştırma',
    hint: 'İhtiyaç analizi, keşif ve tartışma',
    statuses: ['baslaniyor', 'tartisiliyor', 'arastiriliyor'],
  },
  {
    key: 'tasarim_gelistirme',
    label: 'Tasarım ve Geliştirme',
    hint: 'UI/UX çalışmaları ve geliştirme',
    statuses: ['tasarlaniyor', 'gelistiriliyor'],
  },
  {
    key: 'test_yayin',
    label: 'Test ve Yayın',
    hint: 'Test, yayına hazırlık ve canlı yayın',
    statuses: ['test_ediliyor', 'yayina_hazir', 'yayinda'],
  },
  {
    key: 'beklemede',
    label: 'Bakım ve Bekleyenler',
    hint: 'Bakımda olan, duraklatılan veya iptal edilenler',
    statuses: ['bakimda', 'duraklatildi', 'iptal_talebi', 'iptal_edildi'],
  },
];

const WHO_KANBAN_COLUMNS = [
  {
    key: 'yogun',
    label: 'Yoğun Çalışanlar',
    hint: '5+ aktif görev',
    test: (member) => Number(member.active_task_count || 0) >= 5,
  },
  {
    key: 'aktif',
    label: 'Aktif Çalışanlar',
    hint: '1-4 aktif görev',
    test: (member) => Number(member.active_task_count || 0) >= 1 && Number(member.active_task_count || 0) < 5,
  },
  {
    key: 'planlama',
    label: 'Planlama / Takip',
    hint: 'Koordinasyon ve planlama',
    test: (member) => Number(member.active_task_count || 0) === 0,
  },
];

function initials(name = '') {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() || '')
    .join('');
}

function projectDueBadge(project) {
  const days = fromNowDays(project.due_date);
  if (days === null) return '<span class="badge badge-neutral">Tarih yok</span>';
  if (days < 0) return `<span class="badge badge-danger">${Math.abs(days)} gün gecikme</span>`;
  if (days <= 3) return `<span class="badge badge-warn">${days} gün kaldı</span>`;
  return `<span class="badge badge-ok">${days} gün kaldı</span>`;
}

function projectCard(project) {
  const typeBadges = getProjectTypeLabels(project.types || project.type)
    .map((label) => `<span class="badge badge-neutral">${escapeHtml(label)}</span>`)
    .join('');

  return `
    <article class="dashboard-project-card">
      <div class="row between top">
        <h3><a href="#/projects/${project.id}">${escapeHtml(project.title)}</a></h3>
        <span class="badge badge-${getStatusTone(project.status)}">${escapeHtml(getStatusLabel(project.status))}</span>
      </div>
      <p class="muted small">${escapeHtml(project.description || 'Açıklama yok')}</p>
      <div class="row gap-8 wrap">
        ${typeBadges}
        <span class="badge badge-neutral">Açık görev: ${project.open_task_count ?? 0}</span>
        <span class="badge badge-neutral">Öncelik: ${escapeHtml(getPriorityLabel(project.priority || '-'))}</span>
        ${projectDueBadge(project)}
      </div>
    </article>
  `;
}

function taskCard(task) {
  return `
    <article class="dashboard-task-card">
      <div class="row between top">
        <h4>${escapeHtml(task.title)}</h4>
        <span class="badge badge-${getStatusTone(task.status)}">${escapeHtml(getStatusLabel(task.status))}</span>
      </div>
      <p class="muted small">${escapeHtml(task.stage_name || 'Aşama yok')}</p>
      <p class="muted small">${task.due_date ? formatDate(task.due_date, { withTime: false }) : 'Teslim tarihi yok'}</p>
    </article>
  `;
}

function laneTemplate({ label, hint, count, cardsHtml, emptyText }) {
  return `
    <section class="dashboard-lane">
      <header class="dashboard-lane-head">
        <div>
          <h3>${escapeHtml(label)}</h3>
          ${hint ? `<p class="muted small">${escapeHtml(hint)}</p>` : ''}
        </div>
        <span class="badge badge-neutral">${count}</span>
      </header>
      <div class="dashboard-lane-list">
        ${cardsHtml || `<div class="dashboard-lane-empty">${escapeHtml(emptyText)}</div>`}
      </div>
    </section>
  `;
}

function shortText(value, max = 110) {
  const text = String(value || '').trim();
  if (!text) return '';
  return text.length > max ? `${text.slice(0, max - 1).trimEnd()}...` : text;
}

function whoItem(member) {
  const projectStates = (member.project_states || []).slice(0, 4);
  const currentState = projectStates[0] || null;

  const states = projectStates
    .map(
      (item) => `
        <div class="who-project-row">
          <span class="muted small">${escapeHtml(shortText(item.project_title || 'Proje', 28))}</span>
          <span class="badge badge-${getStatusTone(item.member_status)}">${escapeHtml(getStatusLabel(item.member_status))}</span>
        </div>
      `
    )
    .join('');

  const recentActions = (member.recent_actions || [])
    .slice(0, 2)
    .map((item) => {
      const row = formatActivity({
        actor_name: member.name,
        action: item.action,
        entity_type: item.entity_type,
        entity_id: item.entity_id,
        meta: item.meta || {},
      });
      return `
        <li class="who-action-item">
          <div class="row between wrap">
            <strong>${escapeHtml(row.actionLabel)}</strong>
            <span class="muted small">${formatDate(item.created_at)}</span>
          </div>
          <p class="muted small">${escapeHtml(shortText(row.detail, 90))}</p>
        </li>
      `;
    })
    .join('');

  return `
    <article class="who-card">
      <header class="who-card-head">
        <div class="row gap-10 top">
          <span class="avatar-mini">${escapeHtml(initials(member.name))}</span>
          <div class="who-card-title">
            <h4>${escapeHtml(member.name)}</h4>
            <p class="muted small">${escapeHtml(member.title || member.role || '-')}</p>
          </div>
        </div>
        <div class="who-card-metrics">
          <span class="who-pill">Aktif: ${member.active_task_count}</span>
          <span class="who-pill who-pill-soft">Açık: ${member.open_task_count}</span>
        </div>
      </header>
      <section class="who-now">
        <span class="muted small">Şu an</span>
        ${
          currentState
            ? `
              <div class="who-now-row">
                <strong>${escapeHtml(shortText(currentState.project_title || 'Proje', 34))}</strong>
                <span class="badge badge-${getStatusTone(currentState.member_status)}">${escapeHtml(getStatusLabel(currentState.member_status))}</span>
              </div>
            `
            : '<p class="muted small">Durum bilgisi yok</p>'
        }
      </section>
      <section class="who-projects">
        ${states || '<span class="muted small">Proje durumu yok</span>'}
      </section>
      <section class="who-actions">
        <h5>Son hareketler</h5>
        <ul class="who-action-list">
          ${
            recentActions ||
            `<li class="who-action-item"><div class="row between wrap"><strong>${escapeHtml(getActionLabel(member.last_action))}</strong><span class="muted small">${member.last_action_at ? formatDate(member.last_action_at) : '-'}</span></div><p class="muted small">${escapeHtml(getEntityLabel('project'))}</p></li>`
          }
        </ul>
      </section>
    </article>
  `;
}

function activityItem(item) {
  const row = formatActivity(item);
  return `
    <li class="dashboard-activity-item">
      <div class="row between wrap">
        <strong>${escapeHtml(row.title)}</strong>
        <span class="muted small">${formatDate(item.created_at)}</span>
      </div>
      <p>${escapeHtml(row.detail)}</p>
    </li>
  `;
}

export async function render(ctx) {
  if (!ctx.state.user) {
    ctx.router.navigate('/login');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  return {
    title: 'Dashboard',
    html: `
      <section class="page-head">
        <h1>Dashboard</h1>
        <p>Kanban görünümünde proje akışı.</p>
      </section>

      <section id="dashboard-content" class="dashboard-layout">
        <div class="card">${skeleton(8)}</div>
      </section>
    `,
    async onMount() {
      const summary = await api.get('/dashboard/summary', { activity_limit: 120 });
      const root = document.querySelector('#dashboard-content');

      const activeProjects = summary.active_projects || [];
      const assignedTasks = summary.assigned_tasks || [];
      const teamPulse = summary.team_pulse || [];
      const recentActivity = summary.recent_activity || [];
      const unread = Number(summary.unread_notifications || 0);

      const projectLaneHtml = PROJECT_LANES.map((lane) => {
        const laneProjects = activeProjects.filter((project) => lane.statuses.includes(project.status));
        return laneTemplate({
          label: lane.label,
          hint: lane.hint,
          count: laneProjects.length,
          cardsHtml: laneProjects.map(projectCard).join(''),
          emptyText: 'Bu kolonda proje yok.',
        });
      }).join('');

      const myTasksLane = laneTemplate({
        label: 'Bana Atananlar',
        hint: 'Sana atanan güncel görevler',
        count: assignedTasks.length,
        cardsHtml: assignedTasks.slice(0, 18).map(taskCard).join(''),
        emptyText: 'Açık görev yok.',
      });

      const teamPulseHtml = WHO_KANBAN_COLUMNS.map((column) => {
        const members = teamPulse.filter(column.test);
        return `
          <section class="who-column">
            <div class="who-column-head">
              <div>
                <h3>${escapeHtml(column.label)}</h3>
                <p class="muted small">${escapeHtml(column.hint || '')}</p>
              </div>
              <span class="badge badge-neutral">${members.length}</span>
            </div>
            <div class="who-column-list">
              ${members.length ? members.map(whoItem).join('') : '<div class="who-empty">Bu grupta kişi yok</div>'}
            </div>
          </section>
        `;
      }).join('');

      const activityHtml = recentActivity.length
        ? recentActivity.map(activityItem).join('')
        : '<li class="dashboard-lane-empty">Aktivite bulunamadı.</li>';

      root.innerHTML = `
        <section class="dashboard-kpi-strip">
          <article class="dashboard-kpi">
            <p>Aktif Proje</p>
            <strong>${activeProjects.length}</strong>
          </article>
          <article class="dashboard-kpi">
            <p>Bana Atanan</p>
            <strong>${assignedTasks.length}</strong>
          </article>
          <article class="dashboard-kpi">
            <p>Okunmamış</p>
            <strong>${unread}</strong>
          </article>
          <article class="dashboard-kpi">
            <p>Toplam Akış Kolonu</p>
            <strong>${PROJECT_LANES.length + 1}</strong>
          </article>
        </section>

        <section class="dashboard-kanban-shell">
          <div class="dashboard-kanban-board">
            ${projectLaneHtml}
            ${myTasksLane}
          </div>
        </section>

        <section class="dashboard-bottom-grid">
          <article class="card dashboard-panel">
            <div class="card-head">
              <h2>Kim Ne Yapıyor?</h2>
              <span class="badge badge-neutral">${teamPulse.length} kişi</span>
            </div>
            <section class="who-board">
              ${teamPulseHtml}
            </section>
          </article>

          <article class="card dashboard-panel">
            <div class="card-head">
              <h2>Anlamlı Aktivite Akışı</h2>
              <span class="badge badge-neutral">${recentActivity.length}</span>
            </div>
            <ul class="dashboard-scroll-list">
              ${activityHtml}
            </ul>
          </article>
        </section>
      `;
    },
  };
}
