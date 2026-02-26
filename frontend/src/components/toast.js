let toastTimer = null;

export function showToast({ type = 'info', message = '' }) {
  const root = document.querySelector('#toast-root');
  if (!root) return;

  root.innerHTML = `<div class="toast toast-${type}" role="status" aria-live="polite">${message}</div>`;
  root.classList.add('visible');

  if (toastTimer) {
    window.clearTimeout(toastTimer);
  }

  toastTimer = window.setTimeout(() => {
    root.classList.remove('visible');
  }, 2600);
}
