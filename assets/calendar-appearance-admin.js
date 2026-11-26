/* global document */
(() => {
  'use strict';
  const root = document.querySelector('.dc-appearance-settings');
  if (!root) return;
  const radios = [...root.querySelectorAll('input[type="radio"][name="booking_style"]')];
  const fields = [...root.querySelectorAll('[data-dc-color]')];
  const submit = root.querySelector('[data-dc-style-submit]');
  const activeStyle = root.querySelector('[data-dc-colors-style]');
  const message = root.querySelector('[data-dc-contrast-message]');
  const reset = root.querySelector('[data-dc-colors-reset]');
  if (!radios.length || !fields.length || !submit || !activeStyle || !message) return;
  const draft = {};
  const defaults = {};
  const names = ['accent', 'ink', 'surface', 'soft'];
  radios.forEach(radio => {
    try {
      draft[radio.value] = JSON.parse(radio.dataset.palette);
      defaults[radio.value] = JSON.parse(radio.dataset.defaults);
    } catch (_) {
      draft[radio.value] = null;
    }
  });
  const valid = color => /^#[\da-f]{6}$/i.test(color || '');
  const luminance = hex => {
    const values = [1, 3, 5].map(offset => parseInt(hex.slice(offset, offset + 2), 16) / 255)
      .map(c => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    return values[0] * .2126 + values[1] * .7152 + values[2] * .0722;
  };
  const ratio = (a, b) => {
    if (!valid(a) || !valid(b)) return 0;
    const x = luminance(a), y = luminance(b);
    return (Math.max(x, y) + .05) / (Math.min(x, y) + .05);
  };
  const selected = () => radios.find(radio => radio.checked) || radios[0];
  const paint = radio => {
    const colors = draft[radio.value];
    const preview = radio.closest('.dc-appearance-option')?.querySelector('.dc-appearance-demo');
    if (!colors || !preview) return;
    names.forEach(name => { if (valid(colors[name])) preview.style.setProperty('--dc-' + name, colors[name]); });
    preview.style.setProperty('--dc-accent-text', ratio(colors.accent, '#ffffff') >= ratio(colors.accent, '#000000') ? '#ffffff' : '#000000');
  };
  const validate = () => {
    const colors = draft[selected().value] || {};
    const ok = ratio(colors.ink, colors.surface) >= 4.5 && ratio(colors.ink, colors.soft) >= 4.5 && ratio(colors.accent, colors.surface) >= 3;
    submit.disabled = !ok;
    message.textContent = ok ? '' : (root.dataset.contrastWarning || 'Increase contrast between text, buttons and background.');
    message.classList.toggle('is-error', !ok);
    return ok;
  };
  const load = () => {
    const radio = selected();
    activeStyle.value = radio.value;
    const colors = draft[radio.value];
    if (!colors) return;
    fields.forEach(field => {
      const color = colors[field.dataset.dcColor];
      if (valid(color)) field.value = color;
      const output = root.querySelector('[data-dc-color-output="' + field.dataset.dcColor + '"]');
      if (output) output.textContent = field.value.toUpperCase();
    });
    paint(radio);
    validate();
  };
  fields.forEach(field => field.addEventListener('input', () => {
    const radio = selected();
    if (!draft[radio.value] || !valid(field.value)) return;
    draft[radio.value][field.dataset.dcColor] = field.value.toLowerCase();
    const output = root.querySelector('[data-dc-color-output="' + field.dataset.dcColor + '"]');
    if (output) output.textContent = field.value.toUpperCase();
    paint(radio);
    validate();
  }));
  radios.forEach(radio => radio.addEventListener('change', load));
  reset?.addEventListener('click', () => {
    const radio = selected();
    if (!defaults[radio.value]) return;
    draft[radio.value] = { ...defaults[radio.value] };
    load();
  });
  root.querySelector('form')?.addEventListener('submit', event => {
    if (!validate()) event.preventDefault();
  });
  load();
})();
