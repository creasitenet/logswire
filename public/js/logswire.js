(function () {
  // ——— Confirmation modal ———
  // Forms opt in with data-confirm on the <form>, links with data-confirm on the
  // <a>. Native <dialog> keeps it accessible and CSP-safe (no inline JS).

  var dialog = document.getElementById('confirm-dialog');
  var pendingAction = null;
  var lastTrigger = null;

  function confirmAction(trigger, run) {
    if (!dialog || typeof dialog.showModal !== 'function' || dialog.open) {
      return;
    }

    pendingAction = run;
    lastTrigger = trigger;

    var title = dialog.querySelector('#confirm-dialog-title');
    var message = dialog.querySelector('#confirm-dialog-message');
    var accept = dialog.querySelector('[data-confirm-accept]');

    if (title) {
      title.textContent = trigger.getAttribute('data-confirm-title') || 'Confirm action';
    }
    if (message) {
      message.textContent = trigger.getAttribute('data-confirm') || 'Are you sure?';
    }
    if (accept) {
      accept.textContent = trigger.getAttribute('data-confirm-ok') || 'Confirm';
    }

    dialog.showModal();
  }

  function settle(action) {
    if (!dialog) {
      return;
    }

    var run = pendingAction;
    var trigger = lastTrigger;
    pendingAction = null;
    lastTrigger = null;

    dialog.close(action);

    if ('confirm' === action && run) {
      run();
    } else if (trigger && typeof trigger.focus === 'function') {
      trigger.focus();
    }
  }

  if (dialog) {
    var accept = dialog.querySelector('[data-confirm-accept]');
    var cancel = dialog.querySelector('[data-confirm-cancel]');

    if (accept) {
      accept.addEventListener('click', function () { settle('confirm'); });
    }
    if (cancel) {
      cancel.addEventListener('click', function () { settle('cancel'); });
    }

    // ESC and backdrop both cancel. Do not rely on the <dialog> close event:
    // some environments do not dispatch it, which would silently drop the action.
    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      settle('cancel');
    });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        settle('cancel');
      }
    });
  }

  // Capture phase: stops the submission before HTMX or any other listener sees it.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.matches || !form.matches('form[data-confirm]')) {
      return;
    }
    if (form.dataset.confirmed === '1') {
      delete form.dataset.confirmed;
      return;
    }
    event.preventDefault();
    confirmAction(form, function () {
      form.dataset.confirmed = '1';
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    });
  }, true);

  document.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a[data-confirm]') : null;
    if (!link || link.dataset.confirmed === '1') {
      return;
    }
    event.preventDefault();
    confirmAction(link, function () {
      link.dataset.confirmed = '1';
      window.location.assign(link.href);
    });
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

  // ——— Live monitor timestamps ———
  // Server sends absolute UTC times as the no-JS fallback; these helpers turn
  // them into "42s ago" / "in 18s" and keep them ticking between htmx polls.

  function relativePast(ts, now) {
    var diff = Math.max(0, now - ts);
    if (diff < 60) {
      return Math.floor(diff) + 's ago';
    }
    if (diff < 3600) {
      return Math.floor(diff / 60) + 'm ago';
    }
    if (diff < 86400) {
      return Math.floor(diff / 3600) + 'h ago';
    }
    return Math.floor(diff / 86400) + 'd ago';
  }

  // Same buckets without the trailing "ago", for "Up for 18h".
  function relativeSpan(ts, now) {
    return relativePast(ts, now).replace(/ ago$/, '');
  }

  function countdown(ts, now, intervalSec) {
    var diff = ts - now;
    if (diff > 1) {
      if (diff < 60) {
        return 'in ' + Math.ceil(diff) + 's';
      }
      if (diff < 3600) {
        return 'in ' + Math.ceil(diff / 60) + 'm';
      }
      return 'in ' + Math.ceil(diff / 3600) + 'h';
    }
    if (diff >= -intervalSec) {
      return 'due…';
    }
    var late = -diff;
    if (late < 60) {
      return 'overdue ' + Math.floor(late) + 's';
    }
    if (late < 3600) {
      return 'overdue ' + Math.floor(late / 60) + 'm';
    }
    return 'overdue ' + Math.floor(late / 3600) + 'h';
  }

  function tickLiveTimestamps() {
    var now = Math.floor(Date.now() / 1000);

    document.querySelectorAll('[data-ts-relative]').forEach(function (el) {
      var ts = parseInt(el.getAttribute('data-ts-relative'), 10);
      if (ts > 0) {
        el.textContent = relativePast(ts, now);
      }
    });

    document.querySelectorAll('[data-ts-countdown]').forEach(function (el) {
      var ts = parseInt(el.getAttribute('data-ts-countdown'), 10);
      var interval = parseInt(el.getAttribute('data-ts-interval') || '60', 10);
      if (ts > 0) {
        el.textContent = countdown(ts, now, interval);
      }
    });

    document.querySelectorAll('[data-ts-span]').forEach(function (el) {
      var ts = parseInt(el.getAttribute('data-ts-span'), 10);
      if (ts > 0) {
        el.textContent = relativeSpan(ts, now);
      }
    });

    var poller = window.htmx && document.querySelector('[data-live-poller]');
    document.querySelectorAll('[data-live-pill]').forEach(function (el) {
      el.hidden = !poller;
    });
  }

  tickLiveTimestamps();
  window.setInterval(tickLiveTimestamps, 1000);
  document.body.addEventListener('htmx:afterSwap', tickLiveTimestamps);
  document.body.addEventListener('htmx:oobAfterSwap', tickLiveTimestamps);
})();
