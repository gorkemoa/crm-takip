import { Router } from './router/index.js';
import { getState, setState, subscribe } from './store/index.js';
import { api, bootstrapSession } from './utils/api.js';
import {
  renderLogin,
  renderRegister,
  renderDashboard,
  renderProjects,
  renderProjectDetail,
  renderIdeas,
  renderIdeaDetail,
  renderSettings,
  renderNotifications,
  renderNotFound,
} from './pages/index.js';
import { showToast } from './components/toast.js';
import { escapeHtml, qs, qsa } from './utils/dom.js';
import { formatNotification } from './utils/notifications.js';

let currentUnmount = null;

function isMobileViewport() {
  return window.matchMedia('(max-width: 980px)').matches;
}

function setSidebarOpen(open) {
  const sidebar = qs('#sidebar');
  const backdrop = qs('#sidebar-backdrop');
  const toggle = qs('#sidebar-toggle');
  if (!sidebar || !backdrop) return;

  const shouldOpen = Boolean(open) && isMobileViewport();
  sidebar.classList.toggle('open', shouldOpen);
  backdrop.classList.toggle('open', shouldOpen);
  backdrop.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
  document.body.classList.toggle('drawer-open', shouldOpen);

  if (toggle) {
    toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
  }
}

function closeSidebar() {
  setSidebarOpen(false);
}

function routeTitle(path) {
  if (path.startsWith('/projects/')) return 'Proje Detayı';
  if (path.startsWith('/ideas/')) return 'Fikir Detayı';
  if (path.startsWith('/projects')) return 'Projeler';
  if (path.startsWith('/ideas')) return 'Fikir Havuzu';
  if (path.startsWith('/settings')) return 'Ayarlar';
  if (path.startsWith('/notifications')) return 'Bildirimler';
  if (path.startsWith('/login')) return 'Giriş';
  if (path.startsWith('/register')) return 'Kayıt Kapalı';
  return 'Dashboard';
}

function renderNotificationItems() {
  const state = getState();
  const items = state.notifications.slice(0, 8);

  if (!state.user) {
    return '<p class="muted small">Bildirimler için giriş yapın.</p>';
  }

  if (!items.length) {
    return '<p class="muted small">Yeni bildirim yok.</p>';
  }

  return `
    <ul class="dropdown-list">
      ${items
        .map((item) => {
          const row = formatNotification(item);
          return `
            <li class="${item.is_read ? 'read' : 'unread'}">
              <strong>${escapeHtml(row.title)}</strong>
              <span>${escapeHtml(String(row.detail || '').slice(0, 72))}</span>
            </li>
          `;
        })
        .join('')}
    </ul>
  `;
}

function renderShell() {
  const root = document.querySelector('#app');

  root.innerHTML = `
    <div class="app-shell">
      <aside class="sidebar" id="sidebar">
        <div class="brand">
          <span class="dot"></span>
          <span>Crm Takip</span>
        </div>
        <nav>
          <a href="#/dashboard" data-nav="/dashboard">Dashboard</a>
          <a href="#/projects" data-nav="/projects">Projeler</a>
          <a href="#/ideas" data-nav="/ideas">Fikirler</a>
          <a href="#/notifications" data-nav="/notifications">Bildirimler</a>
          <a href="#/settings" data-nav="/settings">Ayarlar</a>
        </nav>
      </aside>

      <div class="main-shell">
        <header class="topbar">
          <div class="row gap-8 topbar-main">
            <button id="sidebar-toggle" class="icon-btn mobile-only" aria-label="Menüyü aç" aria-controls="sidebar" aria-expanded="false">☰</button>
            <div>
              <h2 id="page-title">Dashboard</h2>
              <p class="muted small" id="page-subtitle">Proje & iş akışı yönetimi</p>
            </div>
          </div>

          <div class="row gap-8 topbar-actions">
            <div class="notification-wrap">
              <button class="icon-btn" id="notification-bell" aria-label="Bildirimler">
                🔔 <span class="badge-count" id="notification-count">0</span>
              </button>
              <div class="dropdown-panel" id="notification-dropdown" aria-hidden="true">
                <div class="row between">
                  <strong>Bildirimler</strong>
                  <a href="#/notifications">Tümünü Aç</a>
                </div>
                <div id="notification-items"></div>
              </div>
            </div>
            <button class="btn btn-ghost" id="logout-btn">Çıkış</button>
          </div>
        </header>

        <main id="page-content" tabindex="-1"></main>
      </div>

      <button id="sidebar-backdrop" class="sidebar-backdrop" aria-hidden="true" aria-label="Menüyü kapat"></button>
    </div>

    <div id="modal-root"></div>
    <div id="toast-root"></div>
  `;
}

function applyNavState(path) {
  document.querySelectorAll('[data-nav]').forEach((link) => {
    const target = link.getAttribute('data-nav');
    if (path.startsWith(target)) {
      link.classList.add('active');
    } else {
      link.classList.remove('active');
    }
  });

  const titleEl = qs('#page-title');
  if (titleEl) {
    titleEl.textContent = routeTitle(path);
  }
}

async function syncNotifications() {
  const state = getState();
  if (!state.user) {
    setState({ notifications: [], unreadCount: 0 });
    return;
  }

  try {
    const data = await api.get('/notifications', { limit: 50 });
    const list = data.notifications || [];
    setState({
      notifications: list,
      unreadCount: list.filter((item) => !item.is_read).length,
    });
  } catch (error) {
    console.error('Failed to sync notifications', error);
  }
}

function bindShellEvents(router) {
  qs('#notification-bell')?.addEventListener('click', async () => {
    const dropdown = qs('#notification-dropdown');
    if (!dropdown) return;

    const isOpen = dropdown.classList.toggle('open');
    dropdown.setAttribute('aria-hidden', isOpen ? 'false' : 'true');

    if (isOpen) {
      await syncNotifications();
    }
  });

  document.addEventListener('click', (event) => {
    const dropdown = qs('#notification-dropdown');
    const bell = qs('#notification-bell');
    if (!dropdown || !bell) return;

    if (dropdown.contains(event.target) || bell.contains(event.target)) {
      return;
    }

    dropdown.classList.remove('open');
    dropdown.setAttribute('aria-hidden', 'true');
  });

  qs('#logout-btn')?.addEventListener('click', async () => {
    try {
      await api.post('/auth/logout', {});
      setState({ user: null, csrfToken: '', notifications: [], unreadCount: 0 });
      router.navigate('/login');
    } catch (error) {
      showToast({ type: 'error', message: error.message || 'Çıkış yapılamadı.' });
    }
  });

  qs('#sidebar-toggle')?.addEventListener('click', () => {
    const sidebar = qs('#sidebar');
    if (!sidebar) return;
    setSidebarOpen(!sidebar.classList.contains('open'));
  });

  qs('#sidebar-backdrop')?.addEventListener('click', closeSidebar);

  qsa('[data-nav]').forEach((link) => {
    link.addEventListener('click', () => {
      closeSidebar();
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeSidebar();
    }
  });

  window.addEventListener('resize', () => {
    if (!isMobileViewport()) {
      closeSidebar();
    }
  });
}

function renderHeaderState() {
  const state = getState();
  const countEl = qs('#notification-count');
  const itemsEl = qs('#notification-items');

  if (countEl) {
    countEl.textContent = String(state.unreadCount || 0);
    countEl.style.display = state.unreadCount ? 'inline-flex' : 'none';
  }

  if (itemsEl) {
    itemsEl.innerHTML = renderNotificationItems();
  }

  const logoutButton = qs('#logout-btn');
  if (logoutButton) {
    logoutButton.style.display = state.user ? 'inline-flex' : 'none';
  }
}

const routes = [
  { path: '/login', page: renderLogin, guestOnly: true },
  { path: '/register', page: renderRegister, guestOnly: true },
  { path: '/dashboard', page: renderDashboard, requiresAuth: true },
  { path: '/projects', page: renderProjects, requiresAuth: true },
  { path: '/projects/:id', page: renderProjectDetail, requiresAuth: true },
  { path: '/ideas', page: renderIdeas, requiresAuth: true },
  { path: '/ideas/:id', page: renderIdeaDetail, requiresAuth: true },
  { path: '/notifications', page: renderNotifications, requiresAuth: true },
  { path: '/settings', page: renderSettings, requiresAuth: true },
  { path: '/404', page: renderNotFound },
];

async function init() {
  renderShell();

  const router = new Router(routes, async (match) => {
    const state = getState();
    const route = match.route;

    if (!route) {
      window.location.hash = '#/404';
      return;
    }

    if (route.requiresAuth && !state.user) {
      router.navigate('/login');
      return;
    }

    if (route.guestOnly && state.user) {
      router.navigate('/dashboard');
      return;
    }

    if (typeof currentUnmount === 'function') {
      try {
        currentUnmount();
      } catch (error) {
        console.error('Unmount failed', error);
      }
      currentUnmount = null;
    }

    setState({ activePath: match.path });
    applyNavState(match.path);
    closeSidebar();

    const view = await route.page({
      router,
      params: match.params,
      query: match.query,
      state: getState(),
    });

    document.title = `${view?.title || 'CRM'} • Crm Takip`;

    const pageRoot = qs('#page-content');
    if (pageRoot) {
      pageRoot.innerHTML = view?.html || '';
      pageRoot.focus();
    }

    if (typeof view?.onMount === 'function') {
      const maybeCleanup = await view.onMount();
      if (typeof maybeCleanup === 'function') {
        currentUnmount = maybeCleanup;
      }
    }
  });

  bindShellEvents(router);

  subscribe(() => {
    renderHeaderState();
    applyNavState(getState().activePath || '/dashboard');
  });

  try {
    await bootstrapSession();
  } catch (error) {
    setState({ user: null });
  }

  if (getState().user) {
    if (!window.location.hash || ['#/login', '#/register'].includes(window.location.hash)) {
      router.navigate('/dashboard');
    }
  } else if (!window.location.hash || window.location.hash === '#/') {
    router.navigate('/login');
  }

  renderHeaderState();
  router.start();
}

init();
