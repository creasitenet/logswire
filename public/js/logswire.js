(function () {
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-confirm]');
    if (!trigger) {
      return;
    }
    if (!window.confirm(trigger.getAttribute('data-confirm') || 'Are you sure?')) {
      event.preventDefault();
    }
  });

  document.addEventListener('change', function (event) {
    var input = event.target.closest('[data-autosubmit]');
    if (!input || !input.form) {
      return;
    }
    input.form.requestSubmit();
  });

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-stack-toggle-for]');
    if (toggle) {
      var list = document.getElementById(toggle.getAttribute('data-stack-toggle-for'));
      if (!list) {
        return;
      }
      var inAppOnly = list.hasAttribute('data-stack-in-app-only');
      if (inAppOnly) {
        list.removeAttribute('data-stack-in-app-only');
        toggle.textContent = toggle.getAttribute('data-label-in-app') || 'Show in-app frames only';
      } else {
        list.setAttribute('data-stack-in-app-only', '');
        toggle.textContent = toggle.getAttribute('data-label-all') || 'Show all frames';
      }
      return;
    }

    var btn = event.target.closest('[data-copy]');
    if (!btn) {
      return;
    }
    var text = btn.getAttribute('data-copy');
    if (!text) {
      return;
    }
    navigator.clipboard.writeText(text).then(function () {
      var label = btn.getAttribute('data-copy-label') || 'Copy';
      var done = btn.getAttribute('data-copied-label') || 'Copied';
      btn.textContent = done;
      setTimeout(function () {
        btn.textContent = label;
      }, 1600);
    });
  });

  document.body.addEventListener('htmx:beforeRequest', function (event) {
    var detail = event.detail;
    if (!detail || !detail.target) {
      return;
    }
    if (detail.target.id === 'issues-results') {
      detail.target.classList.add('is-loading');
    }
  });
  document.body.addEventListener('htmx:afterRequest', function (event) {
    var detail = event.detail;
    if (!detail || !detail.target) {
      return;
    }
    if (detail.target.id === 'issues-results') {
      detail.target.classList.remove('is-loading');
    }
  });

  document.body.addEventListener('htmx:beforeRequest', function (event) {
    var elt = event.detail && event.detail.elt;
    if (!elt || !elt.closest('#issues-filters')) {
      return;
    }
    var form = document.getElementById('issues-filters');
    var page = form && form.querySelector('input[name="page"]');
    if (page) {
      page.value = '1';
    }
  });
})();
