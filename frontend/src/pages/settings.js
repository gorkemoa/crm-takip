import { api } from '../utils/api.js';
import { escapeHtml, formToObject, qs, qsa } from '../utils/dom.js';
import { showToast } from '../components/toast.js';
import { openModal, closeModal } from '../components/modal.js';
import { setState } from '../store/index.js';
import { skeleton } from '../components/ui.js';

function renderUsers(users = [], currentUserId = null) {
  if (!users.length) {
    return '<div class="empty-state"><h3>Kullanıcı bulunamadı</h3><p>İlk kullanıcıyı sağdaki formdan ekleyin.</p></div>';
  }

  return `
    <div class="settings-user-list">
      ${users
        .map(
          (user) => `
            <article class="settings-user-item">
              <div>
                <h4>${escapeHtml(user.name)}</h4>
                <p class="muted small">@${escapeHtml(user.username || '-')} • ${escapeHtml(user.title || 'Title yok')}</p>
                <span class="badge badge-${user.role === 'admin' ? 'info' : 'neutral'}">${escapeHtml(user.role)}</span>
                ${Number(user.id) === Number(currentUserId) ? '<span class="badge badge-warn">Sen</span>' : ''}
              </div>
              <button class="btn btn-ghost tiny" data-action="user-edit" data-user-id="${user.id}">Düzenle</button>
            </article>
          `
        )
        .join('')}
    </div>
  `;
}

function openUserEditModal({ user, isSelf, onSave }) {
  openModal({
    title: 'Kullanıcıyı Düzenle',
    confirmText: 'Kaydet',
    content: `
      <form id="user-edit-form" class="stack-form">
        <label>Ad Soyad
          <input name="name" required minlength="2" maxlength="120" value="${escapeHtml(user.name || '')}" />
        </label>
        <label>Kullanıcı adı
          <input name="username" required minlength="3" maxlength="40" value="${escapeHtml(user.username || '')}" />
        </label>
        <label>Title
          <input name="title" maxlength="120" value="${escapeHtml(user.title || '')}" />
        </label>
        <label>Rol
          <select name="role" ${isSelf ? 'disabled' : ''}>
            <option value="member" ${user.role === 'member' ? 'selected' : ''}>member</option>
            <option value="admin" ${user.role === 'admin' ? 'selected' : ''}>admin</option>
          </select>
        </label>
        <label>Yeni parola (opsiyonel)
          <input name="password" type="password" minlength="8" placeholder="Değiştirmek istemiyorsan boş bırak" />
        </label>
      </form>
    `,
    onConfirm: async () => {
      const form = qs('#user-edit-form');
      if (!form) return;

      const payload = formToObject(form);
      const normalized = {
        name: String(payload.name || '').trim(),
        username: String(payload.username || '').trim(),
        title: String(payload.title || '').trim(),
      };

      if (!isSelf) {
        normalized.role = payload.role;
      }
      if (payload.password) {
        normalized.password = payload.password;
      }

      try {
        const result = await api.patch(`/users/${user.id}`, normalized);
        closeModal();
        showToast({ type: 'success', message: 'Kullanıcı güncellendi.' });
        await onSave(result.user);
      } catch (error) {
        showToast({ type: 'error', message: error.message || 'Kullanıcı güncellenemedi.' });
      }
    },
  });
}

export async function render(ctx) {
  if (!ctx.state.user) {
    ctx.router.navigate('/login');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  return {
    title: 'Ayarlar',
    html: `
      <section class="page-head">
        <h1>Ayarlar</h1>
        <p>Profil, kullanıcı yönetimi ve aşama şablonu.</p>
      </section>

      <section class="grid two-col" id="settings-content">
        <article class="card">
          ${skeleton(6)}
        </article>
        <article class="card">
          ${skeleton(7)}
        </article>
      </section>
    `,
    async onMount() {
      const isAdmin = ctx.state.user.role === 'admin';
      const root = qs('#settings-content');

      const stageTemplateData = await api.get('/stage-templates');
      let users = [];
      if (isAdmin) {
        const usersData = await api.get('/users');
        users = usersData.users || [];
      }

      const renderUserList = () => {
        if (!isAdmin) return;
        const listRoot = qs('#settings-user-list');
        if (!listRoot) return;

        listRoot.innerHTML = renderUsers(users, ctx.state.user.id);
        qsa('[data-action="user-edit"]', listRoot).forEach((button) => {
          button.addEventListener('click', () => {
            const userId = Number(button.dataset.userId);
            const target = users.find((item) => Number(item.id) === userId);
            if (!target) return;

            openUserEditModal({
              user: target,
              isSelf: Number(target.id) === Number(ctx.state.user.id),
              onSave: async (updated) => {
                users = users.map((item) => (Number(item.id) === Number(updated.id) ? updated : item));
                renderUserList();
                if (Number(updated.id) === Number(ctx.state.user.id)) {
                  setState({ user: updated });
                }
              },
            });
          });
        });
      };

      root.innerHTML = `
        <article class="card">
          <div class="card-head">
            <h2>Profil</h2>
            <span class="badge badge-neutral">${escapeHtml(ctx.state.user.role)}</span>
          </div>
          <form id="profile-form" class="stack-form">
            <label>Ad Soyad
              <input name="name" required minlength="2" maxlength="120" value="${escapeHtml(ctx.state.user.name || '')}" />
            </label>
            <label>Kullanıcı adı
              <input name="username" required minlength="3" maxlength="40" value="${escapeHtml(ctx.state.user.username || '')}" />
            </label>
            <label>Title
              <input name="title" maxlength="120" value="${escapeHtml(ctx.state.user.title || '')}" />
            </label>
            <label>Avatar URL (opsiyonel)
              <input name="avatar_url" maxlength="500" value="${escapeHtml(ctx.state.user.avatar_url || '')}" />
            </label>
            <label>Mevcut parola (yalnızca parola değişimi için)
              <input name="current_password" type="password" minlength="8" />
            </label>
            <label>Yeni parola (opsiyonel)
              <input name="password" type="password" minlength="8" />
            </label>
            <button class="btn btn-primary" type="submit">Profili Kaydet</button>
          </form>
        </article>

        <article class="card">
          <h2>Pipeline Şablonu</h2>
          <p class="muted small">Projelerde varsayılan açılacak aşama isimleri.</p>
          <form id="stage-template-form" class="stack-form mt-16">
            <textarea name="stages" rows="10">${escapeHtml(
              (stageTemplateData.stages || []).map((stage) => stage.name).join('\n')
            )}</textarea>
            <button class="btn btn-primary" type="submit" ${isAdmin ? '' : 'disabled'}>Aşama Şablonunu Kaydet</button>
          </form>
        </article>

        <article class="card">
          <h2>Tarayıcı Bildirimleri</h2>
          <p class="muted small">Canlı event geldiğinde sistem bildirimi göstermek için izin ver.</p>
          <div class="row gap-8">
            <button class="btn btn-ghost" id="browser-notif-btn">Bildirim İzni İste</button>
            <span class="muted small" id="browser-notif-state">${
              typeof Notification === 'undefined'
                ? 'Bu tarayıcı desteklemiyor.'
                : localStorage.getItem('crm_browser_notifications') === '1'
                  ? 'Aktif'
                  : 'Kapalı'
            }</span>
          </div>
        </article>

        ${
          isAdmin
            ? `
          <article class="card">
            <h2>Yeni Kullanıcı Oluştur</h2>
            <form id="create-user-form" class="stack-form mt-16">
              <label>Ad Soyad
                <input name="name" required minlength="2" maxlength="120" />
              </label>
              <label>Kullanıcı adı
                <input name="username" required minlength="3" maxlength="40" />
              </label>
              <label>Title
                <input name="title" maxlength="120" />
              </label>
              <label>Rol
                <select name="role">
                  <option value="member" selected>member</option>
                  <option value="admin">admin</option>
                </select>
              </label>
              <label>Parola
                <input name="password" type="password" required minlength="8" />
              </label>
              <button class="btn btn-primary" type="submit">Kullanıcı Oluştur</button>
            </form>
          </article>

          <article class="card">
            <div class="card-head">
              <h2>Kullanıcı Yönetimi</h2>
              <span class="badge badge-neutral">${users.length} kişi</span>
            </div>
            <div id="settings-user-list"></div>
          </article>
        `
            : ''
        }
      `;

      qs('#profile-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = formToObject(event.currentTarget);
        const normalized = {
          name: String(payload.name || '').trim(),
          username: String(payload.username || '').trim(),
          title: String(payload.title || '').trim(),
          avatar_url: String(payload.avatar_url || '').trim(),
        };

        if (payload.password) {
          normalized.password = payload.password;
          normalized.current_password = payload.current_password;
        }

        try {
          const result = await api.patch('/users/me', normalized);
          setState({ user: result.user });
          showToast({ type: 'success', message: 'Profil güncellendi.' });
        } catch (error) {
          showToast({ type: 'error', message: error.message || 'Profil güncellenemedi.' });
        }
      });

      qs('#stage-template-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!isAdmin) return;

        const values = formToObject(event.currentTarget);
        const stages = String(values.stages || '')
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean);

        try {
          await api.post('/stage-templates', { stages });
          showToast({ type: 'success', message: 'Aşama şablonu kaydedildi.' });
        } catch (error) {
          showToast({ type: 'error', message: error.message || 'Şablon kaydedilemedi.' });
        }
      });

      qs('#browser-notif-btn')?.addEventListener('click', async () => {
        if (!('Notification' in window)) {
          showToast({ type: 'error', message: 'Tarayıcı bildirim API desteklemiyor.' });
          return;
        }

        try {
          const permission = await Notification.requestPermission();
          if (permission === 'granted') {
            localStorage.setItem('crm_browser_notifications', '1');
            qs('#browser-notif-state').textContent = 'Aktif';
            showToast({ type: 'success', message: 'Tarayıcı bildirimleri aktif.' });
          } else {
            localStorage.removeItem('crm_browser_notifications');
            qs('#browser-notif-state').textContent = permission === 'denied' ? 'Engellendi' : 'Kapalı';
            showToast({ type: 'info', message: 'Bildirim izni verilmedi.' });
          }
        } catch (_error) {
          showToast({ type: 'error', message: 'Bildirim izni istenirken hata oluştu.' });
        }
      });

      qs('#create-user-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!isAdmin) return;

        const payload = formToObject(event.currentTarget);
        const normalized = {
          name: String(payload.name || '').trim(),
          username: String(payload.username || '').trim(),
          title: String(payload.title || '').trim(),
          role: payload.role || 'member',
          password: payload.password || '',
        };

        try {
          const result = await api.post('/users', normalized);
          users = [...users, result.user].sort((a, b) => String(a.name).localeCompare(String(b.name), 'tr'));
          event.currentTarget.reset();
          renderUserList();
          showToast({ type: 'success', message: 'Kullanıcı oluşturuldu.' });
        } catch (error) {
          showToast({ type: 'error', message: error.message || 'Kullanıcı oluşturulamadı.' });
        }
      });

      renderUserList();
    },
  };
}
