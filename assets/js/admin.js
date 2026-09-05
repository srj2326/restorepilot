/* ── Modal dialog controller ──
 *
 * Four dialogs in this admin screen each grew their own keyboard handling, and
 * between them they covered different parts of the same job: two closed on
 * Escape and two did not, one set initial focus, none contained Tab, none gave
 * focus back to the control that opened them, and none stopped the page behind
 * from being reached. A keyboard user could tab straight out of a modal into
 * the page it was covering, and a screen-reader user could read a form that was
 * visually obscured.
 *
 * So the mechanics live here once, and the dialogs keep their own rules about
 * what is allowed to close them. This deliberately does not touch showing and
 * hiding: each dialog already has its own open/close (display, is-active, or
 * removal from the DOM), and its own acknowledgement and confirmation checks,
 * which are unchanged. activate() is called once a dialog is visible and
 * deactivate() once it is not.
 */
window.RestorePilotDialog = (function () {
  var FOCUSABLE = [
    'a[href]', 'area[href]', 'button', 'input', 'select', 'textarea',
    'iframe', 'object', 'embed', '[contenteditable]', '[tabindex]'
  ].join(',');

  // inert removes an element from focus, hit-testing and the accessibility
  // tree in one attribute. Where it is missing, the Tab trap below still keeps
  // the keyboard in, and aria-hidden keeps assistive technology out.
  var supportsInert = ('inert' in document.createElement('div'));
  var stack = [];
  var listening = false;

  function isVisible(el) {
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }

  /** Focusable, in DOM order, as it stands right now.
   *
   * Recomputed on every Tab rather than cached at open: these dialogs reveal
   * and hide fields as checkboxes are ticked (the new-admin fields, the purge
   * options), so a list captured at open time goes stale while the dialog is
   * still on screen.
   */
  function focusableWithin(root) {
    return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
      if (el.disabled) { return false; }
      if (el.getAttribute('tabindex') === '-1') { return false; }
      if (el.type === 'hidden') { return false; }
      if (el.getAttribute('aria-hidden') === 'true') { return false; }
      return isVisible(el);
    });
  }

  /** Everything outside the dialog, walking up to <body>. */
  function eachBackgroundSibling(dialog, fn) {
    var node = dialog;
    while (node && node !== document.body && node.parentNode) {
      var parent = node.parentNode;
      if (parent.children) {
        Array.prototype.forEach.call(parent.children, function (sib) {
          if (sib !== node) { fn(sib); }
        });
      }
      node = parent;
    }
  }

  function applyInert(dialog) {
    var touched = [];
    eachBackgroundSibling(dialog, function (sib) {
      // Already held by a dialog outside this one; leave it to that one to
      // release, or the outer dialog's background comes back early.
      if (sib.hasAttribute('data-rp-inerted')) { return; }
      sib.setAttribute('data-rp-inerted', '');
      if (supportsInert) {
        sib.inert = true;
      } else if (!sib.hasAttribute('aria-hidden')) {
        sib.setAttribute('aria-hidden', 'true');
        sib.setAttribute('data-rp-aria-added', '');
      }
      touched.push(sib);
    });
    return touched;
  }

  function releaseInert(touched) {
    touched.forEach(function (sib) {
      sib.removeAttribute('data-rp-inerted');
      if (supportsInert) { sib.inert = false; }
      if (sib.hasAttribute('data-rp-aria-added')) {
        sib.removeAttribute('aria-hidden');
        sib.removeAttribute('data-rp-aria-added');
      }
    });
  }

  function top() {
    return stack.length ? stack[stack.length - 1] : null;
  }

  function onKeydown(e) {
    var handle = top();
    if (!handle) { return; }

    if (e.key === 'Escape' || e.key === 'Esc') {
      if (handle.opts.onRequestClose && handle.opts.dismissOnEscape !== false) {
        e.preventDefault();
        handle.opts.onRequestClose('escape');
      }
      return;
    }

    if (e.key !== 'Tab') { return; }

    var items = focusableWithin(handle.el);
    if (!items.length) {
      e.preventDefault();
      handle.el.focus();
      return;
    }
    var first  = items[0];
    var last   = items[items.length - 1];
    var active = document.activeElement;

    if (!handle.el.contains(active)) {
      e.preventDefault();
      (e.shiftKey ? last : first).focus();
      return;
    }
    if (e.shiftKey && active === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  }

  // Tab is not the only way focus leaves: a click on the page behind reaches
  // the background wherever inert is unavailable, and returning to the tab
  // from elsewhere can land focus on <body>.
  function onFocusIn(e) {
    var handle = top();
    if (!handle) { return; }
    if (handle.el.contains(e.target)) { return; }
    var items = focusableWithin(handle.el);
    (items[0] || handle.el).focus();
  }

  function ensureListening() {
    if (listening) { return; }
    listening = true;
    document.addEventListener('keydown', onKeydown, true);
    document.addEventListener('focusin', onFocusIn, true);
  }

  /**
   * opts:
   *   initialFocus      function returning the element to focus, optional
   *   onRequestClose    called with 'escape' or 'backdrop'; without it neither
   *                     gesture closes anything
   *   dismissOnEscape   default true when onRequestClose is given
   *   dismissOnBackdrop default true when onRequestClose is given
   */
  function attach(el, opts) {
    opts = opts || {};
    var isActive = false;
    var opener   = null;
    var touched  = [];

    var handle = {
      el: el,
      opts: opts,
      isActive: function () { return isActive; },
      activate: function () {
        if (isActive || !el) { return; }
        isActive = true;
        opener = document.activeElement;
        touched = applyInert(el);
        // So the dialog itself can hold focus when it contains nothing that can.
        if (!el.hasAttribute('tabindex')) { el.setAttribute('tabindex', '-1'); }
        stack.push(handle);
        ensureListening();
        // After the frame that made it visible: an element that is still
        // display:none cannot take focus, and silently does not.
        window.setTimeout(function () {
          if (!isActive) { return; }
          var target = (opts.initialFocus && opts.initialFocus()) || focusableWithin(el)[0] || el;
          try { target.focus(); } catch (_) {}
        }, 0);
      },
      deactivate: function () {
        if (!isActive) { return; }
        isActive = false;
        var i = stack.indexOf(handle);
        if (i !== -1) { stack.splice(i, 1); }
        releaseInert(touched);
        touched = [];
        // Back where the user was, so closing a dialog does not drop them at
        // the top of the page.
        if (opener && document.contains(opener) && typeof opener.focus === 'function') {
          try { opener.focus(); } catch (_) {}
        }
        opener = null;
      }
    };

    if (opts.onRequestClose && opts.dismissOnBackdrop !== false) {
      el.addEventListener('click', function (e) {
        if (e.target === el && isActive) { opts.onRequestClose('backdrop'); }
      });
    }

    return handle;
  }

  return { attach: attach, focusableWithin: focusableWithin };
})();

(function () {
  var tabLinks = document.querySelectorAll('.nav-tab-wrapper .nav-tab');
  var panels = {
    backup: document.getElementById('rp-panel-backup'),
    daily: document.getElementById('rp-panel-daily'),
    restore: document.getElementById('rp-panel-restore'),
    logs: document.getElementById('rp-panel-logs'),
    settings: document.getElementById('rp-panel-settings'),
    danger: document.getElementById('rp-panel-danger')
  };
  document.querySelectorAll('.rp-disclosure').forEach(function (disclosure, index) {
    var trigger = disclosure.querySelector('.rp-disclosure__summary');
    var panel = disclosure.querySelector('.rp-disclosure__panel');
    if (!trigger || !panel) return;

    if (!panel.id) {
      panel.id = 'rp-disclosure-panel-' + index;
    }
    trigger.setAttribute('aria-controls', panel.id);
    trigger.setAttribute('aria-expanded', disclosure.classList.contains('is-open') ? 'true' : 'false');

    trigger.addEventListener('click', function () {
      var isOpen = !disclosure.classList.contains('is-open');
      disclosure.classList.toggle('is-open', isOpen);
      trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

      var row = disclosure.closest('tr');
      if (row) {
        row.classList.toggle('is-advanced-open', isOpen);
      }
    });
  });

  function switchTab(tabName, updateUrl) {
    if (!panels[tabName]) return;
    Object.keys(panels).forEach(function (name) {
      if (panels[name]) {
        panels[name].classList.toggle('is-active', name === tabName);
      }
    });
    tabLinks.forEach(function (link) {
      link.classList.toggle('nav-tab-active', link.getAttribute('data-rp-tab') === tabName);
    });
    if (updateUrl && window.history && window.history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tabName);
      url.searchParams.delete('rp_error');
      url.searchParams.delete('rp_notice');
      window.history.replaceState(null, '', url.toString());
      document.querySelectorAll('.rp-admin-notice').forEach(function (notice) {
        notice.style.display = 'none';
      });
    }
    if (tabName === 'logs') {
      refreshLogs();
    }
  }
  window.restorepilotSwitchTab = switchTab;
  function refreshLogs() {
    var logOutput = document.getElementById('rp-log-output');
    if (!logOutput || !window.fetch) return;
    var data = new FormData();
    data.set('action', 'restorepilot_read_log');
    data.set('_ajax_nonce', restorePilotData.nonce);
    fetch(ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    }).then(function (response) {
      return response.json();
    }).then(function (json) {
      if (json && json.success && json.data && typeof json.data.log === 'string') {
        fullLogText = json.data.log || restorePilotData.i18n.noLogEntriesYet;
        applyLogFilter();
      }
    }).catch(function () {});
  }
  var activeLogFilter = 'all';
  var fullLogText = '';
  function applyLogFilter() {
    var logOutput = document.getElementById('rp-log-output');
    if (!logOutput) return;
    var text = fullLogText || logOutput.textContent || '';
    if (activeLogFilter !== 'all') {
      var needle = activeLogFilter === 'error' ? 'fail' : activeLogFilter;
      text = text.split(/\r?\n/).filter(function (line) {
        var lower = line.toLowerCase();
        return lower.indexOf(needle) !== -1 || (activeLogFilter === 'error' && (lower.indexOf('error') !== -1 || lower.indexOf('fatal') !== -1));
      }).join('\n');
    }
    logOutput.textContent = text || restorePilotData.i18n.noMatchingLogEntries;
  }
  document.querySelectorAll('.rp-log-filter').forEach(function (button) {
    button.addEventListener('click', function () {
      activeLogFilter = button.getAttribute('data-rp-log-filter') || 'all';
      document.querySelectorAll('.rp-log-filter').forEach(function (item) {
        item.classList.toggle('is-active', item === button);
      });
      applyLogFilter();
    });
  });
  var refreshLogButton = document.getElementById('rp-refresh-log');
  if (refreshLogButton) {
    refreshLogButton.addEventListener('click', function () {
      refreshLogs();
    });
  }
  var clearLogButton = document.getElementById('rp-clear-log');
  if (clearLogButton && window.fetch) {
    clearLogButton.addEventListener('click', function () {
      if (!window.confirm(restorePilotData.i18n.confirmClearLogs)) return;
      clearLogButton.disabled = true;
      var data = new FormData();
      data.set('action', 'restorepilot_clear_log');
      data.set('_ajax_nonce', restorePilotData.nonce);
      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      }).then(function (response) {
        return response.json();
      }).then(function (json) {
        if (json && json.success) {
          refreshLogs();
        }
      }).catch(function () {}).finally(function () {
        clearLogButton.disabled = false;
      });
    });
  }
  tabLinks.forEach(function (link) {
    link.addEventListener('click', function (event) {
      var tabName = link.getAttribute('data-rp-tab');
      if (!tabName || !panels[tabName]) return;
      event.preventDefault();
      switchTab(tabName, true);
    });
  });
  window.setInterval(function () {
    if (panels.logs && panels.logs.classList.contains('is-active')) {
      refreshLogs();
    }
  }, 5000);

  var auto = document.getElementById('rp_auto_urls');
  var manual = document.getElementById('rp_manual_urls');
  var hiddenAuto = document.querySelector('input[name="auto_detect_urls"]');
  function syncAdvanced() {
    if (!auto || !manual || !hiddenAuto) return;
    manual.style.display = auto.checked ? 'none' : 'block';
    hiddenAuto.value = auto.checked ? '1' : '';
  }
  if (auto) auto.addEventListener('change', syncAdvanced);
  syncAdvanced();

  var backupForm = document.getElementById('rp-backup-form');
  var backupButton = document.getElementById('rp-backup-button');
  var cancelButton = document.getElementById('rp-cancel-backup-button');
  var progress = document.getElementById('rp-backup-progress');
  var progressBar = document.getElementById('rp-backup-progress-bar');
  var progressText = document.getElementById('rp-backup-progress-text');
  var selectAllFiles = document.getElementById('rp-select-all-files');
  var clearFiles = document.getElementById('rp-clear-files');

  if (backupForm && backupButton && cancelButton && progress && progressBar && progressText && window.fetch) {
    var activePoll = null;
    var activeClock = null;
    var activeJob = null;
    var latestStatus = null;

    function nowSeconds() {
      return Math.floor(Date.now() / 1000);
    }

    function formatElapsed(seconds) {
      seconds = Math.max(0, parseInt(seconds || 0, 10));
      if (seconds < 60) { return seconds + 's'; }
      var mins = Math.floor(seconds / 60);
      var secs = seconds % 60;
      if (mins < 60) { return mins + 'm ' + secs + 's'; }
      return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
    }

    // Deliberately reports WHAT is happening and HOW LONG it has been going,
    // rather than predicting how long is left.
    //
    // The old countdown extrapolated linearly from percent-so-far, but a
    // backup's phases do not run at comparable speeds — exporting a database
    // of many small tables is far slower per percent than collecting files —
    // so the figure was routinely wrong and, worse, went UP as often as down
    // (observed live: "257 seconds left", then "406 seconds left"). An
    // estimate that grows is worse than no estimate: it reads as a stuck or
    // broken job. The phase label is genuinely useful in exactly the moments
    // the countdown was worst — a minute at 8% looks stuck, until it says
    // "Exporting database" and the reason is obvious.
    function progressSummary(percent, status, fallbackText) {
      percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
      status = status || {};
      if (status.status === 'error' || status.status === 'stale' || status.status === 'canceled') {
        return fallbackText || status.message || (percent + '% ' + restorePilotData.i18n.done);
      }

      var parts = [percent + '% ' + restorePilotData.i18n.done];

      if (status.phase_label) {
        parts.push(status.phase_label);
      } else if (status.phase === 'finalizing' && percent < 100) {
        parts.push(restorePilotData.i18n.finalizingBackup);
      }

      var receivedAt = status._receivedAt || Date.now();
      var serverNow = status.server_time
        ? parseInt(status.server_time, 10) + Math.floor((Date.now() - receivedAt) / 1000)
        : nowSeconds();
      var created = parseInt(status.created || (activeJob && activeJob.startedAt) || 0, 10);
      var elapsed = created > 0 ? Math.max(0, serverNow - created) : 0;
      if (elapsed >= 2) {
        parts.push(formatElapsed(elapsed) + ' ' + restorePilotData.i18n.elapsed);
      }

      return parts.join(' • ');
    }

    // Highest percentage shown for the job currently being watched. A backup
    // never un-does work, so the bar must never travel backwards: the server
    // legitimately reports a lower number at times (a fresh page render
    // starts at 0, and a resumed chunk re-reports its phase's floor), and
    // showing that made a healthy backup look like it had restarted — 8%,
    // then 0%, then 30%. Reset in clearActiveJob() when a new job begins.
    var highestPercent = 0;

    function setProgressLabel(text) {
      var labelEl = document.getElementById('rp-backup-progress-label');
      if (labelEl && text) { labelEl.textContent = text; }
    }

    function setProgress(percent, text, status) {
      percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
      // A finished job (complete/canceled/failed) reports its true final
      // figure, including 100 — only live progress is clamped upwards.
      var isFinal = status && (status.status === 'complete' || status.status === 'canceled' || status.status === 'error' || status.status === 'stale');
      if (isFinal) {
        highestPercent = percent;
      } else {
        percent = Math.max(highestPercent, percent);
        highestPercent = percent;
      }
      progressBar.style.width = percent + '%';
      var pctEl = document.getElementById('rp-backup-progress-pct');
      if (pctEl) { pctEl.textContent = percent + '%'; }
      if (status) {
        if (!status._receivedAt) {
          status._receivedAt = Date.now();
        }
        latestStatus = status;
      }
      progressText.textContent = progressSummary(percent, status || latestStatus || {}, text);
    }

    function stopPolling() {
      if (activePoll) {
        window.clearInterval(activePoll);
        activePoll = null;
      }
      if (activeClock) {
        window.clearInterval(activeClock);
        activeClock = null;
      }
    }

    function clearActiveJob() {
      activeJob = null;
      latestStatus = null;
      highestPercent = 0;
      window.localStorage.removeItem('restorepilot_active_job');
    }

    function setRunningUi() {
      progress.classList.add('is-active');
      setProgressLabel(restorePilotData.i18n.backupInProgress);
      backupButton.disabled = true;
      cancelButton.disabled = false;
      cancelButton.style.display = '';
      progressBar.style.background = '#2271b1';
      if (!activeClock) {
        activeClock = window.setInterval(function () {
          if (latestStatus) {
            setProgress(latestStatus.progress || 0, latestStatus.message || '', latestStatus);
          }
        }, 1000);
      }
    }

    function finishJob(message, color, reload) {
      var finishedJob = activeJob;
      var finishedStatus = latestStatus || {};
      var finalStatus = reload ? 'complete' : (finishedStatus.status || (color === '#b32d2e' ? 'error' : (color === '#646970' ? 'canceled' : 'stopped')));
      stopPolling();
      // The heading has to stop saying "Backup in progress" the moment the
      // job is over, or a canceled backup keeps advertising itself as still
      // running — with a live countdown beside it.
      setProgressLabel(
        finalStatus === 'complete' ? restorePilotData.i18n.complete
          : finalStatus === 'canceled' ? restorePilotData.i18n.canceled
          : finalStatus === 'error' || finalStatus === 'stale' ? restorePilotData.i18n.failed
          : restorePilotData.i18n.stopped
      );
      backupButton.disabled = false;
      cancelButton.disabled = false;
      cancelButton.style.display = 'none';
      progressBar.style.background = color || '#2271b1';
      setProgress(100, message, {
        status: finalStatus,
        phase: reload ? 'complete' : (finishedStatus.phase || 'stopped'),
        phase_label: reload ? restorePilotData.i18n.complete : (finishedStatus.phase_label || restorePilotData.i18n.stopped),
        updated: nowSeconds(),
        server_time: nowSeconds(),
        created: finishedStatus.created || (finishedJob && finishedJob.startedAt ? finishedJob.startedAt : nowSeconds()),
        files_scanned: finishedStatus.files_scanned || 0,
        bytes_scanned: finishedStatus.bytes_scanned || 0
      });
      clearActiveJob();
      if (reload) {
        window.setTimeout(function () {
          window.location.reload();
        }, 900);
      }
    }

    function triggerBackupRunner(jobId, nonce) {
      var runnerData = new FormData();
      runnerData.set('action', 'restorepilot_run_backup_job_admin');
      runnerData.set('job_id', jobId);
      runnerData.set('_ajax_nonce', nonce || '');

      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: runnerData
      }).catch(function () {});
    }

    function pollBackupJob(jobId, nonce) {
      if (!jobId) return;

      activeJob = activeJob && activeJob.jobId === jobId ? activeJob : { jobId: jobId, nonce: nonce || '', startedAt: nowSeconds() };
      stopPolling();
      setRunningUi();

      // A poll that fails once is not a failed backup. The backup runs
      // server-side and does not care whether this page could reach it — a
      // dropped request, a moment of server load, or a status request that
      // lands while the job record is being rewritten would otherwise mark a
      // perfectly healthy backup as failed and stop watching it for good.
      var consecutiveErrors = 0;
      var MAX_CONSECUTIVE_ERRORS = 4;

      function pollOnce() {
        var statusData = new FormData();
        statusData.set('action', 'restorepilot_backup_status');
        statusData.set('job_id', jobId);
        statusData.set('_ajax_nonce', nonce || '');

        fetch(ajaxurl, {
          method: 'POST',
          credentials: 'same-origin',
          body: statusData
        }).then(function (response) {
          return response.json();
        }).then(function (statusJson) {
          if (!statusJson || !statusJson.success) {
            throw new Error(statusJson && statusJson.data && statusJson.data.message ? statusJson.data.message : restorePilotData.i18n.backupJobNotFound);
          }
          consecutiveErrors = 0;
          var status = statusJson.data || {};
          setProgress(status.progress || 12, status.message || restorePilotData.i18n.backupRunning, status);

          if (status.status === 'complete') {
            finishJob(status.message || restorePilotData.i18n.backupComplete, '#2271b1', true);
          }

          if (status.status === 'error' || status.status === 'stale') {
            finishJob(status.message || restorePilotData.i18n.backupFailed, '#b32d2e', false);
          }

          if (status.status === 'canceled') {
            finishJob(status.message || restorePilotData.i18n.backupCanceled, '#646970', false);
          }
        }).catch(function (error) {
          consecutiveErrors++;
          if (consecutiveErrors < MAX_CONSECUTIVE_ERRORS) {
            return; // keep polling; the next tick may well succeed
          }
          // Out of retries. Reload rather than assert failure: the backup may
          // have finished while this page could not see it, and the freshly
          // rendered page shows the truth — including the new backup in the
          // list — instead of a red error over a backup that actually worked.
          finishJob(error.message || restorePilotData.i18n.backupStatusError, '#646970', true);
        });
      }

      pollOnce();
      activePoll = window.setInterval(pollOnce, 2000);
    }

    try {
      var stored = JSON.parse(window.localStorage.getItem('restorepilot_active_job') || 'null');
      if (stored && stored.jobId && stored.nonce) {
        triggerBackupRunner(stored.jobId, stored.nonce);
        pollBackupJob(stored.jobId, stored.nonce);
      }
    } catch (_) {}

    backupForm.addEventListener('submit', function (event) {
      event.preventDefault();

      backupButton.disabled = true;
      progress.classList.add('is-active');

      setProgress(8, restorePilotData.i18n.startingBackup, {
        phase: 'starting',
        phase_label: restorePilotData.i18n.startingBackupLabel,
        created: nowSeconds(),
        updated: nowSeconds(),
        server_time: nowSeconds()
      });

      var data = new FormData(backupForm);
      data.set('action', 'restorepilot_ajax_backup');

      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      }).then(function (response) {
        return response.json();
      }).then(function (json) {
        if (!json || !json.success) {
          throw new Error(json && json.data && json.data.message ? json.data.message : restorePilotData.i18n.backupFailed);
        }
        var jobId = json.data && json.data.job_id ? json.data.job_id : '';
        if (!jobId) {
          throw new Error(restorePilotData.i18n.backupJobNotStarted);
        }
        setProgress(12, restorePilotData.i18n.backupRunningBackground, {
          phase: 'queued',
          phase_label: restorePilotData.i18n.queued,
          created: nowSeconds(),
          updated: nowSeconds(),
          server_time: nowSeconds()
        });
        var nonce = data.get('_wpnonce') || '';
        activeJob = { jobId: jobId, nonce: nonce, startedAt: nowSeconds() };
        window.localStorage.setItem('restorepilot_active_job', JSON.stringify(activeJob));
        triggerBackupRunner(jobId, nonce);
        pollBackupJob(jobId, nonce);
      }).catch(function (error) {
        finishJob(error.message || restorePilotData.i18n.backupFailed, '#b32d2e', false);
      });
    });

    cancelButton.addEventListener('click', function () {
      if (!activeJob || !activeJob.jobId) return;
      if (!window.confirm(restorePilotData.i18n.confirmCancelBackup)) return;

      cancelButton.disabled = true;
      setProgressLabel(restorePilotData.i18n.canceling);
      setProgress(100, restorePilotData.i18n.cancelingBackup, {
        status: 'canceled',
        phase: 'canceling',
        phase_label: restorePilotData.i18n.canceling,
        created: activeJob && activeJob.startedAt ? activeJob.startedAt : nowSeconds(),
        updated: nowSeconds(),
        server_time: nowSeconds(),
        files_scanned: latestStatus && latestStatus.files_scanned ? latestStatus.files_scanned : 0,
        bytes_scanned: latestStatus && latestStatus.bytes_scanned ? latestStatus.bytes_scanned : 0
      });

      var cancelData = new FormData();
      cancelData.set('action', 'restorepilot_cancel_backup');
      cancelData.set('job_id', activeJob.jobId);
      cancelData.set('_ajax_nonce', activeJob.nonce || '');

      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: cancelData
      }).then(function (response) {
        return response.json();
      }).then(function (json) {
        if (!json || !json.success) {
          throw new Error(json && json.data && json.data.message ? json.data.message : restorePilotData.i18n.backupCancelError);
        }
        finishJob(json.data && json.data.message ? json.data.message : restorePilotData.i18n.backupCanceled, '#646970', false);
      }).catch(function (error) {
        cancelButton.disabled = false;
        setProgress(100, error.message || restorePilotData.i18n.backupCancelError);
        progressBar.style.background = '#b32d2e';
      });
    });

    if (selectAllFiles) {
      selectAllFiles.addEventListener('click', function () {
        document.querySelectorAll('input[name="backup_paths[]"]').forEach(function (input) {
          input.checked = true;
        });
      });
    }

    if (clearFiles) {
      clearFiles.addEventListener('click', function () {
        document.querySelectorAll('input[name="backup_paths[]"]').forEach(function (input) {
          input.checked = false;
        });
      });
    }
  }

  var restoreForm = document.getElementById('rp-restore-form');
  if (restoreForm) {
    var restoreConfirmModal = document.getElementById('rp-restore-confirm-modal');
    var restoreConfirmCancel = document.getElementById('rp-restore-confirm-cancel');
    var restoreConfirmContinue = document.getElementById('rp-restore-confirm-continue');
    var restoreActionInput = document.getElementById('rp_restore_action');
    var requestedRestoreAction = restoreActionInput ? restoreActionInput.value : 'restorepilot_restore';
    var restoreFileInput = restoreForm.querySelector('input[name="backup_upload[]"]');
    var restoreServerPath = restoreForm.querySelector('input[name="server_backup_path"]');
    var restoreActions = restoreForm.querySelector('.rp-restore-actions');
    var restoreReadyHint = restoreForm.querySelector('.rp-restore-ready-hint');
    function restoreHasInput() {
      var hasFile = !!(restoreFileInput && restoreFileInput.files && restoreFileInput.files.length);
      var hasServerPath = !!(restoreServerPath && restoreServerPath.value.trim());
      return hasFile || hasServerPath;
    }
    function syncRestoreReadiness(forceDisabled) {
      var ready = restoreHasInput();
      if (restoreActions) {
        restoreActions.classList.toggle('is-waiting', !ready);
      }
      restoreForm.querySelectorAll('[data-rp-action]').forEach(function (button) {
        button.disabled = !!forceDisabled || !ready;
      });
      if (restoreReadyHint) {
        restoreReadyHint.style.display = ready ? 'none' : '';
      }
    }
    if (restoreFileInput) {
      restoreFileInput.addEventListener('change', function () {
        // A newly chosen file makes the previous upload's temp path stale, and
        // that path takes precedence on the server -- so leaving it set would
        // quietly restore the file before this one.
        var stalePath = document.getElementById('rp-restore-uploaded-path');
        if (stalePath) { stalePath.value = ''; }
        syncRestoreReadiness(false);
      });
    }
    if (restoreServerPath) {
      // Clear stale assembled-upload paths on page load — the temp file was
      // consumed by the previous restore and no longer exists on the server.
      if (/restore-upload-/i.test(restoreServerPath.value)) {
        restoreServerPath.value = '';
      }
      restoreServerPath.addEventListener('input', function () {
        syncRestoreReadiness(false);
      });
    }
    syncRestoreReadiness(false);
    restoreForm.querySelectorAll('[data-rp-action]').forEach(function (button) {
      button.addEventListener('click', function () {
        requestedRestoreAction = button.getAttribute('data-rp-action') || 'restorepilot_restore';
        if (restoreActionInput) {
          restoreActionInput.value = requestedRestoreAction;
        }
      });
    });
    function restoreProgressEls() {
      return {
        wrap: document.getElementById('rp-restore-progress'),
        bar: document.getElementById('rp-restore-progress-bar'),
        pct: document.getElementById('rp-restore-progress-pct'),
        text: document.getElementById('rp-restore-progress-text')
      };
    }
    function setRestoreProgressLabel(text) {
      var label = document.getElementById('rp-restore-progress-label');
      if (label && text) {
        label.textContent = text;
      }
    }
    function setRestoreProgressUi(percent, text, color) {
      var els = restoreProgressEls();
      percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
      if (els.wrap) {
        els.wrap.classList.add('is-active');
      }
      if (els.bar) {
        els.bar.style.width = percent + '%';
        if (color) {
          els.bar.style.background = color;
        }
      }
      if (els.pct) {
        els.pct.textContent = percent + '%';
      }
      if (els.text) {
        els.text.textContent = text;
      }
    }
    function setRestoreButtonsDisabled(form, disabled) {
      if (form === restoreForm) {
        syncRestoreReadiness(disabled);
        return;
      }
      form.querySelectorAll('button[type="submit"]').forEach(function (button) {
        button.disabled = disabled;
      });
    }
    function triggerRestoreRunner(jobId) {
      var runnerData = new FormData();
      runnerData.set('action', 'restorepilot_run_restore_job_admin');
      runnerData.set('job_id', jobId);
      runnerData.set('_ajax_nonce', restorePilotData.nonce);
      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: runnerData
      }).catch(function () {});
    }
    var RESTORE_JOB_KEY = 'restorepilot_active_restore_job';
    function storeActiveRestoreJob(jobId, pollToken) {
      try {
        window.localStorage.setItem(RESTORE_JOB_KEY, JSON.stringify({ jobId: jobId, pollToken: pollToken || '', started: (new Date()).getTime() }));
      } catch (_) {}
    }
    function clearActiveRestoreJob() {
      try { window.localStorage.removeItem(RESTORE_JOB_KEY); } catch (_) {}
    }
    // Reload at most once per 15s so a persistent session_expired can never spin
    // the page in a reload loop.
    function reloadForSessionOnce() {
      try {
        var last = parseInt(window.sessionStorage.getItem('restorepilot_last_reload') || '0', 10);
        var now = (new Date()).getTime();
        if (now - last < 15000) {
          setRestoreProgressUi(60, restorePilotData.i18n.restoreInProgressMaintenance || restorePilotData.i18n.restoreRunning, '#2271b1');
          return;
        }
        window.sessionStorage.setItem('restorepilot_last_reload', String(now));
      } catch (_) {}
      window.location.reload();
    }
    function pollRestoreJob(jobId, pollToken, form) {
      var pollTimer = null;
      var consecutiveFailures = 0;
      // Generous ceiling: the maintenance drop-in normally keeps polls answered,
      // but if it can't be installed the DB-swap window may briefly return HTML or
      // 403s. 120 retries x 2.5s = 5 minutes before we give up.
      var MAX_CONSECUTIVE_FAILURES = 120;
      function pollOnce() {
        var statusData = new FormData();
        statusData.set('action', 'restorepilot_restore_status');
        statusData.set('job_id', jobId);
        statusData.set('_ajax_nonce', restorePilotData.nonce);
        if (pollToken) {
          statusData.set('poll_token', pollToken);
        }
        fetch(ajaxurl, {
          method: 'POST',
          credentials: 'same-origin',
          body: statusData
        }).then(function (response) {
          return response.text();
        }).then(function (text) {
          var json;
          try {
            json = JSON.parse(text);
          } catch (e) {
            // Non-JSON response — most likely WordPress maintenance mode is
            // active while the restore runs. Retry; show a neutral "in progress"
            // note rather than an error, since the restore is almost certainly
            // still running in the background.
            consecutiveFailures++;
            setRestoreProgressUi(60, restorePilotData.i18n.restoreInProgressMaintenance || restorePilotData.i18n.restoreRunning, '#2271b1');
            if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
              window.clearInterval(pollTimer);
              setRestoreProgressLabel(restorePilotData.i18n.failed);
              setRestoreProgressUi(100, restorePilotData.i18n.restoreStatusErrorAfterLogin, '#b32d2e');
              setRestoreButtonsDisabled(form, false);
            }
            return;
          }
          // A non-success JSON response (e.g. "Job not found", "Permission denied")
          // is treated as transient — the job may temporarily be unavailable while
          // the DB is being swapped or the session is invalidating. Keep retrying
          // until we get a real status or hit the consecutive-failure ceiling.
          if (!json || !json.success) {
            if (json && json.data && json.data.session_expired) {
              // The session was invalidated by the DB restore. The result is
              // recorded server-side; reload to pick up the success/failure notice.
              window.clearInterval(pollTimer);
              reloadForSessionOnce();
              return;
            }
            consecutiveFailures++;
            setRestoreProgressUi(60, restorePilotData.i18n.restoreInProgressMaintenance || restorePilotData.i18n.restoreRunning, '#2271b1');
            if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
              window.clearInterval(pollTimer);
              var errMsg = json && json.data && json.data.message ? json.data.message : restorePilotData.i18n.restoreStatusErrorAfterLogin;
              setRestoreProgressLabel(restorePilotData.i18n.failed);
              setRestoreProgressUi(100, errMsg, '#b32d2e');
              setRestoreButtonsDisabled(form, false);
            }
            return;
          }
          consecutiveFailures = 0;
          var status = json.data || {};
          setRestoreProgressUi(status.progress || 10, status.message || restorePilotData.i18n.restoreRunning);
          if (status.status === 'complete') {
            window.clearInterval(pollTimer);
            clearActiveRestoreJob();
            setRestoreProgressLabel(restorePilotData.i18n.complete);
            setRestoreProgressUi(100, status.message || restorePilotData.i18n.restoreComplete, '#2271b1');
            // The account is created but still on a throwaway password; the
            // one the operator chose has been held in this page all along and
            // goes now, in a single call, so it never had to be stored.
            if (status.new_admin_awaiting_password && pendingAdminPassword) {
              var adminData = new FormData();
              adminData.set('action', 'restorepilot_set_restore_admin_password');
              adminData.set('job_id', jobId);
              adminData.set('poll_token', pollToken || '');
              adminData.set('_ajax_nonce', restorePilotData.nonce);
              adminData.set('new_password', pendingAdminPassword);
              pendingAdminPassword = '';

              setRestoreProgressUi(100, restorePilotData.i18n.settingAdminPassword, '#2271b1');

              fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: adminData
              }).then(function (response) {
                return response.json();
              }).then(function (adminJson) {
                if (adminJson && adminJson.success) {
                  var who = (adminJson.data && adminJson.data.email) ? adminJson.data.email : '';
                  setRestoreProgressUi(100, who
                    ? restorePilotData.i18n.adminPasswordSetFor.replace('%s', who)
                    : restorePilotData.i18n.adminPasswordSet, '#2271b1');
                } else {
                  // The restore itself succeeded; only the password step did
                  // not. Say exactly that, and name the way in that still
                  // works, rather than reporting a failed restore.
                  setRestoreProgressLabel(restorePilotData.i18n.restoreInProgress);
                  setRestoreProgressUi(100, restorePilotData.i18n.adminPasswordFailed, '#b32d2e');
                }
              }).catch(function () {
                setRestoreProgressUi(100, restorePilotData.i18n.adminPasswordFailed, '#b32d2e');
              }).then(function () {
                window.setTimeout(function () {
                  window.location.href = restorePilotData.loginUrl;
                }, 2500);
              });
              return;
            }

            // Always the login form. The restore replaced the users table and
            // the session tokens stored beside it, so this page's session is
            // gone whether the backup came from this domain or another.
            window.setTimeout(function () {
              window.location.href = restorePilotData.loginUrl;
            }, 1500);
          } else if (status.status === 'error' || status.status === 'stale') {
            window.clearInterval(pollTimer);
            clearActiveRestoreJob();
            setRestoreProgressLabel(restorePilotData.i18n.failed);
            setRestoreProgressUi(100, status.message || restorePilotData.i18n.restoreNeedsAttention, '#b32d2e');
            // Clear the server path so the form resets — the temp file is gone
            // and the user must re-choose a backup to avoid re-running the same restore.
            if (restoreServerPath) {
              restoreServerPath.value = '';
              restoreServerPath.dispatchEvent(new Event('input'));
            }
            setRestoreButtonsDisabled(form, false);
            // Scroll the rollback section into view if the server reported a rollback.
            if (status.has_rollback) {
              var rollbackCard = document.getElementById('rp-rollback-card');
              if (rollbackCard) {
                window.setTimeout(function () { rollbackCard.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 600);
              }
            }
          }
        }).catch(function (error) {
          window.clearInterval(pollTimer);
          setRestoreProgressLabel(restorePilotData.i18n.failed);
          setRestoreProgressUi(100, error.message || restorePilotData.i18n.restoreStatusErrorAfterLogin, '#b32d2e');
          setRestoreButtonsDisabled(form, false);
        });
      }
      pollOnce();
      pollTimer = window.setInterval(pollOnce, 2500);
    }
    function queueBackgroundRestore(form) {
      if (window.restorepilotSwitchTab) {
        window.restorepilotSwitchTab('restore', true);
      }
      setRestoreButtonsDisabled(form, true);
      setRestoreProgressLabel(restorePilotData.i18n.restoreInProgress);
      setRestoreProgressUi(5, restorePilotData.i18n.queuingRestore, '#2271b1');
      var data = new FormData(form);
      data.set('action', 'restorepilot_ajax_restore');
      data.set('_ajax_nonce', restorePilotData.nonce);
      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      }).then(function (response) {
        return response.json();
      }).then(function (json) {
        if (!json || !json.success || !json.data || !json.data.job_id) {
          if (json && json.data && json.data.session_expired) {
            reloadForSessionOnce();
            return;
          }
          throw new Error(json && json.data && json.data.message ? json.data.message : restorePilotData.i18n.restoreNotStarted);
        }
        setRestoreProgressUi(8, json.data.message || restorePilotData.i18n.restoreQueued);
        // Persist so a reload / new tab can resume tracking this restore.
        storeActiveRestoreJob(json.data.job_id, json.data.poll_token || '');
        triggerRestoreRunner(json.data.job_id);
        pollRestoreJob(json.data.job_id, json.data.poll_token || '', form);
      }).catch(function (error) {
        setRestoreProgressLabel(restorePilotData.i18n.failed);
        setRestoreProgressUi(100, error.message || restorePilotData.i18n.restoreNotStarted, '#b32d2e');
        setRestoreButtonsDisabled(form, false);
      });
    }
    window.restorepilotQueueRestoreForm = queueBackgroundRestore;

    // Resume tracking a restore that was started before this page load (e.g. the
    // user reloaded, or the DB restore forced a re-login). The success/failure
    // result is recorded server-side, so we just re-attach the poller.
    try {
      var resumeRestore = JSON.parse(window.localStorage.getItem(RESTORE_JOB_KEY) || 'null');
      // Ignore entries older than 1 hour — the server-side status file is swept on
      // that schedule, so a stale entry could otherwise cause a phantom poll.
      if (resumeRestore && resumeRestore.started && ((new Date()).getTime() - resumeRestore.started > 3600000)) {
        clearActiveRestoreJob();
        resumeRestore = null;
      }
      if (resumeRestore && resumeRestore.jobId) {
        if (window.restorepilotSwitchTab) {
          window.restorepilotSwitchTab('restore', true);
        }
        setRestoreProgressLabel(restorePilotData.i18n.restoreInProgress);
        setRestoreProgressUi(60, restorePilotData.i18n.restoreRunning, '#2271b1');
        setRestoreButtonsDisabled(restoreForm, true);
        pollRestoreJob(resumeRestore.jobId, resumeRestore.pollToken || '', restoreForm);
      }
    } catch (_) {}

    var restoreConfirmDialog = restoreConfirmModal
      ? window.RestorePilotDialog.attach(restoreConfirmModal, {
          // Escape and a backdrop click cancel, and cancelling here means the
          // same as pressing Cancel: the acknowledgement is cleared so it has
          // to be given again.
          onRequestClose: function () {
            resetRestoreConfirmModal();
            closeRestoreConfirmModal();
          },
          initialFocus: function () { return document.getElementById('rp-restore-confirm-check'); }
        })
      : null;

    function openRestoreConfirmModal() {
      if (restoreConfirmModal) {
        restoreConfirmModal.classList.add('is-active');
        if (restoreConfirmDialog) { restoreConfirmDialog.activate(); }
      }
    }
    function closeRestoreConfirmModal() {
      if (restoreConfirmModal) {
        restoreConfirmModal.classList.remove('is-active');
        if (restoreConfirmDialog) { restoreConfirmDialog.deactivate(); }
      }
    }
    document.querySelectorAll('.rp-rollback-restore-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!restoreServerPath) return;
        restoreServerPath.value = btn.getAttribute('data-rp-rollback-path') || '';
        restoreServerPath.dispatchEvent(new Event('input'));
        openRestoreConfirmModal();
      });
    });
    var restoreConfirmCheck = document.getElementById('rp-restore-confirm-check');
    if (restoreConfirmCheck && restoreConfirmContinue) {
      restoreConfirmCheck.addEventListener('change', function () {
        restoreConfirmContinue.disabled = !restoreConfirmCheck.checked;
      });
    }
    // Lives in the modal, outside <form id="rp-restore-form"> in the DOM, so
    // its state has to be copied into a hidden field inside the form before
    // submit — FormData only picks up the form's own descendants.
    var restoreConfirmNewAdmin = document.getElementById('rp-restore-confirm-new-admin');
    var restoreNewAdminHidden = document.getElementById('rp_create_new_admin_hidden');
    var newAdminFields = document.getElementById('rp-new-admin-fields');
    var newAdminEmailInput = document.getElementById('rp-new-admin-email-input');
    var newAdminPasswordInput = document.getElementById('rp-new-admin-password-input');
    var newAdminEmailHidden = document.getElementById('rp_new_admin_email_hidden');
    var newAdminError = document.getElementById('rp-new-admin-error');
    var newAdminPasswordToggle = document.getElementById('rp-new-admin-password-toggle');

    // Reveal the password on request. The field is filled in once and needed
    // immediately afterwards to sign in, so an unseen typo locks the operator
    // out of the site they have just restored.
    if (newAdminPasswordToggle && newAdminPasswordInput) {
      newAdminPasswordToggle.addEventListener('click', function () {
        var revealed = newAdminPasswordInput.getAttribute('type') === 'text';
        newAdminPasswordInput.setAttribute('type', revealed ? 'password' : 'text');
        newAdminPasswordToggle.setAttribute('aria-pressed', revealed ? 'false' : 'true');
        var label = revealed
          ? (restorePilotData.i18n.showPassword || 'Show password')
          : (restorePilotData.i18n.hidePassword || 'Hide password');
        newAdminPasswordToggle.setAttribute('aria-label', label);
        newAdminPasswordToggle.setAttribute('title', label);
        var icon = newAdminPasswordToggle.querySelector('.dashicons');
        if (icon) {
          icon.classList.toggle('dashicons-visibility', revealed);
          icon.classList.toggle('dashicons-hidden', !revealed);
        }
        // Keep focus where the typing is, not on the button that was clicked.
        newAdminPasswordInput.focus();
      });
    }

    // The chosen password is held here and nowhere else until the restore
    // finishes, then sent in a single call. It deliberately never goes into
    // the restore form: the job record it would ride in is mirrored to a file
    // on disk so it can survive the database swap.
    var pendingAdminPassword = '';

    // Whenever the field is cleared, put it back to masked. Otherwise a
    // revealed password stays on screen after the dialog closes, and the next
    // time it opens it is already showing.
    function maskNewAdminPassword() {
      if (!newAdminPasswordInput) { return; }
      newAdminPasswordInput.setAttribute('type', 'password');
      if (!newAdminPasswordToggle) { return; }
      newAdminPasswordToggle.setAttribute('aria-pressed', 'false');
      var label = restorePilotData.i18n.showPassword || 'Show password';
      newAdminPasswordToggle.setAttribute('aria-label', label);
      newAdminPasswordToggle.setAttribute('title', label);
      var icon = newAdminPasswordToggle.querySelector('.dashicons');
      if (icon) {
        icon.classList.add('dashicons-visibility');
        icon.classList.remove('dashicons-hidden');
      }
    }

    function toggleNewAdminFields() {
      if (!newAdminFields || !restoreConfirmNewAdmin) { return; }
      newAdminFields.hidden = !restoreConfirmNewAdmin.checked;
    }
    if (restoreConfirmNewAdmin) {
      restoreConfirmNewAdmin.addEventListener('change', toggleNewAdminFields);
    }

    function showNewAdminError(message) {
      if (!newAdminError) { return; }
      newAdminError.textContent = message || '';
      newAdminError.hidden = !message;
    }

    // Checked here only for the things the browser can know. Whether a name is
    // free cannot be settled now: the restore replaces the users table, so the
    // only meaningful check happens server-side against the restored site.
    function validateNewAdminFields() {
      showNewAdminError('');
      if (!restoreConfirmNewAdmin || !restoreConfirmNewAdmin.checked) { return true; }

      // Both are required: nothing is generated on the operator's behalf any
      // more, so there is no fallback to fall back to.
      var email = newAdminEmailInput ? newAdminEmailInput.value.trim() : '';
      if (email === '' || email.indexOf('@') < 1 || email.indexOf('.', email.indexOf('@')) < 0) {
        showNewAdminError(restorePilotData.i18n.adminEmailInvalid);
        return false;
      }

      var password = newAdminPasswordInput ? newAdminPasswordInput.value : '';
      if (password.length < 8) {
        showNewAdminError(restorePilotData.i18n.adminPasswordTooShort);
        return false;
      }
      return true;
    }

    function resetRestoreConfirmModal() {
      if (restoreConfirmCheck) {
        restoreConfirmCheck.checked = false;
        if (restoreConfirmContinue) restoreConfirmContinue.disabled = true;
      }
      if (restoreConfirmNewAdmin) {
        restoreConfirmNewAdmin.checked = false;
      }
      if (restoreNewAdminHidden) {
        restoreNewAdminHidden.value = '';
      }
      if (newAdminEmailInput) { newAdminEmailInput.value = ''; }
      if (newAdminPasswordInput) { newAdminPasswordInput.value = ''; }
      maskNewAdminPassword();
      if (newAdminEmailHidden) { newAdminEmailHidden.value = ''; }
      showNewAdminError('');
      toggleNewAdminFields();
    }
    if (restoreConfirmCancel) {
      restoreConfirmCancel.addEventListener('click', function () {
        // Reset so re-opening the modal requires re-confirming from scratch.
        resetRestoreConfirmModal();
        closeRestoreConfirmModal();
      });
    }
    if (restoreConfirmContinue) {
      restoreConfirmContinue.addEventListener('click', function () {
        if (!validateNewAdminFields()) { return; }

        var wantsAdmin = !!(restoreConfirmNewAdmin && restoreConfirmNewAdmin.checked);
        if (restoreNewAdminHidden) {
          // Empty, never '0' -- see post_bool(). This is the same convention
        // the auto_detect_urls toggle already uses.
        restoreNewAdminHidden.value = wantsAdmin ? '1' : '';
        }

        // The email rides along with the restore; the password stays in this
        // page and is applied once the restore is done.
        pendingAdminPassword = (wantsAdmin && newAdminPasswordInput) ? newAdminPasswordInput.value : '';
        if (newAdminEmailHidden) {
          newAdminEmailHidden.value = (wantsAdmin && newAdminEmailInput) ? newAdminEmailInput.value.trim() : '';
        }
        if (newAdminPasswordInput) {
          // Nothing further reads the field itself, so do not leave a
          // password sitting in the DOM for the rest of the restore --
          // masked again as well, so a revealed one is not left on screen.
          newAdminPasswordInput.value = '';
          maskNewAdminPassword();
        }

        restoreForm.setAttribute('data-rp-confirmed', '1');
        closeRestoreConfirmModal();
        if (restoreForm.requestSubmit) {
          restoreForm.requestSubmit();
        } else {
          restoreForm.submit();
        }
      });
    }
    restoreForm.addEventListener('submit', function (event) {
      if (restoreForm.getAttribute('data-rp-ready') === '1') {
        return;
      }

      var activeAction = restoreActionInput ? restoreActionInput.value : requestedRestoreAction;
      var isRestoreCheck = activeAction === 'restorepilot_check_restore';

      if (!restoreHasInput()) {
        event.preventDefault();
        syncRestoreReadiness(false);
        setRestoreProgressUi(0, restorePilotData.i18n.chooseBackupToContinue, '#b32d2e');
        return;
      }

      if (!isRestoreCheck && restoreForm.getAttribute('data-rp-confirmed') !== '1') {
        event.preventDefault();
        openRestoreConfirmModal();
        return;
      }

      var fileInput = restoreForm.querySelector('input[name="backup_upload[]"]');
      var serverPath = restoreForm.querySelector('input[name="server_backup_path"]');
      var uploadedPath = document.getElementById('rp-restore-uploaded-path');
      var restoreButtons = restoreForm.querySelectorAll('button[type="submit"]');
      var restoreProgress = document.getElementById('rp-restore-progress');
      var restoreProgressBar = document.getElementById('rp-restore-progress-bar');
      var restoreProgressText = document.getElementById('rp-restore-progress-text');
      var maxUpload = fileInput ? parseInt(fileInput.getAttribute('data-max-upload-size') || '0', 10) : 0;
      var files = fileInput && fileInput.files ? fileInput.files : [];

      if (!files.length || (serverPath && serverPath.value.trim())) {
        if (!isRestoreCheck && window.fetch) {
          event.preventDefault();
          queueBackgroundRestore(restoreForm);
        }
        return;
      }

      var file = files.length === 1 ? files[0] : null;
      var shouldChunkUpload = file && /\.zip$/i.test(file.name) && maxUpload > 0 && file.size > maxUpload && window.fetch;
      if (!shouldChunkUpload) {
        if (!isRestoreCheck && window.fetch) {
          event.preventDefault();
          queueBackgroundRestore(restoreForm);
        }
        return;
      }

      event.preventDefault();
      restoreButtons.forEach(function (button) {
        button.disabled = true;
      });
      if (restoreProgress) {
        restoreProgress.classList.add('is-active');
      }

      var chunkSize = Math.max(128 * 1024, Math.min(5 * 1024 * 1024, Math.floor(maxUpload * 0.5)));
      var totalChunks = Math.ceil(file.size / chunkSize);
      var uploadId = 'restore-' + Date.now() + '-' + Math.random().toString(36).slice(2);

      function setRestoreProgress(percent, text) {
        percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
        if (restoreProgressBar) {
          restoreProgressBar.style.width = percent + '%';
        }
        var pctEl = document.getElementById('rp-restore-progress-pct');
        if (pctEl) { pctEl.textContent = percent + '%'; }
        if (restoreProgressText) {
          restoreProgressText.textContent = text;
        }
      }

      function uploadChunk(index) {
        var start = index * chunkSize;
        var end = Math.min(file.size, start + chunkSize);
        var data = new FormData();
        data.set('action', 'restorepilot_chunk_restore_upload');
        data.set('_ajax_nonce', restorePilotData.nonce);
        data.set('upload_id', uploadId);
        data.set('file_name', file.name);
        data.set('chunk_index', String(index));
        data.set('total_chunks', String(totalChunks));
        data.append('chunk', file.slice(start, end), file.name + '.part' + index);

        setRestoreProgressLabel(restorePilotData.i18n.uploading);
        setRestoreProgress(
          Math.floor((index / totalChunks) * 100),
          restorePilotData.i18n.uploadingBackup + ' ' + (index + 1) + '/' + totalChunks
        );

        return fetch(ajaxurl, {
          method: 'POST',
          credentials: 'same-origin',
          body: data
        }).then(function (response) {
          return response.json();
        }).then(function (json) {
          if (!json || !json.success) {
            if (json && json.data && json.data.session_expired) {
              window.location.reload();
              return;
            }
            throw new Error(json && json.data && json.data.message ? json.data.message : restorePilotData.i18n.backupUploadFailed);
          }

          if (json.data && json.data.complete && json.data.path) {
            if (!isRestoreCheck) {
              setRestoreProgressLabel(restorePilotData.i18n.restoreInProgress);
            }
            setRestoreProgress(100, isRestoreCheck ? restorePilotData.i18n.uploadCompleteChecking : restorePilotData.i18n.uploadCompleteRestoring);
            if (uploadedPath) {
              // Deliberately not the visible "Server backup path" box: that one
              // is for a path the operator typed, and filling it with our temp
              // filename left a stale path behind once the restore deleted it.
              uploadedPath.value = json.data.path;
            }
            if (fileInput) {
              fileInput.disabled = true;
            }
            if (isRestoreCheck) {
              restoreForm.setAttribute('data-rp-ready', '1');
              restoreForm.submit();
            } else {
              queueBackgroundRestore(restoreForm);
            }
            return;
          }

          return uploadChunk(index + 1);
        });
      }

      uploadChunk(0).catch(function (error) {
        restoreButtons.forEach(function (button) {
          button.disabled = false;
        });
        if (restoreProgressBar) {
          restoreProgressBar.style.background = '#b32d2e';
        }
        setRestoreProgressLabel(restorePilotData.i18n.failed);
        setRestoreProgress(100, error.message || restorePilotData.i18n.backupUploadFailed);
      });
    });
  }
})();

/* ── Select-all checkbox ── */
(function () {
  var selectAll = document.getElementById('rp-select-all-backups');
  if (!selectAll) return;
  selectAll.addEventListener('change', function () {
    document.querySelectorAll('input[name="backup_ids[]"]').forEach(function (cb) {
      cb.checked = selectAll.checked;
    });
  });
})();

/* ── Restore-from-existing modal ── */
(function () {
  var modal      = document.getElementById('rp-restore-existing-modal');
  var nameEl     = document.getElementById('rp-restore-existing-name');
  var pathInput  = document.getElementById('rp-restore-existing-path');
  var cancelBtn  = document.getElementById('rp-restore-existing-cancel');
  var submitBtn  = document.getElementById('rp-restore-existing-submit');
  var ackBox     = document.getElementById('rp-restore-existing-ack');
  var existingForm = document.getElementById('rp-restore-existing-form');
  if (!modal) return;

  var dlg = window.RestorePilotDialog.attach(modal, {
    onRequestClose: function () { closeModal(); },
    initialFocus: function () { return ackBox || cancelBtn; }
  });

  function syncSubmit() {
    if (submitBtn) submitBtn.disabled = !(ackBox && ackBox.checked);
  }

  function openModal(name, path) {
    if (nameEl)    nameEl.textContent = name;
    if (pathInput) pathInput.value    = path;
    // Same reason: an earlier upload in this page's lifetime would otherwise
    // outrank the backup just picked from the list.
    var uploadedStale = document.getElementById('rp-restore-uploaded-path');
    if (uploadedStale) { uploadedStale.value = ''; }
    if (ackBox)    { ackBox.checked = false; }
    syncSubmit();
    modal.style.display = 'flex';
    modal.classList.add('is-active');
    dlg.activate();
  }
  function closeModal() {
    modal.style.display = 'none';
    modal.classList.remove('is-active');
    dlg.deactivate();
  }

  if (ackBox) ackBox.addEventListener('change', syncSubmit);

  document.querySelectorAll('.rp-restore-from-existing').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(
        btn.getAttribute('data-backup-name') || '',
        btn.getAttribute('data-backup-path') || ''
      );
    });
  });

  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (existingForm) {
    existingForm.addEventListener('submit', function (event) {
      if (!window.restorepilotQueueRestoreForm) {
        return;
      }
      event.preventDefault();
      closeModal();
      window.restorepilotQueueRestoreForm(existingForm);
    });
  }
})();

/* ── data-confirm delegated handler ── */
document.addEventListener('click', function(e) {
  var el = e.target.closest('[data-confirm]');
  if (!el) return;
  if (!confirm(el.dataset.confirm)) e.preventDefault();
});

/* ── Restore success dialog (render_restore_success_dialog) ── */
(function () {
  var close = document.getElementById('rp-restore-success-close');
  var dialog = document.getElementById('rp-restore-success-dialog');
  if (!close || !dialog) { return; }

  // This one is rendered already open, so there is no opener to go back to --
  // but it is the dialog most likely to be met by a keyboard user, since it
  // appears on the page a restore lands on.
  var dlg = window.RestorePilotDialog.attach(dialog, {
    onRequestClose: function () { dismiss(); },
    initialFocus: function () { return close; }
  });

  function dismiss() {
    dlg.deactivate();
    if (dialog.parentNode) { dialog.parentNode.removeChild(dialog); }
  }

  close.addEventListener('click', dismiss);
  dlg.activate();
})();

/* ── Master Reset modal ── */
(function () {
  var modal      = document.getElementById('rp-master-reset-modal');
  var openBtn    = document.getElementById('rp-master-reset-open');
  var cancelBtn  = document.getElementById('rp-master-reset-cancel');
  var confirmBtn = document.getElementById('rp-master-reset-confirm');
  var input      = document.getElementById('rp-master-reset-confirm-input');
  var ackBox     = document.getElementById('rp-master-reset-ack');
  var purgeBox   = document.getElementById('rp-master-reset-purge-backups');
  var purgeMuBox = document.getElementById('rp-master-reset-purge-mu');
  var errorBox   = document.getElementById('rp-master-reset-error');

  if (!modal || !openBtn) { return; }

  var dlg = window.RestorePilotDialog.attach(modal, {
    onRequestClose: function () { closeModal(); },
    initialFocus: function () { return input; }
  });

  function syncConfirmEnabled() {
    if (!confirmBtn) { return; }
    var typedOk = input && input.value === 'RESET';
    var ackOk   = !ackBox || ackBox.checked;
    confirmBtn.disabled = !(typedOk && ackOk);
  }

  function openModal() {
    if (input)      { input.value = ''; }
    if (ackBox)     { ackBox.checked = false; }
    if (errorBox)   { errorBox.style.display = 'none'; }
    syncConfirmEnabled();
    modal.classList.add('is-active');
    dlg.activate();
  }

  function closeModal() {
    modal.classList.remove('is-active');
    dlg.deactivate();
  }

  openBtn.addEventListener('click', openModal);
  if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }

  if (input)  { input.addEventListener('input', syncConfirmEnabled); }
  if (ackBox) { ackBox.addEventListener('change', syncConfirmEnabled); }

  if (confirmBtn) {
    confirmBtn.addEventListener('click', function () {
      if (input && input.value !== 'RESET') { return; }

      confirmBtn.disabled = true;
      if (cancelBtn) { cancelBtn.disabled = true; }
      if (errorBox)  { errorBox.style.display = 'none'; }

      var resetLabel = (restorePilotData.i18n && restorePilotData.i18n.masterResetting) || 'Resetting…';
      confirmBtn.innerHTML = '<span class="dashicons dashicons-update" style="animation:rotation 1s linear infinite" aria-hidden="true"></span> ' + resetLabel;

      var data = new FormData();
      data.set('action',       'restorepilot_master_reset');
      // Empty, never '0' -- see post_bool(): a submitted '0' used to read as
      // true, which on this action would delete backups nobody asked to lose.
      data.set('purge_backups', (purgeBox && purgeBox.checked) ? '1' : '');
      data.set('purge_mu_plugins', (purgeMuBox && purgeMuBox.checked) ? '1' : '');
      data.set('_ajax_nonce',  restorePilotData.nonce);
      data.set('confirm_word', 'RESET');

      fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success && json.data && json.data.redirect) {
            window.location.href = json.data.redirect;
          } else {
            var msg = (json && json.data && json.data.message)
              || (restorePilotData.i18n && restorePilotData.i18n.masterResetFailed)
              || 'Reset failed.';
            if (errorBox) { errorBox.textContent = msg; errorBox.style.display = 'block'; }
            confirmBtn.disabled = false;
            if (cancelBtn) { cancelBtn.disabled = false; }
            confirmBtn.innerHTML = '<span class="dashicons dashicons-warning" aria-hidden="true"></span> Reset Everything';
          }
        })
        .catch(function () {
          var msg = (restorePilotData.i18n && restorePilotData.i18n.masterResetFailed) || 'Reset failed.';
          if (errorBox) { errorBox.textContent = msg; errorBox.style.display = 'block'; }
          confirmBtn.disabled = false;
          if (cancelBtn) { cancelBtn.disabled = false; }
          confirmBtn.innerHTML = '<span class="dashicons dashicons-warning" aria-hidden="true"></span> Reset Everything';
        });
    });
  }
})();
