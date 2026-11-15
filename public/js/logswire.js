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
