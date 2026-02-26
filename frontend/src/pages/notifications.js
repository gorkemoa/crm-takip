import { api } from '../utils/api.js';
import { escapeHtml, qs } from '../utils/dom.js';
import { formatDate } from '../utils/date.js';
import { setState } from '../store/index.js';
import { showToast } from '../components/toast.js';
import { skeleton } from '../components/ui.js';
import { formatNotification } from '../utils/notifications.js';

function renderNotificationList(items) {
  if (!items.length) {
    return '<div class="empty-state"><h3>Bildirim yok</h3><p>Şu anda listelenecek bildirim bulunamadı.</p></div>';
  }

  return `
    <ul class="list notifications-list">
      ${items
        .map((item) => {
          const row = formatNotification(item);
          return `
          <li class="${item.is_read ? 'read' : 'unread'}" data-notification-id="${item.id}">
            <div>
              <strong>${escapeHtml(row.title)}</strong>
              <p>${escapeHtml(row.detail)}</p>
              <span class="muted small">${formatDate(item.created_at)}</span>
            </div>
            ${item.is_read ? '' : `<button class="btn btn-ghost tiny" data-action="mark-read" data-notification-id="${item.id}">Okundu</button>`}
          </li>
        `;
        })
        .join('')}
    </ul>
  `;
}

export async function render(ctx) {
  if (!ctx.state.user) {
    ctx.router.navigate('/login');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  return {
    title: 'Bildirim Merkezi',
    html: `
      <section class="page-head row between wrap">
        <div>
          <h1>Bildirim Merkezi</h1>
          <p>Anlık güncellemeler, atamalar ve yorumlar.</p>
        </div>
        <div class="row gap-8">
          <select id="notification-scope">
            <option value="">Tümü</option>
            <option value="relevant">Sadece beni ilgilendirenler</option>
          </select>
          <button id="notifications-read-all" class="btn btn-ghost">Tümünü Okundu Yap</button>
        </div>
      </section>
      <section id="notifications-content" class="card">${skeleton(6)}</section>
    `,
    async onMount() {
      const content = qs('#notifications-content');
      const scopeSelect = qs('#notification-scope');

      const load = async () => {
        const data = await api.get('/notifications', {
          limit: 200,
          scope: scopeSelect?.value || undefined,
        });

        const list = data.notifications || [];
        setState({
          notifications: list,
          unreadCount: list.filter((item) => !item.is_read).length,
        });

        content.innerHTML = renderNotificationList(list);

        content.querySelectorAll('[data-action="mark-read"]').forEach((button) => {
          button.addEventListener('click', async () => {
            const notificationId = Number(button.dataset.notificationId);
            const prev = [...list];
            const current = list.find((item) => Number(item.id) === notificationId);
            if (!current) return;

            current.is_read = true;
            content.innerHTML = renderNotificationList(list);

            try {
              await api.patch(`/notifications/${notificationId}/read`, {});
              setState({ unreadCount: list.filter((item) => !item.is_read).length });
            } catch (error) {
              content.innerHTML = renderNotificationList(prev);
              showToast({ type: 'error', message: error.message || 'Bildirim güncellenemedi.' });
            }
          });
        });
      };

      qs('#notifications-read-all')?.addEventListener('click', async () => {
        try {
          await api.post('/notifications/read-all', {});
          showToast({ type: 'success', message: 'Tüm bildirimler okundu.' });
          await load();
        } catch (error) {
          showToast({ type: 'error', message: error.message || 'İşlem başarısız.' });
        }
      });

      scopeSelect?.addEventListener('change', load);

      await load();
    },
  };
}
