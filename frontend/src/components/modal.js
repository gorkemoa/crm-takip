let closeCallback = null;

export function openModal({ title, content, onConfirm, confirmText = 'Kaydet' }) {
  const root = document.querySelector('#modal-root');
  if (!root) return;

  closeModal();
  closeCallback = null;

  root.innerHTML = `
    <div class="modal-backdrop" data-action="close-modal"></div>
    <div class="modal" role="dialog" aria-modal="true" aria-label="${title}">
      <div class="modal-head">
        <h3>${title}</h3>
        <button class="icon-btn" data-action="close-modal" aria-label="Kapat">✕</button>
      </div>
      <div class="modal-body">${content}</div>
      <div class="modal-foot">
        <button class="btn btn-ghost" data-action="close-modal">İptal</button>
        <button class="btn btn-primary" data-action="confirm-modal">${confirmText}</button>
      </div>
    </div>
  `;

  root.classList.add('visible');

  root.querySelectorAll('[data-action="close-modal"]').forEach((el) => {
    el.addEventListener('click', closeModal);
  });

  root.querySelector('[data-action="confirm-modal"]')?.addEventListener('click', async () => {
    if (typeof onConfirm === 'function') {
      await onConfirm();
    }
  });

  closeCallback = () => {
    root.classList.remove('visible');
    root.innerHTML = '';
  };
}

export function closeModal() {
  if (typeof closeCallback === 'function') {
    closeCallback();
  }
}
