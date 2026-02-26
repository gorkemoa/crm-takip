import { getState, setState } from '../store/index.js';

const API_BASE = '/api/index.php';

function buildUrl(path, query) {
  const url = new URL(API_BASE + path, window.location.origin);
  if (query && typeof query === 'object') {
    Object.entries(query).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, value);
      }
    });
  }
  return url.toString();
}

async function request(method, path, { query, body, skipAuthError = false } = {}) {
  const state = getState();
  const headers = {
    'Accept': 'application/json',
  };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (!['GET', 'HEAD'].includes(method) && state.csrfToken) {
    headers['X-CSRF-Token'] = state.csrfToken;
  }

  const response = await fetch(buildUrl(path, query), {
    method,
    headers,
    credentials: 'include',
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const text = await response.text();
  let payload = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch (error) {
      payload = null;
    }
  }

  if (!response.ok || !payload?.ok) {
    const fallbackMessage = (text || '').trim().slice(0, 240) || 'Beklenmeyen API hatası.';
    const normalizedError = {
      status: response.status,
      code: payload?.error?.code || 'API_ERROR',
      message: payload?.error?.message || fallbackMessage,
      fields: payload?.error?.fields || {},
    };

    if (normalizedError.status === 401 && !skipAuthError) {
      setState({ user: null });
      if (window.location.hash !== '#/login') {
        window.location.hash = '#/login';
      }
    }

    throw normalizedError;
  }

  return payload.data;
}

export const api = {
  get: (path, query, options) => request('GET', path, { query, ...options }),
  post: (path, body, options) => request('POST', path, { body, ...options }),
  patch: (path, body, options) => request('PATCH', path, { body, ...options }),
  delete: (path, body, options) => request('DELETE', path, { body, ...options }),
};

export async function bootstrapSession() {
  const me = await api.get('/auth/me', null, { skipAuthError: true });
  setState({
    user: me.user,
    csrfToken: me.csrfToken || '',
  });
  return me.user;
}

export async function refreshCsrfToken() {
  const data = await api.get('/auth/csrf');
  setState({ csrfToken: data.csrfToken || '' });
  return data.csrfToken;
}
