const state = {
  user: null,
  csrfToken: '',
  users: [],
  notifications: [],
  unreadCount: 0,
  activePath: '/dashboard',
};

const listeners = new Set();

function notify() {
  listeners.forEach((listener) => {
    try {
      listener(state);
    } catch (error) {
      console.error('Store listener failed', error);
    }
  });
}

export function getState() {
  return state;
}

export function setState(patch) {
  Object.assign(state, patch);
  notify();
}

export function updateState(updater) {
  const result = updater({ ...state });
  if (result && typeof result === 'object') {
    Object.assign(state, result);
    notify();
  }
}

export function subscribe(listener) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
