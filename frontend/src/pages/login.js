import { api } from '../utils/api.js';
import { formToObject, qs } from '../utils/dom.js';
import { setState } from '../store/index.js';
import { showToast } from '../components/toast.js';

export async function render(ctx) {
  if (ctx.state.user) {
    ctx.router.navigate('/dashboard');
    return { title: 'Yönlendiriliyor', html: '' };
  }

  return {
    title: 'Giriş',
    html: `
      <section class="auth-wrap">
        <div class="auth-card">
          <h1>Crm Takip’e Giriş</h1>
          <p>Crm Takip paneline giriş yapın.</p>
          <form id="login-form" class="stack-form">
            <label>Kullanıcı adı
              <input type="text" name="username" required minlength="3" maxlength="40" placeholder="admin" />
            </label>
            <label>Parola
              <input type="password" name="password" required minlength="6" placeholder="••••••••" />
            </label>
            <button class="btn btn-primary" type="submit">Giriş Yap</button>
            <p class="muted small">Demo admin: <code>admin / Admin123!</code></p>
          </form>
        </div>
      </section>
    `,
    onMount() {
      const form = qs('#login-form');
      form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = formToObject(form);

        try {
          const data = await api.post('/auth/login', payload, { skipAuthError: true });
          setState({ user: data.user, csrfToken: data.csrfToken || '' });
          showToast({ type: 'success', message: 'Hoş geldin.' });
          ctx.router.navigate('/dashboard');
        } catch (error) {
          showToast({ type: 'error', message: error.message || 'Giriş başarısız.' });
        }
      });
    },
  };
}
