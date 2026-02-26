import { escapeHtml } from '../utils/dom.js';

export function badge(text, tone = 'neutral') {
  return `<span class="badge badge-${tone}">${escapeHtml(text)}</span>`;
}

export function emptyState({ title, description, actionHtml = '' }) {
  return `
    <div class="empty-state">
      <h3>${escapeHtml(title)}</h3>
      <p>${escapeHtml(description)}</p>
      ${actionHtml}
    </div>
  `;
}

export function skeleton(lines = 3) {
  const items = Array.from({ length: lines }, () => '<div class="skeleton-line"></div>').join('');
  return `<div class="skeleton-block">${items}</div>`;
}
