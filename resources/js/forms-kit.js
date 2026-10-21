/*!
 * forms-kit — form client (spycx/forms-kit).
 * Copied into the site by `vendor/bin/forms-kit publish`: don't edit the copy, it gets overwritten.
 *
 * FormsKit.attach(form, options) connects a <form action="contact.php"> to the endpoint:
 *   - requests the anti-spam token (GET action?token) on load and puts it in the hidden field;
 *   - on submit: confirm → "Enviando…" → success / error, using SweetAlert2 when loaded
 *     and otherwise window.confirm + text in the status element;
 *   - waits as needed so it never submits before the server's minimum.
 */
(function (window) {
  'use strict';

  const DEFAULT_TEXTS = {
    confirmTitle: '¿Enviar mensaje?',
    // Receives the already-escaped name; returns HTML
    confirmHtml: (safeName) => safeName
      ? `Hola <strong>${safeName}</strong>, te responderemos a la brevedad.`
      : 'Te responderemos a la brevedad.',
    confirmFallback: '¿Enviar tu mensaje?',
    confirmButton: 'Sí, enviar',
    cancelButton: 'Revisar',
    sending: 'Enviando…',
    sendingText: 'Estamos entregando tu mensaje, un momento por favor.',
    successTitle: '¡Mensaje enviado!',
    successButton: 'Perfecto',
    errorTitle: 'No se pudo enviar',
    errorButton: 'Entendido',
    genericError: 'No pudimos enviar tu mensaje. Intenta de nuevo.',
    networkError: 'Error de conexión. Revisa tu red e intenta de nuevo.'
  };

  const escapeHtml = (str) => String(str).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[c]);

  /**
   * @param {HTMLFormElement} form
   * @param {object} [options]
   * @param {object} [options.swal]        Base SweetAlert2 options (theme): background, color, customClass…
   * @param {object} [options.iconColors]  { question, success, error }
   * @param {object} [options.texts]       Overrides entries of DEFAULT_TEXTS.
   * @param {boolean} [options.confirm=true] Ask for confirmation before sending.
   * @param {string} [options.nameField='name']  Field whose value is shown in the confirmation.
   * @param {string} [options.tokenField='form_token']
   * @param {string} [options.statusSelector='.form-status']
   * @param {string} [options.submitSelector='[type="submit"]']
   * @param {string} [options.sendingClass='is-sending']
   * @param {number} [options.minWaitMs=3500]  Must be greater than the server's minimum (3 s).
   * @param {number} [options.maxTokenAgeMs]   Renew the token after this long (12 h; the server accepts 24 h).
   */
  function attach(form, options = {}) {
    if (!form) return;

    const opts = {
      swal: {},
      iconColors: {},
      confirm: true,
      nameField: 'name',
      tokenField: 'form_token',
      statusSelector: '.form-status',
      submitSelector: '[type="submit"]',
      sendingClass: 'is-sending',
      minWaitMs: 3500,
      maxTokenAgeMs: 12 * 60 * 60 * 1000,
      ...options
    };
    const texts = { ...DEFAULT_TEXTS, ...(options.texts || {}) };

    const statusEl = form.querySelector(opts.statusSelector);
    const submitBtn = form.querySelector(opts.submitSelector);
    const submitLabel = submitBtn ? submitBtn.textContent : '';
    const tokenInput = form.elements[opts.tokenField];
    const hasSwal = () => typeof window.Swal !== 'undefined';
    let tokenAt = 0;

    // --- Anti-spam token ---
    const fetchToken = async () => {
      const res = await fetch(form.action + '?token', {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });
      const data = await res.json();
      if (!data.token) throw new Error('No token');
      tokenInput.value = data.token;
      tokenAt = Date.now();
    };

    const ensureToken = async () => {
      if (!tokenInput) return;
      if (!tokenInput.value || Date.now() - tokenAt > opts.maxTokenAgeMs) await fetchToken();
      const wait = opts.minWaitMs - (Date.now() - tokenAt);
      if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait));
    };

    if (tokenInput) fetchToken().catch(() => {});

    // --- UI ---
    const setStatus = (type, text) => {
      if (!statusEl) return;
      statusEl.textContent = text;
      statusEl.className = statusEl.className.replace(/\s*\bis-(success|error)\b/g, '') + (type ? ' is-' + type : '');
    };

    const askConfirm = async () => {
      if (!opts.confirm) return true;
      const nameInput = form.elements[opts.nameField];
      const name = nameInput ? nameInput.value.trim() : '';
      if (!hasSwal()) return window.confirm(texts.confirmFallback);
      const { isConfirmed } = await window.Swal.fire({
        ...opts.swal,
        title: texts.confirmTitle,
        html: texts.confirmHtml(escapeHtml(name)),
        icon: 'question',
        iconColor: opts.iconColors.question,
        showCancelButton: true,
        confirmButtonText: texts.confirmButton,
        cancelButtonText: texts.cancelButton,
        reverseButtons: true,
        focusConfirm: true
      });
      return isConfirmed;
    };

    const showSending = () => {
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.classList.add(opts.sendingClass);
        submitBtn.textContent = texts.sending;
      }
      setStatus('', '');
      if (hasSwal()) {
        window.Swal.fire({
          ...opts.swal,
          title: texts.sending,
          text: texts.sendingText,
          allowOutsideClick: false,
          allowEscapeKey: false,
          showConfirmButton: false,
          didOpen: () => window.Swal.showLoading()
        });
      }
    };

    const resetButton = () => {
      if (!submitBtn) return;
      submitBtn.disabled = false;
      submitBtn.classList.remove(opts.sendingClass);
      submitBtn.textContent = submitLabel;
    };

    const showResult = (ok, message) => {
      setStatus(ok ? 'success' : 'error', message);
      if (!hasSwal()) return;
      window.Swal.fire({
        ...opts.swal,
        title: ok ? texts.successTitle : texts.errorTitle,
        text: message,
        icon: ok ? 'success' : 'error',
        iconColor: ok ? opts.iconColors.success : opts.iconColors.error,
        confirmButtonText: ok ? texts.successButton : texts.errorButton
      });
    };

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      if (!(await askConfirm())) return;

      showSending();

      try {
        await ensureToken();
        const res = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'Accept': 'application/json' }
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok) {
          showResult(true, data.message);
          form.reset();
          // Fresh token for a next submission (restarts the minimum-time count)
          tokenAt = 0;
          if (tokenInput) fetchToken().catch(() => {});
        } else {
          showResult(false, data.message || texts.genericError);
        }
      } catch (err) {
        showResult(false, texts.networkError);
      } finally {
        resetButton();
      }
    });
  }

  window.FormsKit = { attach, escapeHtml };
})(window);
