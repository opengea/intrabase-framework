/**
 * Intrabase – Core JavaScript
 * Vanilla JS, no dependencies.
 * Version: 1.0.0
 */


/* ==========================================================================
   0. i18n – localized strings from PHP (injected by Layout.php)
   ========================================================================== */
var ibI18n = window.ibI18n || {
  confirm_delete:   'Delete this record?',
  confirm_continue: 'Are you sure you want to continue?',
  no_results_for:   'No results for',
  theme_to_dark:    'Switch to dark theme',
  theme_to_light:   'Switch to light theme'
};

/* ==========================================================================
   1. CSRF helpers
   ========================================================================== */

/**
 * Reads the CSRF token from the <meta name="csrf-token"> tag injected by Layout.
 * @returns {string}
 */
function ibGetCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}

/**
 * True when the URL targets this same origin (relative URLs included).
 * The CSRF header must ONLY travel to our own backend: sending it
 * cross-origin leaks the token to third parties and breaks their CORS
 * preflight (e.g. the MapTiler tile API rejects unknown request headers).
 */
function ibIsSameOrigin(url) {
  if (!url) return true;
  try {
    return new URL(url, window.location.href).origin === window.location.origin;
  } catch (e) {
    return true;
  }
}

/**
 * Monkey-patches the global fetch so every SAME-ORIGIN request automatically
 * carries the X-CSRF-TOKEN header.
 */
(function patchFetch() {
  const originalFetch = window.fetch.bind(window);

  window.fetch = function (input, init = {}) {
    const headers = new Headers(init.headers || {});
    const reqUrl = (typeof input === 'string') ? input : (input && input.url) || '';

    if (ibIsSameOrigin(reqUrl) && !headers.has('X-CSRF-TOKEN')) {
      const token = ibGetCsrfToken();
      if (token) {
        headers.set('X-CSRF-TOKEN', token);
      }
    }

    return originalFetch(input, { ...init, headers }).then(function (resp) {
      ibCheckSessionResponse(resp, reqUrl);
      ibCheckFeatureResponse(resp, reqUrl);
      return resp;
    });
  };
}());

/**
 * Intercept all XMLHttpRequest calls and add the CSRF header.
 */
(function patchXHR() {
  const originalOpen = XMLHttpRequest.prototype.open;
  const originalSend = XMLHttpRequest.prototype.send;

  XMLHttpRequest.prototype.open = function (method, url, ...rest) {
    this._ibMethod = method;
    this._ibUrl = url;
    return originalOpen.call(this, method, url, ...rest);
  };

  XMLHttpRequest.prototype.send = function (...args) {
    const safeMethods = ['GET', 'HEAD', 'OPTIONS'];
    if (!safeMethods.includes((this._ibMethod || '').toUpperCase())
        && ibIsSameOrigin(this._ibUrl)) {
      const token = ibGetCsrfToken();
      if (token) {
        this.setRequestHeader('X-CSRF-TOKEN', token);
      }
    }
    this.addEventListener('load', function () {
      try {
        if (this.status === 401 && this.getResponseHeader('X-Ib-Session') === 'expired') {
          ibSessionExpired();
        }
      } catch (e) { /* cross-origin: headers not readable, ignore */ }
    });
    return originalSend.apply(this, args);
  };
}());

/* ==========================================================================
   1b. Session loss detection
   ========================================================================== */

/**
 * Pages that are reachable WITHOUT a session (login, password recovery). On
 * these, background polls (heartbeat, work-session chip) answer 401 by design,
 * and the "session expired" overlay must never fire: it would hijack the page
 * and bounce the visitor to /login before they can even submit the form.
 */
function ibIsPublicAuthPage() {
  var p = window.location.pathname;
  return p.indexOf('/login') === 0
    || p.indexOf('/forgot-password') === 0
    || p.indexOf('/reset-password') === 0;
}

/**
 * Called when the server tells us the session is gone. Blocks the UI with an
 * explanation and sends the user back to the login page.
 *
 * The server marks these answers with `X-Ib-Session: expired` (see
 * Application::respondSessionExpired) — a bare 401 is not enough, it can also
 * mean "this particular resource is not yours".
 */
var ibSessionExpired = (function () {
  var handled = false;

  function label(key, fallback) {
    var s = (window.ibI18n && window.ibI18n[key]) || '';
    return s && s !== key ? s : fallback;
  }

  return function ibSessionExpired(loginUrl) {
    // Several parallel requests fail at once — only the first one counts.
    if (handled) return;
    if (ibIsPublicAuthPage()) return;
    handled = true;

    var target = loginUrl || '/login';

    var overlay = document.createElement('div');
    overlay.className = 'ib-session-expired';

    var box = document.createElement('div');
    box.className = 'ib-session-expired__box';

    var icon = document.createElement('i');
    icon.className = 'fa-solid fa-right-from-bracket ib-session-expired__icon';
    box.appendChild(icon);

    var title = document.createElement('h2');
    title.textContent = label('session_expired_title', 'Sessió caducada');
    box.appendChild(title);

    var text = document.createElement('p');
    text.textContent = label('session_expired_text',
      'La teva sessió s\'ha tancat. Torna a iniciar sessió per continuar.');
    box.appendChild(text);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ib-btn ib-btn--primary';
    btn.textContent = label('session_expired_button', 'Iniciar sessió');
    btn.addEventListener('click', function () { window.location.href = target; });
    box.appendChild(btn);

    overlay.appendChild(box);
    (document.body || document.documentElement).appendChild(overlay);
    btn.focus();

    // Nothing on the page works any more, so leave on our own shortly after.
    setTimeout(function () { window.location.href = target; }, 6000);
  };
}());

/**
 * Inspect a response for the session-expired marker. Same-origin only: a 401
 * from a third-party API must not log the user out.
 */
function ibCheckSessionResponse(resp, requestUrl) {
  try {
    if (!resp || (resp.status !== 401 && resp.status !== 403)) return;
    var url = new URL(requestUrl || resp.url || '', window.location.href);
    if (url.origin !== window.location.origin) return;

    if (resp.status === 401 && resp.headers.get('X-Ib-Session') === 'expired') {
      var loginUrl = null;
      resp.clone().json().then(function (d) { loginUrl = d && d.login_url; })
        .catch(function () {})
        .then(function () { ibSessionExpired(loginUrl); });
      return;
    }

    // A CSRF rejection while still "authenticated" means the session was
    // replaced underneath us (logged in again elsewhere, session regenerated):
    // the page's token no longer matches, so every write would keep failing.
    if (resp.status === 403) {
      resp.clone().json().then(function (d) {
        if (d && typeof d.error === 'string' && /csrf/i.test(d.error)) ibSessionExpired();
      }).catch(function () { /* not JSON — an ordinary 403 */ });
    }
  } catch (e) { /* never let the check break the caller */ }
}

/**
 * 402 + X-Ib-Feature: denied → el tenant ha tancat la porta a una funció (quota o pla).
 * Si el tenant defineix window.ibFeatureDenied(quota), li passem el detall i ell ensenya
 * el seu modal; si no, no fem res i l'app mostra l'error com sempre.
 */
function ibCheckFeatureResponse(resp, requestUrl) {
  try {
    if (!resp || resp.status !== 402 || resp.headers.get('X-Ib-Feature') !== 'denied') return;
    var url = new URL(requestUrl || resp.url || '', window.location.href);
    if (url.origin !== window.location.origin) return;
    if (typeof window.ibFeatureDenied !== 'function') return;
    resp.clone().json().then(function (d) { window.ibFeatureDenied((d && d.quota) || {}, (d && d.error) || ''); }).catch(function () {});
  } catch (e) { /* mai trencar el caller */ }
}

/* ==========================================================================
   2. Tabs
   ========================================================================== */

/**
 * Activates tab navigation for all .ib-tabs elements on the page.
 *
 * Expected HTML:
 *   <div class="ib-tabs" data-tabs="my-group">
 *     <div class="ib-tabs__nav">
 *       <button class="ib-tabs__nav-item" data-tab="panel-1">Tab 1</button>
 *       <button class="ib-tabs__nav-item" data-tab="panel-2">Tab 2</button>
 *     </div>
 *     <div class="ib-tabs__panel" id="panel-1"> ... </div>
 *     <div class="ib-tabs__panel" id="panel-2"> ... </div>
 *   </div>
 */
function ibInitTabs() {
  document.querySelectorAll('.ib-tabs').forEach(function (tabsEl) {
    const navItems = tabsEl.querySelectorAll('.ib-tabs__nav-item');
    const panels   = tabsEl.querySelectorAll('.ib-tabs__panel');

    /** Reflect the active tab in the URL hash and in the form's _tab field. */
    function rememberTab(targetId) {
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + targetId);
      }
      // So a form submit (POST → redirect) can come back to the same tab
      const tabInput = tabsEl.closest('form')?.querySelector('input[name="_tab"]');
      if (tabInput) tabInput.value = targetId;
    }

    function activateTab(targetId) {
      navItems.forEach(function (btn) {
        const isActive = btn.dataset.tab === targetId;
        btn.classList.toggle('ib-tabs__nav-item--active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        btn.setAttribute('tabindex', isActive ? '0' : '-1');
      });

      panels.forEach(function (panel) {
        const isActive = panel.id === targetId;
        panel.classList.toggle('ib-tabs__panel--active', isActive);
        panel.hidden = !isActive;
      });
    }

    // Keyboard navigation (arrow keys).
    tabsEl.querySelector('.ib-tabs__nav')?.addEventListener('keydown', function (e) {
      const items = Array.from(navItems);
      const idx   = items.indexOf(document.activeElement);
      if (idx === -1) return;

      let next = -1;
      if (e.key === 'ArrowRight') next = (idx + 1) % items.length;
      if (e.key === 'ArrowLeft')  next = (idx - 1 + items.length) % items.length;

      if (next !== -1) {
        e.preventDefault();
        items[next].focus();
        activateTab(items[next].dataset.tab);
        rememberTab(items[next].dataset.tab);
      }
    });

    navItems.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activateTab(btn.dataset.tab);
        rememberTab(btn.dataset.tab);
      });
    });

    // Restore the tab named in the URL hash (#tab-sieve), if it belongs here.
    const hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
    const fromHash = hash && Array.from(navItems).some(function (btn) { return btn.dataset.tab === hash; });
    if (fromHash) {
      activateTab(hash);
      const tabInput = tabsEl.closest('form')?.querySelector('input[name="_tab"]');
      if (tabInput) tabInput.value = hash;
      return;
    }

    // Activate first tab by default if none is marked active.
    const firstActive = tabsEl.querySelector('.ib-tabs__nav-item--active');
    if (!firstActive && navItems.length > 0) {
      activateTab(navItems[0].dataset.tab);
    }
  });
}

/* ==========================================================================
   3. Flash toasts – auto-dismiss
   ========================================================================== */

/**
 * Auto-dismisses every .ib-toast element after 5 seconds.
 * Also wires up the close button.
 */
function ibInitToasts() {
  document.querySelectorAll('.ib-toast').forEach(function (toast) {
    const TIMEOUT_MS = 5000;

    function dismiss() {
      toast.classList.add('ib-toast--dismiss');
      toast.addEventListener('animationend', function () {
        toast.remove();
      }, { once: true });
    }

    // Close button.
    const closeBtn = toast.querySelector('.ib-alert__close');
    if (closeBtn) {
      closeBtn.addEventListener('click', dismiss);
    }

    // Auto-dismiss.
    let activeTimer = setTimeout(dismiss, TIMEOUT_MS);

    // Pause dismiss on hover, resume when mouse leaves.
    toast.addEventListener('mouseenter', function () { clearTimeout(activeTimer); });
    toast.addEventListener('mouseleave', function () { activeTimer = setTimeout(dismiss, 2000); });
  });
}

/* ==========================================================================
   4. Confirm dialogs – data-confirm attribute
   ========================================================================== */

/**
 * Any form containing a button with class .ib-btn--confirm will ask for
 * confirmation before submitting.  The message is taken from the button's
 * data-confirm attribute (falls back to a default).
 *
 * Also handles standalone .ib-btn--confirm anchor/buttons (non-form).
 *
 * For DELETE forms: any form with data-method="delete" will also ask.
 */
function ibInitConfirmDialogs() {
  // Form submit with confirm button.
  document.addEventListener('submit', function (e) {
    const form   = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    // Find the confirm button that actually belongs to THIS form
    // (exclude buttons with form= pointing to a different form).
    const confirmBtns = form.querySelectorAll('[type="submit"].ib-btn--confirm, [data-confirm]');
    // The clicked button wins: a <button form="x"> lives outside form x, so
    // querySelectorAll() below never sees it (delete buttons in the detail
    // header and the editor submitted without any confirmation).
    let trigger = (e.submitter && e.submitter.matches('.ib-btn--confirm, [data-confirm]')) ? e.submitter : null;
    for (const btn of (trigger ? [] : confirmBtns)) {
      if (btn.getAttribute('form') && btn.getAttribute('form') !== form.id) continue;
      trigger = btn;
      break;
    }

    if (!trigger) {
      // Also catch DELETE forms regardless.
      if (form.dataset.method === 'delete' || form.dataset.method === 'DELETE') {
        const msg = form.dataset.confirm || ibI18n.confirm_delete;
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      }
      return;
    }

    const message = trigger.dataset.confirm || ibI18n.confirm_continue;
    if (!window.confirm(message)) {
      e.preventDefault();
    }
  });

  // Standalone links / buttons with data-confirm (not inside a form).
  document.addEventListener('click', function (e) {
    const el = e.target.closest('[data-confirm]:not([type="submit"])');
    if (!el) return;

    // Skip if it's inside a form (handled above).
    if (el.closest('form')) return;

    const message = el.dataset.confirm || ibI18n.confirm_continue;
    if (!window.confirm(message)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
}

/* ==========================================================================
   5. Mobile sidebar toggle
   ========================================================================== */

/* ibInitSidebarToggle V1 removed — replaced by ibInitSidebarToggleV2() */

/* ==========================================================================
   6. Dynamic forms – method override (PUT / DELETE via POST)
   ========================================================================== */

/**
 * Forms with data-method="delete|put|patch" get a hidden _method field
 * so the backend can detect the intended HTTP verb, and the actual form
 * method is set to POST.
 */
function ibInitMethodOverride() {
  document.querySelectorAll('form[data-method]').forEach(function (form) {
    const method = form.dataset.method.toUpperCase();
    if (['PUT', 'PATCH', 'DELETE'].includes(method)) {
      form.method = 'POST';

      if (!form.querySelector('input[name="_method"]')) {
        const hidden   = document.createElement('input');
        hidden.type    = 'hidden';
        hidden.name    = '_method';
        hidden.value   = method;
        form.appendChild(hidden);
      }
    }
  });
}

/* ==========================================================================
   7. Inline alerts close button (non-toast)
   ========================================================================== */

function ibInitAlertClose() {
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.ib-alert__close');
    if (!btn) return;
    const alert = btn.closest('.ib-alert');
    if (alert && !alert.classList.contains('ib-toast')) {
      alert.remove();
    }
  });
}

/* ==========================================================================
   8. Active nav link highlight
   ========================================================================== */

/**
 * Adds .ib-sidebar__item--active to the nav item whose mod matches the
 * current URL's ?mod param (in case it wasn't server-rendered).
 */
function ibInitNavHighlight() {
  const params    = new URLSearchParams(window.location.search);
  const currentMod = params.get('mod');
  if (!currentMod) return;

  document.querySelectorAll('.ib-sidebar__link[data-mod]').forEach(function (link) {
    const isActive = link.dataset.mod === currentMod;
    link.closest('.ib-sidebar__item')?.classList.toggle('ib-sidebar__item--active', isActive);
  });
}

/* ==========================================================================
   9. Bootstrap – run everything on DOMContentLoaded
   ========================================================================== */

document.addEventListener('DOMContentLoaded', function () {
  ibInitTabs();
  ibInitToasts();
  ibInitConfirmDialogs();
  // ibInitSidebarToggle() replaced by ibInitSidebarToggleV2() in layout additions
  ibInitMethodOverride();
  ibInitAlertClose();
  ibInitNavHighlight();

  // Scroll active app into view in mobile appbar
  var activeItem = document.querySelector('.ib-appbar__item--active');
  if (activeItem) {
    activeItem.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'instant' });
  }

});

/* ==========================================================================
   9b. Sidebar groups – accordion expand/collapse
   ========================================================================== */

/**
 * Sidebar sub-groups — collapsible sections with localStorage persistence.
 *
 * For each .ib-sidebar__subgroup:
 *   - Reads data-group-id and data-default-collapsed attributes
 *   - Checks localStorage (key: 'ib-sg-{appKey}-{groupId}') for persisted state
 *   - Auto-opens the group that contains the current active module
 *   - Handles click on .ib-sidebar__subgroup-label to toggle + save state
 */
function ibInitSidebarSubgroups() {
  var sidebar = document.getElementById('ib-sidebar');
  if (!sidebar) return;

  var appKey = sidebar.getAttribute('data-app-key') || 'default';

  // Resolve current module from structured URL path OR query string
  var pathParts  = window.location.pathname.split('/').filter(Boolean);
  var currentMod = '';
  if (pathParts.length >= 2) {
    // Structured route: /{app}/{mod}/...
    currentMod = pathParts[1] || '';
  }
  if (!currentMod) {
    currentMod = new URLSearchParams(window.location.search).get('mod') || '';
  }

  sidebar.querySelectorAll('.ib-sidebar__subgroup').forEach(function (grp) {
    var btn   = grp.querySelector('.ib-sidebar__subgroup-label');
    var nav   = grp.querySelector('.ib-sidebar__subgroup-nav');
    var grpId = grp.getAttribute('data-group-id') || '';
    var defCollapsed = grp.getAttribute('data-default-collapsed') === 'true';
    if (!btn || !nav) return;

    var lsKey = 'ib-sg-' + appKey + '-' + grpId;

    // Check if group contains the active module → always open it
    var hasActive = !!nav.querySelector('.ib-sidebar__item--active');
    if (!hasActive && currentMod) {
      hasActive = !!nav.querySelector('[data-mod="' + CSS.escape(currentMod) + '"]');
    }

    // Determine initial collapsed state (active group always open)
    var shouldCollapse;
    if (hasActive) {
      shouldCollapse = false;
    } else {
      var stored = localStorage.getItem(lsKey);
      shouldCollapse = (stored !== null) ? (stored === '1') : defCollapsed;
    }

    // Apply initial state (no transition on load). Normalment ja hi és: el
    // servidor pinta l'estat per defecte i l'script de després del menú
    // (Layout::renderSidebar) hi aplica el desat abans de pintar.
    grp.classList.toggle('ib-sidebar__subgroup--collapsed', shouldCollapse);
    btn.setAttribute('aria-expanded', shouldCollapse ? 'false' : 'true');

    // Click handler
    btn.addEventListener('click', function () {
      var isCollapsed = grp.classList.contains('ib-sidebar__subgroup--collapsed');
      grp.classList.toggle('ib-sidebar__subgroup--collapsed', !isCollapsed);
      btn.setAttribute('aria-expanded', isCollapsed ? 'true' : 'false');
      localStorage.setItem(lsKey, isCollapsed ? '0' : '1');
    });
  });
}

// Add to DOMContentLoaded bootstrap
document.addEventListener('DOMContentLoaded', function () {
  ibInitSidebarSubgroups();
});

/* ==========================================================================
   10. Dark / Light Theme Toggle
   ========================================================================== */

/**
 * Reads the current theme from the <html> data-theme attribute.
 * Initialises the toggle button icon and wires the click handler.
 * Persists the preference in localStorage (key: 'ib-theme').
 */
function ibInitThemeToggle() {
  var btn  = document.getElementById('ib-theme-toggle');
  var icon = document.getElementById('ib-theme-icon');
  if (!btn || !icon) return;

  // Native mobile app: force dark theme and hide the toggle
  if (/IntrabaseMobile/i.test(navigator.userAgent)) {
    document.documentElement.setAttribute('data-theme', 'dark');
    btn.style.display = 'none';
    return;
  }

  function applyTheme(theme, save) {
    document.documentElement.setAttribute('data-theme', theme);
    // Switch icon: moon for light mode (click → go dark), sun for dark mode
    icon.className = theme === 'dark'
      ? 'fa-solid fa-sun'
      : 'fa-solid fa-moon';
    btn.setAttribute('aria-label',
      theme === 'dark' ? ibI18n.theme_to_light : ibI18n.theme_to_dark);
    if (save) {
      var csrf = document.querySelector('meta[name="csrf-token"]');
      var token = csrf ? csrf.getAttribute('content') : '';
      fetch('/user/theme', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token':token},
        body: JSON.stringify({theme: theme})
      }).catch(function(){});
    }
  }

  // Apply initial state from server-rendered data-theme (update icon only)
  var current = document.documentElement.getAttribute('data-theme') || 'dark';
  applyTheme(current, false);

  btn.addEventListener('click', function () {
    var current = document.documentElement.getAttribute('data-theme') || 'dark';
    applyTheme(current === 'dark' ? 'light' : 'dark', true);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  ibInitThemeToggle();
});


// =============================================================================
// 10. Notification Bell
// =============================================================================
function ibInitNotificationBell() {
  var btn      = document.getElementById('ib-notif-btn');
  var panel    = document.getElementById('ib-notif-panel');
  var markAll  = document.getElementById('ib-notif-mark-all');
  var badge    = document.getElementById('ib-notif-badge');
  var listEl   = document.getElementById('ib-notif-list');
  if (!btn || !panel) return;
  if (btn.dataset.ibNotifInit === '1') return;
  btn.dataset.ibNotifInit = '1';

  var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var DEFAULT_NOTIFS_URL = '/system/sys_notifications';
  var EMPTY_LABEL = (listEl && listEl.dataset.emptyLabel) || 'No hi ha notificacions';
  var DISMISS_LABEL = (listEl && listEl.dataset.dismissLabel) || '';

  function typeClass(t) {
    switch (String(t || 'info')) {
      case 'success': return 'ib-notif-item--success';
      case 'warning': return 'ib-notif-item--warning';
      case 'danger':  return 'ib-notif-item--danger';
      case 'system':  return 'ib-notif-item--system';
      default:        return 'ib-notif-item--info';
    }
  }
  function trimMsg(s) {
    s = String(s || '');
    return s.length > 80 ? (s.slice(0, 79) + '\u2026') : s;
  }
  function timeAgoShort(dt) {
    if (!dt) return '';
    var t = Date.parse(String(dt).replace(' ', 'T'));
    if (isNaN(t)) return '';
    var diff = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (diff < 60)      return diff + 's';
    if (diff < 3600)    return Math.floor(diff / 60) + 'm';
    if (diff < 86400)   return Math.floor(diff / 3600) + 'h';
    return Math.floor(diff / 86400) + 'd';
  }
  function buildItem(n) {
    var a = document.createElement('a');
    a.className = 'ib-notif-item ' + typeClass(n.type) + (n.is_read ? '' : ' ib-notif-item--unread');
    a.href = n.url ? String(n.url) : DEFAULT_NOTIFS_URL;
    a.target = '_blank';
    a.setAttribute('data-nid', String(n.id|0));
    var i = document.createElement('i');
    i.className = String(n.icon || 'fa-solid fa-bell') + ' ib-notif-item__icon';
    i.setAttribute('aria-hidden', 'true');
    a.appendChild(i);
    var body = document.createElement('div');
    body.className = 'ib-notif-item__body';
    var title = document.createElement('div');
    title.className = 'ib-notif-item__title';
    title.textContent = String(n.title || '');
    body.appendChild(title);
    var msg = document.createElement('div');
    msg.className = 'ib-notif-item__msg';
    msg.textContent = trimMsg(n.msg);
    body.appendChild(msg);
    var time = document.createElement('div');
    time.className = 'ib-notif-item__time';
    time.textContent = timeAgoShort(n.created_at);
    body.appendChild(time);
    a.appendChild(body);

    // La creueta va FORA de l'<a> (un <button> a dins seria HTML invalid), en un
    // contenidor que nomes serveix per ancorar-la a la cantonada de la fila.
    var row = document.createElement('div');
    row.className = 'ib-notif-row';
    row.appendChild(a);
    var dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'ib-notif-item__dismiss';
    dismiss.setAttribute('data-nid', String(n.id|0));
    if (DISMISS_LABEL) {
      dismiss.title = DISMISS_LABEL;
      dismiss.setAttribute('aria-label', DISMISS_LABEL);
    }
    var x = document.createElement('i');
    x.className = 'fa-solid fa-xmark';
    x.setAttribute('aria-hidden', 'true');
    dismiss.appendChild(x);
    row.appendChild(dismiss);
    return row;
  }
  function showEmpty() {
    if (!listEl) return;
    while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
    var empty = document.createElement('div');
    empty.className = 'ib-notif-empty';
    empty.textContent = EMPTY_LABEL;
    listEl.appendChild(empty);
  }
  function rebuildList(items) {
    if (!listEl) return;
    if (!items || items.length === 0) { showEmpty(); return; }
    while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
    items.forEach(function (n) { listEl.appendChild(buildItem(n)); });
  }
  function applyBadge(n) {
    if (!badge) return;
    if (n > 0) {
      badge.textContent = n > 99 ? '99' : String(n);
      badge.classList.remove('ib-notif-badge--hidden');
    } else {
      badge.textContent = '';
      badge.classList.add('ib-notif-badge--hidden');
    }
  }
  var refreshing = false;
  function refreshPanelList() {
    if (refreshing) return;
    refreshing = true;
    fetch('/notifications/poll?list=1&since=0', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      if (!data || !data.ok) return;
      rebuildList(Array.isArray(data.list) ? data.list : []);
      applyBadge(parseInt(data.unread || 0, 10));
      if (markAll) markAll.setAttribute('data-has-unread', (data.unread > 0) ? 'true' : 'false');
    })
    .catch(function () {})
    .then(function () { refreshing = false; });
  }
  // Expose so the polling routine can rebuild the panel when new items arrive
  window.ibNotifRefreshPanel = refreshPanelList;

  function openPanel() {
    panel.removeAttribute('hidden');
    btn.setAttribute('aria-expanded', 'true');
    refreshPanelList();
  }
  function closePanel() {
    panel.setAttribute('hidden', '');
    btn.setAttribute('aria-expanded', 'false');
  }
  function togglePanel() {
    if (panel.hasAttribute('hidden')) { openPanel(); } else { closePanel(); }
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    togglePanel();
  });

  // Explicit close (X) button in the panel header
  var closeBtn = document.getElementById('ib-notif-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      closePanel();
    });
  }

  // Creueta per fila: la marca com a llegida i la treu de la llista. El panell
  // nomes ensenya les NO llegides (/notifications/poll?list=1 filtra is_read=0),
  // o sigui que marcar-la llegida ES treure-la; segueix a l'historial complet.
  // La fila es treu abans de saber si el servidor ha dit que si — si falla, es
  // torna a demanar la llista i reapareix, que es preferible a esperar-se.
  if (listEl) {
    listEl.addEventListener('click', function (e) {
      var dismiss = e.target && e.target.closest ? e.target.closest('.ib-notif-item__dismiss') : null;
      if (!dismiss || !listEl.contains(dismiss)) return;
      e.preventDefault();
      e.stopPropagation();

      var nid = parseInt(dismiss.getAttribute('data-nid') || '0', 10);
      if (!(nid > 0)) return;
      dismiss.disabled = true;

      var row = dismiss.closest('.ib-notif-row');
      if (row && row.parentNode) row.parentNode.removeChild(row);
      if (!listEl.querySelector('.ib-notif-row')) showEmpty();

      if (badge) {
        var left = Math.max(0, (parseInt(badge.textContent || '0', 10) || 0) - 1);
        applyBadge(left);
        if (markAll) markAll.setAttribute('data-has-unread', left > 0 ? 'true' : 'false');
      }

      fetch('/notifications/mark-read', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken
        },
        body: '_token=' + encodeURIComponent(csrfToken) + '&id=' + encodeURIComponent(nid)
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) { refreshPanelList(); return; }
        // Si l'historial es obert, que no ensenyi la fila com a no llegida.
        if (typeof modalIsOpen === 'function' && modalIsOpen() && modalList) {
          var mrow = modalList.querySelector('.ib-notif-modal__row[data-nid="' + nid + '"]');
          if (mrow) setRowState(mrow, true);
        }
      })
      .catch(function () { refreshPanelList(); });
    });
  }

  // ---------------------------------------------------------------------------
  // "All notifications" modal — paginated history + per-row read/unread toggle.
  // Labels come from data-* attributes rendered by Layout.php (no literals here).
  // ---------------------------------------------------------------------------
  var viewAll     = document.getElementById('ib-notif-view-all');
  var modal       = document.getElementById('ib-notif-modal');
  var modalList   = document.getElementById('ib-notif-modal-list');
  var modalMore   = document.getElementById('ib-notif-modal-more');
  var modalClose  = document.getElementById('ib-notif-modal-close');
  var modalStatus = document.getElementById('ib-notif-modal-status');
  var HISTORY_LIMIT = 30;
  var SCROLL_THRESHOLD = 80;
  var mData = (modalList && modalList.dataset) || {};
  var LBL = {
    empty:      mData.emptyLabel      || EMPTY_LABEL,
    loading:    mData.loadingLabel    || '',
    error:      mData.errorLabel      || '',
    noMore:     mData.noMoreLabel     || '',
    read:       mData.readLabel       || '',
    unread:     mData.unreadLabel     || '',
    markRead:   mData.markReadLabel   || '',
    markUnread: mData.markUnreadLabel || ''
  };
  var mState = { loading: false, hasMore: true, before: 0 };

  function modalIsOpen() {
    return !!modal && !modal.hasAttribute('hidden');
  }
  function setStatus(text, isError) {
    if (!modalStatus) return;
    modalStatus.textContent = text || '';
    if (isError) { modalStatus.classList.add('ib-notif-modal__status--error'); }
    else         { modalStatus.classList.remove('ib-notif-modal__status--error'); }
  }
  function setRowState(row, isRead) {
    row.setAttribute('data-read', isRead ? '1' : '0');
    if (isRead) { row.classList.remove('ib-notif-modal__row--unread'); }
    else        { row.classList.add('ib-notif-modal__row--unread'); }
    var state = row.querySelector('.ib-notif-modal__state');
    if (state) state.textContent = isRead ? LBL.read : LBL.unread;
    var tg = row.querySelector('.ib-notif-modal__toggle');
    if (tg) {
      var label = isRead ? LBL.markUnread : LBL.markRead;
      tg.setAttribute('aria-pressed', isRead ? 'true' : 'false');
      tg.setAttribute('aria-label', label);
      tg.setAttribute('title', label);
      var ic = tg.querySelector('i');
      if (ic) ic.className = isRead ? 'fa-solid fa-envelope' : 'fa-solid fa-envelope-open';
    }
  }
  function buildRow(n) {
    var row = document.createElement('div');
    row.className = 'ib-notif-modal__row ' + typeClass(n.type);
    row.setAttribute('data-nid', String(n.id | 0));

    var link = document.createElement('a');
    link.className = 'ib-notif-modal__link';
    link.href = n.url ? String(n.url) : DEFAULT_NOTIFS_URL;
    link.target = '_blank';

    var i = document.createElement('i');
    i.className = String(n.icon || 'fa-solid fa-bell') + ' ib-notif-item__icon';
    i.setAttribute('aria-hidden', 'true');
    link.appendChild(i);

    var body = document.createElement('div');
    body.className = 'ib-notif-modal__body';
    var title = document.createElement('div');
    title.className = 'ib-notif-modal__row-title';
    title.textContent = String(n.title || '');
    body.appendChild(title);
    var msg = document.createElement('div');
    msg.className = 'ib-notif-modal__msg';
    msg.textContent = trimMsg(n.msg);
    body.appendChild(msg);
    var meta = document.createElement('div');
    meta.className = 'ib-notif-modal__meta';
    var time = document.createElement('span');
    time.className = 'ib-notif-modal__time';
    time.textContent = timeAgoShort(n.created_at);
    meta.appendChild(time);
    var state = document.createElement('span');
    state.className = 'ib-notif-modal__state';
    meta.appendChild(state);
    body.appendChild(meta);
    link.appendChild(body);
    row.appendChild(link);

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'ib-notif-modal__toggle';
    toggle.setAttribute('data-nid', String(n.id | 0));
    var ti = document.createElement('i');
    ti.setAttribute('aria-hidden', 'true');
    toggle.appendChild(ti);
    row.appendChild(toggle);

    setRowState(row, !!n.is_read && String(n.is_read) !== '0');
    return row;
  }
  function showEmpty() {
    if (!modalList) return;
    var empty = document.createElement('div');
    empty.className = 'ib-notif-modal__empty';
    empty.textContent = LBL.empty;
    modalList.appendChild(empty);
  }
  function syncMarkAllFlag(unread) {
    if (markAll) markAll.setAttribute('data-has-unread', (unread > 0) ? 'true' : 'false');
  }
  function loadPage(reset) {
    if (!modalList || mState.loading) return;
    if (!reset && !mState.hasMore) return;
    mState.loading = true;
    if (reset) {
      mState.before  = 0;
      mState.hasMore = true;
      while (modalList.firstChild) modalList.removeChild(modalList.firstChild);
    }
    if (modalMore) modalMore.setAttribute('hidden', '');
    setStatus(LBL.loading, false);
    var url = '/notifications/history?limit=' + HISTORY_LIMIT +
              (mState.before ? ('&before=' + encodeURIComponent(mState.before)) : '');
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      if (!data || !data.ok) {
        mState.hasMore = false;
        setStatus(LBL.error, true);
        return;
      }
      var items = Array.isArray(data.items) ? data.items : [];
      items.forEach(function (n) { modalList.appendChild(buildRow(n)); });
      mState.hasMore = !!data.has_more;
      mState.before  = parseInt(data.next_before || 0, 10) || 0;
      if (!mState.before) mState.hasMore = false;
      var unread = parseInt(data.unread || 0, 10);
      applyBadge(unread);
      syncMarkAllFlag(unread);
      if (!modalList.querySelector('.ib-notif-modal__row')) {
        setStatus('', false);
        showEmpty();
      } else if (mState.hasMore) {
        setStatus('', false);
        if (modalMore) modalMore.removeAttribute('hidden');
      } else {
        setStatus(LBL.noMore, false);
      }
    })
    .catch(function () { setStatus(LBL.error, true); })
    .then(function () { mState.loading = false; });
  }
  function openModal() {
    if (!modal) return;
    closePanel();
    modal.removeAttribute('hidden');
    document.body.classList.add('ib-notif-modal-open');
    if (modalClose) modalClose.focus();
    loadPage(true);
  }
  function closeModal() {
    if (!modalIsOpen()) return;
    modal.setAttribute('hidden', '');
    document.body.classList.remove('ib-notif-modal-open');
    if (viewAll) viewAll.focus();
  }

  if (viewAll) {
    viewAll.addEventListener('click', function (e) {
      if (!modal) return; // no markup → let the link navigate (no-JS fallback path)
      e.preventDefault();
      e.stopPropagation();
      openModal();
    });
  }
  if (modalClose) {
    modalClose.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      closeModal();
    });
  }
  if (modal) {
    // Backdrop click closes
    modal.addEventListener('click', function (e) {
      if (e.target && e.target.getAttribute && e.target.getAttribute('data-ib-notif-close') === '1') {
        closeModal();
      }
      e.stopPropagation();
    });
    // Focus trap: keep Tab cycling inside the dialog while it is open
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab' || !modalIsOpen()) return;
      var all = modal.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]),' +
        ' textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );
      var nodes = [];
      for (var k = 0; k < all.length; k++) {
        if (all[k].hasAttribute('hidden')) continue;
        if (!all[k].getClientRects().length) continue;
        nodes.push(all[k]);
      }
      if (!nodes.length) { e.preventDefault(); return; }
      var first = nodes[0];
      var last  = nodes[nodes.length - 1];
      var inside = modal.contains(document.activeElement);
      if (e.shiftKey && (!inside || document.activeElement === first)) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && (!inside || document.activeElement === last)) {
        e.preventDefault();
        first.focus();
      }
    });
  }
  if (modalMore) {
    modalMore.addEventListener('click', function (e) {
      e.preventDefault();
      loadPage(false);
    });
  }
  if (modalList) {
    // Infinite scroll on the list's own scroll container
    modalList.addEventListener('scroll', function () {
      if (mState.loading || !mState.hasMore) return;
      if (modalList.scrollTop + modalList.clientHeight >= modalList.scrollHeight - SCROLL_THRESHOLD) {
        loadPage(false);
      }
    });
    // Per-row read/unread toggle
    modalList.addEventListener('click', function (e) {
      var tg = e.target.closest ? e.target.closest('.ib-notif-modal__toggle') : null;
      if (!tg || tg.disabled) return;
      e.preventDefault();
      e.stopPropagation();
      var row = tg.closest('.ib-notif-modal__row');
      var nid = tg.getAttribute('data-nid');
      if (!row || !nid) return;
      var isRead   = row.getAttribute('data-read') === '1';
      var endpoint = isRead ? '/notifications/mark-unread' : '/notifications/mark-read';
      tg.disabled = true;
      fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken,
          'Accept': 'application/json'
        },
        body: '_token=' + encodeURIComponent(csrfToken) + '&id=' + encodeURIComponent(nid)
      })
      // Non-2xx (403 CSRF / 404 not ours) → leave the row untouched
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.ok) return;
        setRowState(row, !isRead);
        var unread = parseInt(data.unread || 0, 10);
        applyBadge(unread);
        syncMarkAllFlag(unread);
      })
      .catch(function () {})
      .then(function () { tg.disabled = false; });
    });
  }

  // Close on outside click
  document.addEventListener('click', function (e) {
    if (!panel.hasAttribute('hidden') && !panel.contains(e.target) && e.target !== btn) {
      closePanel();
    }
  });

  // Close on Escape — the modal wins while it is open, so the two don't collide
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (modalIsOpen()) { closeModal(); return; }
    closePanel();
  });

  // Mark all as read
  if (markAll) {
    markAll.addEventListener('click', function () {
      fetch('/notifications/mark-read', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken
        },
        body: '_token=' + encodeURIComponent(csrfToken)
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          applyBadge(0);
          markAll.setAttribute('data-has-unread', 'false');
          refreshPanelList();
          closePanel();
          // Keep an open history modal in sync (no reload)
          if (modalIsOpen() && modalList) {
            var rows = modalList.querySelectorAll('.ib-notif-modal__row');
            for (var r = 0; r < rows.length; r++) setRowState(rows[r], true);
          }
        }
      })
      .catch(function () {});
    });
  }

  // Mark single notification when clicking on it (let the <a> navigation happen,
  // but mark it as read first — panel refresh on next open removes it from the list)
  panel.addEventListener('click', function (e) {
    var item = e.target.closest('.ib-notif-item[data-nid]');
    if (!item) return;
    var nid = item.getAttribute('data-nid');
    if (!nid || !item.classList.contains('ib-notif-item--unread')) return;
    fetch('/notifications/mark-read', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': csrfToken
      },
      body: '_token=' + encodeURIComponent(csrfToken) + '&id=' + encodeURIComponent(nid)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok) {
        applyBadge(parseInt(data.unread || 0, 10));
        // Treu la fila sencera, no nomes l'<a>: des que hi ha creueta l'enllac
        // viu dins d'un .ib-notif-row i deixar-lo enrere hi deixaria una x orfe.
        var gone = item.closest('.ib-notif-row') || item;
        if (gone.parentNode) gone.parentNode.removeChild(gone);
        if (listEl && !listEl.querySelector('.ib-notif-item')) showEmpty();
      }
    })
    .catch(function () {});
  });
}

// =============================================================================
// 11. Global Search Bar
// =============================================================================
function ibInitGlobalSearch() {
  var input   = document.getElementById('ib-search-input');
  var results = document.getElementById('ib-search-results');
  if (!input || !results) return;
  if (input.dataset.ibSearchInit === '1') return;
  input.dataset.ibSearchInit = '1';

  var debounceTimer = null;
  var lastQuery = '';

  function showResults(html) {
    results.innerHTML = html;
    results.removeAttribute('hidden');
  }
  function hideResults() {
    results.setAttribute('hidden', '');
    results.innerHTML = '';
    lastQuery = '';
  }

  function doSearch(q) {
    if (q === lastQuery) return;
    lastQuery = q;

    if (q.length < 2) { hideResults(); return; }

    fetch('/search?q=' + encodeURIComponent(q), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (groups) {
      if (!Array.isArray(groups) || groups.length === 0) {
        showResults('<div class="ib-search-no-results">' + ibI18n.no_results_for + ' "<em>' + q.replace(/</g,'&lt;') + '</em>"</div>');
        return;
      }
      var html = '';
      groups.forEach(function (g) {
        var safeIcon = (g.icon||'').replace(/[<>&"']/g, function(ch){return{'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[ch];});
        var safeGroup = (g.group||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        html += '<div class="ib-search-group__title"><i class="' + safeIcon + '" aria-hidden="true"></i>' + safeGroup + '</div>';
        (g.items || []).forEach(function (item) {
          html += '<a class="ib-search-result-item" href="' + item.url.replace(/"/g,'&quot;') + '" target="_blank">'
                + '<span class="ib-search-result-item__label">' + (item.label||'').replace(/</g,'&lt;') + '</span>'
                + (item.sub ? '<span class="ib-search-result-item__sub">' + (item.sub||'').replace(/</g,'&lt;') + '</span>' : '')
                + '</a>';
        });
      });
      showResults(html);
    })
    .catch(function () { hideResults(); });
  }

  input.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    var q = input.value.trim();
    if (q.length < 2) { hideResults(); return; }
    debounceTimer = setTimeout(function () { doSearch(q); }, 300);
  });

  // Clear on Escape
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      input.value = '';
      hideResults();
      input.blur();
    }
    // Navigate results with arrow keys
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var items = results.querySelectorAll('a.ib-search-result-item');
      if (!items.length) return;
      var focused = results.querySelector('.ib-search-result-item--focused');
      var idx = -1;
      items.forEach(function (el, i) { if (el === focused) idx = i; });
      if (focused) focused.classList.remove('ib-search-result-item--focused');
      if (e.key === 'ArrowDown') idx = (idx + 1) % items.length;
      else idx = (idx - 1 + items.length) % items.length;
      items[idx].classList.add('ib-search-result-item--focused');
      items[idx].scrollIntoView({ block: 'nearest' });
    }
    if (e.key === 'Enter') {
      var focused = results.querySelector('.ib-search-result-item--focused');
      if (focused) { window.open(focused.href, '_blank'); }
    }
  });

  // Hide results when clicking outside overlay
  document.addEventListener('click', function (e) {
    var overlay = document.getElementById('ib-search-overlay');
    var toggle  = document.getElementById('ib-search-toggle');
    if (overlay && !overlay.contains(e.target) && toggle && !toggle.contains(e.target)) {
      hideResults();
    }
  });

  // Clear input shows no results
  input.addEventListener('search', function () {
    if (input.value === '') { hideResults(); }
  });

  // Search toggle button
  var toggleBtn = document.getElementById('ib-search-toggle');
  var overlay   = document.getElementById('ib-search-overlay');
  var closeBtn  = document.getElementById('ib-search-close');

  function openSearch() {
    if (!overlay) return;
    overlay.hidden = false;
    toggleBtn && toggleBtn.setAttribute('aria-expanded', 'true');
    input.focus();
    input.select();
  }
  function closeSearch() {
    if (!overlay) return;
    overlay.hidden = true;
    toggleBtn && toggleBtn.setAttribute('aria-expanded', 'false');
    hideResults();
    input.value = '';
  }

  if (toggleBtn) toggleBtn.addEventListener('click', function () {
    overlay && overlay.hidden ? openSearch() : closeSearch();
  });
  if (closeBtn) closeBtn.addEventListener('click', closeSearch);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay && !overlay.hidden) closeSearch();
  });

  // Ctrl+K / Cmd+K → open global search from anywhere
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      if (overlay && overlay.hidden) { openSearch(); }
      else { closeSearch(); }
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  ibInitNotificationBell();
  ibInitGlobalSearch();
});

/* ==========================================================================
   Layout Redesign — Sidebar Toggle + Avatar Dropdown
   Added: 27/02/2026
   ========================================================================== */

/**
 * Sidebar toggle — uses body class 'ib-sidebar-closed' + localStorage.
 * Replaces the old mobile-only ibInitSidebarToggle.
 */
function ibInitSidebarToggleV2() {
  var sidebar    = document.getElementById('ib-sidebar');
  var openBtn    = document.getElementById('ib-menu-open');
  var closeBtn   = document.getElementById('ib-sidebar-toggle');
  var body       = document.body;

  if (!sidebar) return;

  // Create backdrop element for mobile sidebar overlay
  var backdrop = document.createElement('div');
  backdrop.className = 'ib-sidebar-backdrop';
  backdrop.setAttribute('aria-hidden', 'true');
  body.appendChild(backdrop);

  // Check if this app wants sidebar collapsed by default (data-sidebar-collapsed="1")
  var appDefaultCollapsed = sidebar.getAttribute('data-sidebar-collapsed') === '1';
  var appKey = sidebar.getAttribute('data-app-key') || 'default';
  var storageKey = 'ib-sidebar-closed-' + appKey;

  // Restore per-app persisted state, falling back to app default
  var storedState = localStorage.getItem(storageKey);
  var isClosed = storedState !== null ? storedState === '1' : appDefaultCollapsed;

  function isMobile() { return window.innerWidth <= 768; }

  // On mobile, start closed; on desktop respect per-app state
  if (isMobile()) {
    isClosed = true;
  }

  if (isClosed) {
    body.classList.add('ib-sidebar-closed');
    if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
  } else {
    body.classList.remove('ib-sidebar-closed');
    if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
  }

  function openSidebar() {
    if (isMobile()) {
      body.classList.add('ib-sidebar-open');
      body.classList.remove('ib-sidebar-closed');
    } else {
      body.classList.remove('ib-sidebar-closed');
    }
    if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    if (!isMobile()) localStorage.setItem(storageKey, '0');
  }

  function closeSidebar() {
    if (isMobile()) {
      body.classList.remove('ib-sidebar-open');
      body.classList.add('ib-sidebar-closed');
    } else {
      body.classList.add('ib-sidebar-closed');
    }
    if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    if (!isMobile()) localStorage.setItem(storageKey, '1');
  }

  function toggleSidebar() {
    if (isMobile()) {
      if (body.classList.contains('ib-sidebar-open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    } else {
      if (body.classList.contains('ib-sidebar-closed')) {
        openSidebar();
      } else {
        closeSidebar();
      }
    }
  }

  if (openBtn)  openBtn.addEventListener('click', toggleSidebar);
  if (closeBtn) closeBtn.addEventListener('click', closeSidebar);

  // Close on backdrop click (mobile)
  backdrop.addEventListener('click', function () {
    closeSidebar();
  });

  // Navigate sidebar links → close on mobile
  sidebar.querySelectorAll('.ib-sidebar__link').forEach(function (link) {
    link.addEventListener('click', function () {
      if (isMobile()) {
        closeSidebar();
      }
    });
  });

  // Handle orientation change / resize: recalculate mobile state
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      if (isMobile()) {
        body.classList.remove('ib-sidebar-open');
        body.classList.add('ib-sidebar-closed');
      }
    }, 150);
  });
}

/**
 * Avatar dropdown toggle.
 */
function ibInitAvatarDropdown() {
  var btn   = document.getElementById('ib-avatar-btn');
  var panel = document.getElementById('ib-avatar-panel');

  if (!btn || !panel) return;

  panel.querySelectorAll('[data-ib-tenant-fn]').forEach(function (b) {
    b.addEventListener('click', function () {
      var fn = this.getAttribute('data-ib-tenant-fn');
      panel.hidden = true; btn.setAttribute('aria-expanded', 'false');
      if (/^[A-Za-z_]\w*$/.test(fn) && typeof window[fn] === 'function') window[fn]();
    });
  });

  function openPanel() {
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
  }

  function closePanel() {
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    if (panel.hidden) {
      // Close any other open dropdowns first
      document.querySelectorAll('.ib-notif__panel:not([hidden])').forEach(function (p) { p.hidden = true; });
      openPanel();
    } else {
      closePanel();
    }
  });

  // Close on outside click
  document.addEventListener('click', function (e) {
    var wrapper = document.getElementById('ib-avatar');
    if (wrapper && !wrapper.contains(e.target)) {
      closePanel();
    }
  });

  // Close on Escape
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closePanel();
  });
}

/**
 * Where to land after switching account from the avatar menu: the page the
 * user was on, so they stay in the app they were using (23/09/2026; before
 * it always went to /profile). Only /account/{id} is tied to one entity:
 * AccountController switches the session to the entity in its URL, so
 * landing back on the old one would undo the switch. It becomes the new
 * entity's account page, or /profile for a switch to personal mode.
 *
 * A path that does not exist in the new account is each app's business:
 * the Drive, for one, falls back to its root (/drive) with a notice when
 * the folder of the URL is not there.
 */
function ibSwitchLandingUrl(pathname, search, newEntityId) {
  var path = pathname || '/';
  if (/^\/account(\/|$)/.test(path)) {
    return newEntityId > 0 ? '/account/' + newEntityId : '/profile';
  }
  return path + (search || '');
}

// Loads the landing page. Assigning location.href the current URL minus
// its #fragment is a fragment navigation (no reload), so the same page is
// reloaded explicitly.
function ibGoAfterSwitch(url) {
  if (url === window.location.pathname + window.location.search) {
    window.location.reload();
  } else {
    window.location.href = url;
  }
}

/**
 * Organization switching (items inside avatar dropdown).
 */
function ibInitOrgSwitcher() {
  var panel = document.getElementById('ib-avatar-panel');
  if (!panel) return;

  // Organization picker (several organizations, 26/09/2026). Moved to <body>
  // on open: inside the header a transformed ancestor would clip position:fixed.
  var modal = document.getElementById('ib-org-modal');
  function closeOrgModal() {
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    document.body.classList.remove('ib-dlg-open');
  }
  function openOrgModal() {
    if (!modal) return;
    if (modal.parentNode !== document.body) document.body.appendChild(modal);
    panel.hidden = true;
    var btn = document.getElementById('ib-avatar-btn');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    modal.hidden = false;
    document.body.classList.add('ib-dlg-open');
    var first = modal.querySelector('.ib-org-item:not(.ib-org-item--active)') || modal.querySelector('.ib-org-item');
    if (first) first.focus();
  }
  panel.querySelectorAll('[data-ib-org-modal]').forEach(function (b) {
    b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openOrgModal(); });
  });
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.closest('[data-ib-org-modal-close]')) closeOrgModal();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeOrgModal(); });
  }

  // Account rows of the picker and the switch row at the top of the panel (the
  // account to switch to, ⇄) carry the same switch attributes; the active
  // account carries none.
  var targets = Array.prototype.slice.call(panel.querySelectorAll('.ib-avatar__account--switch:not([data-ib-org-modal])'));
  if (modal) targets = targets.concat(Array.prototype.slice.call(modal.querySelectorAll('.ib-org-item')));
  targets.forEach(function (item) {
    item.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      // Tenant-provided account (tenant-provided): switch through the tenant's
      // own endpoint ({actor_id}), then stay on the current page. The
      // active item has no attrs.
      var tenantUrl = this.getAttribute('data-switch-url');
      if (tenantUrl) {
        var actorId = parseInt(this.getAttribute('data-actor-id'), 10) || 0;
        fetch(tenantUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ actor_id: actorId })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) ibGoAfterSwitch(ibSwitchLandingUrl(window.location.pathname, window.location.search, 0));
        });
        return;
      }
      if (!this.hasAttribute('data-entity-id')) { closeOrgModal(); return; } // the active account
      var entityId = parseInt(this.getAttribute('data-entity-id'), 10) || 0;
      fetch('/user/switch-entity', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify({ entity_id: entityId })
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        ibGoAfterSwitch(ibSwitchLandingUrl(window.location.pathname, window.location.search, entityId));
      });
    });
  });
}

// Bootstrap new layout functions
document.addEventListener('DOMContentLoaded', function () {
  ibInitSidebarToggleV2();
  ibInitAvatarDropdown();
  ibInitOrgSwitcher();
  ibInitHtmlEditors();
  ibInitQuickActions();
});

/**
 * WYSIWYG Rich Text Editor
 * Activates on elements with data-editor="html".
 * Uses a contenteditable div + toolbar that syncs back to the hidden textarea.
 */
function ibInitHtmlEditors() {
  document.querySelectorAll('[data-editor="html"]').forEach(function (textarea) {
    // Avoid double-init
    if (textarea.dataset.editorInit === '1') return;
    textarea.dataset.editorInit = '1';
    ibBuildHtmlEditor(textarea);
  });
}

function ibBuildHtmlEditor(textarea) {
  var initialHtml = textarea.value.trim();

  // Wrapper
  var wrap = document.createElement('div');
  wrap.className = 'ib-html-editor-wrap';
  textarea.parentNode.insertBefore(wrap, textarea);

  // Toolbar
  var toolbar = document.createElement('div');
  toolbar.className = 'ib-html-toolbar';
  toolbar.setAttribute('role', 'toolbar');
  toolbar.setAttribute('aria-label', 'Formatació de text');

  // Heading selector
  var headSel = document.createElement('select');
  headSel.className = 'ib-html-toolbar__select';
  headSel.title = 'Format de bloc';
  [
    ['', 'Normal'],
    ['h2', 'H2'],
    ['h3', 'H3'],
    ['h4', 'H4'],
    ['blockquote', 'Cita'],
    ['pre', 'Codi'],
  ].forEach(function (opt) {
    var o = document.createElement('option');
    o.value = opt[0];
    o.textContent = opt[1];
    headSel.appendChild(o);
  });
  toolbar.appendChild(headSel);

  // Separator
  function sep() {
    var s = document.createElement('span');
    s.className = 'ib-html-toolbar__sep';
    toolbar.appendChild(s);
  }

  sep();

  // Inline formatting buttons
  var inlineButtons = [
    { cmd: 'bold',          icon: '<b>B</b>',              title: 'Negreta (Ctrl+B)' },
    { cmd: 'italic',        icon: '<i>I</i>',              title: 'Cursiva (Ctrl+I)' },
    { cmd: 'underline',     icon: '<u>U</u>',              title: 'Subratllat (Ctrl+U)' },
    { cmd: 'strikeThrough', icon: '<s>S</s>',              title: 'Tatxat' },
  ];

  inlineButtons.forEach(function (btn) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'ib-html-toolbar__btn';
    b.title = btn.title;
    b.innerHTML = btn.icon;
    b.addEventListener('mousedown', function (e) {
      e.preventDefault();
      content.focus();
      document.execCommand(btn.cmd, false, null);
      syncToTextarea();
      updateToolbarState();
    });
    toolbar.appendChild(b);
  });

  sep();

  // List buttons
  [
    { cmd: 'insertUnorderedList', icon: '<i class="fa-solid fa-list-ul"></i>', title: 'Llista amb vinyetes' },
    { cmd: 'insertOrderedList',   icon: '<i class="fa-solid fa-list-ol"></i>',  title: 'Llista numerada' },
  ].forEach(function (btn) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'ib-html-toolbar__btn';
    b.title = btn.title;
    b.innerHTML = btn.icon;
    b.addEventListener('mousedown', function (e) {
      e.preventDefault();
      content.focus();
      document.execCommand(btn.cmd, false, null);
      syncToTextarea();
    });
    toolbar.appendChild(b);
  });

  sep();

  // Link button
  var linkBtn = document.createElement('button');
  linkBtn.type = 'button';
  linkBtn.className = 'ib-html-toolbar__btn';
  linkBtn.title = 'Inserir enllaç';
  linkBtn.innerHTML = '<i class="fa-solid fa-link"></i>';
  linkBtn.addEventListener('mousedown', function (e) {
    e.preventDefault();
    content.focus();
    // El dialeg (async) roba el focus i amb ell la seleccio del contenteditable:
    // es desa el Range abans d'obrir-lo i es restaura abans del createLink.
    var selBefore = window.getSelection();
    var savedRange = (selBefore && selBefore.rangeCount) ? selBefore.getRangeAt(0).cloneRange() : null;
    IbModal.prompt({ message: 'URL de l\'enllaç (inclou https://):', value: 'https://' }).then(function (url) {
      if (!url || url === 'https://') return;
      content.focus();
      if (savedRange) {
        var selNow = window.getSelection();
        selNow.removeAllRanges();
        selNow.addRange(savedRange);
      }
      document.execCommand('createLink', false, url);
      // Make link open in new tab
      var sel = window.getSelection();
      if (sel && sel.anchorNode) {
        var a = sel.anchorNode.parentElement;
        if (a && a.tagName === 'A') a.target = '_blank';
      }
      syncToTextarea();
    });
  });
  toolbar.appendChild(linkBtn);

  var unlinkBtn = document.createElement('button');
  unlinkBtn.type = 'button';
  unlinkBtn.className = 'ib-html-toolbar__btn';
  unlinkBtn.title = 'Eliminar enllaç';
  unlinkBtn.innerHTML = '<i class="fa-solid fa-link-slash"></i>';
  unlinkBtn.addEventListener('mousedown', function (e) {
    e.preventDefault();
    content.focus();
    document.execCommand('unlink', false, null);
    syncToTextarea();
  });
  toolbar.appendChild(unlinkBtn);

  sep();

  // Clear formatting
  var clearBtn = document.createElement('button');
  clearBtn.type = 'button';
  clearBtn.className = 'ib-html-toolbar__btn';
  clearBtn.title = 'Eliminar format';
  clearBtn.innerHTML = '<i class="fa-solid fa-eraser"></i>';
  clearBtn.addEventListener('mousedown', function (e) {
    e.preventDefault();
    content.focus();
    document.execCommand('removeFormat', false, null);
    syncToTextarea();
  });
  toolbar.appendChild(clearBtn);

  sep();

  // Source code toggle button
  var sourceBtn = document.createElement('button');
  sourceBtn.type = 'button';
  sourceBtn.className = 'ib-html-toolbar__btn';
  sourceBtn.title = 'Codi font HTML';
  sourceBtn.innerHTML = '<i class="fa-solid fa-code"></i>';
  var sourceMode = false;
  var sourceArea = document.createElement('textarea');
  sourceArea.className = 'ib-html-source';
  sourceArea.style.display = 'none';

  sourceBtn.addEventListener('mousedown', function (e) {
    e.preventDefault();
    sourceMode = !sourceMode;
    if (sourceMode) {
      sourceArea.value = content.innerHTML;
      content.style.display = 'none';
      sourceArea.style.display = '';
      sourceArea.focus();
      sourceBtn.classList.add('ib-active');
    } else {
      content.innerHTML = sourceArea.value;
      content.style.display = '';
      sourceArea.style.display = 'none';
      sourceBtn.classList.remove('ib-active');
      syncToTextarea();
    }
  });
  sourceArea.addEventListener('input', function () {
    textarea.value = sourceArea.value;
  });
  toolbar.appendChild(sourceBtn);

  // Contenteditable area
  var content = document.createElement('div');
  content.className = 'ib-html-editor-content';
  content.contentEditable = 'true';
  content.setAttribute('role', 'textbox');
  content.setAttribute('aria-multiline', 'true');
  content.setAttribute('spellcheck', 'true');
  content.innerHTML = initialHtml || '<p><br></p>';

  // Sync helpers
  function syncToTextarea() {
    var html = content.innerHTML;
    // Normalize empty editor
    if (html === '<br>' || html === '<p><br></p>' || html.trim() === '') {
      textarea.value = '';
    } else {
      textarea.value = html;
    }
  }

  function updateToolbarState() {
    // Update inline button active states
    inlineButtons.forEach(function (btn, i) {
      var el = toolbar.querySelectorAll('.ib-html-toolbar__btn')[i];
      if (!el) return;
      try {
        if (document.queryCommandState(btn.cmd)) {
          el.classList.add('ib-active');
        } else {
          el.classList.remove('ib-active');
        }
      } catch (ex) { /* ignore */ }
    });

    // Update heading selector
    try {
      var val = document.queryCommandValue('formatBlock').toLowerCase();
      headSel.value = val === 'div' || val === 'p' ? '' : val;
    } catch (ex) { headSel.value = ''; }
  }

  // Heading select handler
  headSel.addEventListener('change', function () {
    content.focus();
    var val = headSel.value;
    if (val === '') {
      document.execCommand('formatBlock', false, 'p');
    } else {
      document.execCommand('formatBlock', false, val);
    }
    syncToTextarea();
    // Restore focus
    setTimeout(function () { content.focus(); }, 0);
  });

  // Content events
  content.addEventListener('input', function () {
    syncToTextarea();
    updateToolbarState();
  });
  content.addEventListener('keyup', updateToolbarState);
  content.addEventListener('mouseup', updateToolbarState);

  // Sync on form submit
  var form = textarea.closest('form');
  if (form) {
    form.addEventListener('submit', syncToTextarea);
  }

  // Assemble DOM: wrap > toolbar + content + sourceArea + textarea(hidden)
  textarea.style.display = 'none';
  wrap.appendChild(toolbar);
  wrap.appendChild(content);
  wrap.appendChild(sourceArea);
  wrap.appendChild(textarea);
}


/* ==========================================================================
   12. Editor form — auto-focus first input on create
   ========================================================================== */

/**
 * When the editor form is rendered (.ib-editor), focus the first
 * visible, non-readonly text input or select for faster keyboard entry.
 */
function ibInitEditorAutoFocus() {
  var editor = document.querySelector('.ib-editor');
  if (!editor) return;

  // Only auto-focus on "create" pages (form has id=0)
  var idInput = editor.querySelector('input[name="id"]');
  if (!idInput || idInput.value !== '0') return;

  var firstInput = editor.querySelector(
    '.ib-form__fields input:not([type="hidden"]):not([readonly]):not([disabled]),' +
    '.ib-form__fields select:not([disabled]),' +
    '.ib-form__fields textarea:not([readonly]):not([disabled])'
  );
  if (firstInput) {
    setTimeout(function () { firstInput.focus(); }, 100);
  }
}

/* ==========================================================================
   13. Table row clickable — click row to navigate to detail view
   ========================================================================== */

/**
 * Makes entire table rows in .ib-table clickable to navigate to the detail view.
 * Uses data-href attribute on the row (set by DataBrowser).
 * Skips if the click target is an interactive element (links, buttons, inputs).
 */
function ibInitTableRowClick() {
  var tables = document.querySelectorAll('.ib-table');
  tables.forEach(function (table) {
    table.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, form, label')) return;

      var row = e.target.closest('tr.ib-table__row');
      if (!row) return;

      var href = row.getAttribute('data-href');
      if (href) {
        window.location.href = href;
      }
    });

    // Visual affordance: pointer cursor on clickable rows
    table.querySelectorAll('tr.ib-table__row[data-href]').forEach(function (row) {
      row.style.cursor = 'pointer';
    });
  });
}

/**
 * Columnes de sobretaula / essencials (regla fields_desktop + fields_mobile del
 * KMS, genèrica): el contenidor arriba amb .ib-cols-desk (només les columnes
 * .desk). Si aquestes desborden el contenidor (scrollWidth > clientWidth, sense
 * llindar fix) es passa a .ib-cols-mob (només les .mob); en redimensionar es
 * torna a mesurar des de l'estat de sobretaula. El commutador «Totes les
 * columnes» (.ib-cols-all, localStorage ib-all-cols-<mod>) mostra $fields sencer
 * amb scroll horitzontal.
 */
function ibInitTableOverflow() {
  document.querySelectorAll('.ib-browser__table-wrap').forEach(function (wrap) {
    var table = wrap.querySelector('table.ib-table[data-desk-cols]');
    if (!table) return;
    var mod = table.getAttribute('data-module') || '';
    var key = 'ib-all-cols-' + mod;
    var toggle = document.querySelector('.ib-allcols-toggle[data-mod="' + mod + '"]');
    // El control nomes te sentit si hi ha res per destapar: columnes fora de
    // les de sobretaula, o el llistat plegat a les essencials. Amb un modul
    // que ja ensenya totes les seves columnes d'entrada, activar-lo no feia
    // res i semblava espatllat (04/09/2026).
    var label = toggle ? toggle.closest('label') : null;
    function syncToggleVisibility(all) {
      if (!label) return;
      var hasHidden = table.querySelector('th:not(.desk)') !== null;
      var collapsed = wrap.classList.contains('ib-cols-mob');
      label.style.display = (all || hasHidden || collapsed) ? '' : 'none';
    }

    function apply() {
      var all = false;
      try { all = localStorage.getItem(key) === '1'; } catch (e) {}
      if (toggle) toggle.checked = all;
      wrap.classList.remove('ib-cols-all', 'ib-cols-mob');
      if (all) {
        wrap.classList.remove('ib-cols-desk');
        wrap.classList.add('ib-cols-all');
        syncToggleVisibility(true);
        return;
      }
      wrap.classList.add('ib-cols-desk');
      // data-keep-desk: en pantalla ampla el llistat manté les columnes de
      // sobretaula encara que desbordin (el contenidor ja fa scroll horitzontal),
      // com fa el KMS. Per sota de 880px —el llindar de tauleta del KMS— es
      // plega igualment a les essencials.
      if (table.getAttribute('data-keep-desk') === '1' && window.innerWidth > 880) {
        syncToggleVisibility(false);
        return;
      }
      if (table.scrollWidth > wrap.clientWidth) { wrap.classList.remove('ib-cols-desk'); wrap.classList.add('ib-cols-mob'); }
      syncToggleVisibility(false);
    }
    apply();
    var timer;
    window.addEventListener('resize', function () { clearTimeout(timer); timer = setTimeout(apply, 100); });
    if (toggle) {
      toggle.addEventListener('change', function () {
        try { if (toggle.checked) localStorage.setItem(key, '1'); else localStorage.removeItem(key); } catch (e) {}
        apply();
      });
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  ibInitEditorAutoFocus();
  ibInitTableRowClick();
  ibInitTableOverflow();
});

// =============================================================================
// Notification Polling + Toast Popup (bell → toast for new notifications)
// =============================================================================
function ibInitNotificationPolling() {
  var bell = document.getElementById('ib-notif-btn');
  var badge = document.getElementById('ib-notif-badge');
  if (!bell) return;

  var STORAGE_KEY = 'ib_notif_last_seen_id';
  var lastSeenId = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10) || 0;
  var primed = false;
  var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  // ─ "Event starts now" reminders → centred modal (Descartar/Postposar/Finalitzar) ─
  // The cal-reminders cron tags the at-event-time notification's URL with &at=1.
  // Those get a modal instead of a toast, and show even on the first poll after
  // load (a just-started event must not be missed). One modal at a time.
  //
  // Read state on the server is the single source of truth for "already dealt
  // with": every exit path (dismiss, snooze, finalize) marks the notification
  // read, and the pollers below only ever surface unread items. This map is
  // just a per-page guard so primeEventModals() and the first poll() can't
  // queue the same notification twice.
  var eventModalQueue = [];
  var eventModalOpen = false;
  var eventModalSeen = {};
  // The modal currently on screen: { id, close } — so a "dealt with elsewhere"
  // signal can take it down without going through markRead again.
  var activePrompt = null;
  // Drop the pre-2026-08 per-browser "seen" list: it used to suppress modals
  // that had never been acted on, stranding them unread on the bell forever.
  try { localStorage.removeItem('ib_event_modal_seen'); } catch (_) {}

  // ── One answer, every window ──
  // Answering the prompt in one tab closes it in all the others: same browser
  // through a BroadcastChannel (with a localStorage `storage` event as the
  // fallback), other browsers/devices through the poll below, which asks the
  // server whether the modals this page shows are still pending (&check=).
  var PROMPT_DONE_KEY = 'ib_event_prompt_done';
  var promptChannel = ('BroadcastChannel' in window) ? new BroadcastChannel('ib-event-prompt') : null;
  if (promptChannel) {
    promptChannel.onmessage = function (e) {
      if (e.data && e.data.done) settlePrompt(parseInt(e.data.done, 10));
    };
  }
  window.addEventListener('storage', function (e) {
    if (e.key !== PROMPT_DONE_KEY || !e.newValue) return;
    settlePrompt(parseInt(String(e.newValue).split(':')[0], 10));
  });
  function announcePromptDone(id) {
    if (promptChannel) { try { promptChannel.postMessage({ done: id }); } catch (_) {} }
    // The value carries a timestamp so repeating the same id still fires
    // `storage` (it only fires on a change).
    try { localStorage.setItem(PROMPT_DONE_KEY, id + ':' + Date.now()); } catch (_) {}
  }
  // The prompt was answered elsewhere (or is no longer pending): forget it
  // here, and if it's the one on screen, take it down without marking read —
  // whoever answered it already did.
  function settlePrompt(id) {
    if (!id) return;
    eventModalSeen[id] = 1;
    eventModalQueue = eventModalQueue.filter(function (q) { return q.id !== id; });
    if (activePrompt && activePrompt.id === id) activePrompt.close();
  }

  // ── Only while the event is still on ──
  // The cron stamps &until=<unix> (end of the event, or start + 1 h without
  // an end; end of day for all-day ones; due + 1 h for tasks). Prompts from
  // before that stamp age out one hour after their creation instead. Past the
  // deadline the modal is never raised — and closes itself if it is open —
  // while the notification stays on the bell, unread, for the user to find.
  var PROMPT_FALLBACK_SECS = 3600;
  function promptDeadline(n) {
    var m = String(n && n.url || '').match(/[?&]until=(\d+)(?:&|$)/);
    if (m) return parseInt(m[1], 10);
    var ts = parseInt(n && n.ts || 0, 10);
    return ts > 0 ? ts + PROMPT_FALLBACK_SECS : 0;
  }
  function promptIsLive(n) {
    var d = promptDeadline(n);
    return d === 0 || d > Date.now() / 1000;
  }

  // Two shapes carry the "act on this now" prompt: calendar events
  // (/planner/calendar?event=N&source=…) and board tasks (/planner/tasks/N).
  function isEventTimeNotif(n) {
    if (!n || !n.url || !/[?&]at=1(?:&|$)/.test(n.url)) return false;
    return /[?&]event=\d+/.test(n.url) || /\/planner\/tasks\/\d+\?/.test(n.url);
  }

  function eventFromNotif(n) {
    var url = String(n.url || '');
    var task = url.match(/\/planner\/tasks\/(\d+)\?/);
    if (task) return { id: parseInt(task[1], 10), source: 'task' };
    var m = url.match(/[?&]event=(\d+)/);
    var s = url.match(/[?&]source=(cal|planner|gcal)(?:&|$)/);
    return {
      id: m ? parseInt(m[1], 10) : 0,
      source: s ? s[1] : 'cal'
    };
  }

  function queueEventModal(n) {
    if (!n || !n.id || eventModalSeen[n.id]) return;
    eventModalSeen[n.id] = 1;
    if (!promptIsLive(n)) return;
    eventModalQueue.push(n);
    if (!eventModalOpen) showNextEventModal();
  }

  function showNextEventModal() {
    if (eventModalOpen) return;
    var n = eventModalQueue.shift();
    if (n) { eventModalOpen = true; renderEventModal(n); }
  }

  function renderEventModal(n) {
    var overlay = document.createElement('div');
    overlay.className = 'ib-overlay ib-event-reminder__overlay';

    var modal = document.createElement('div');
    modal.className = 'ib-modal ib-event-reminder';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');

    var ev = eventFromNotif(n);
    var isTask = ev.source === 'task';

    var header = document.createElement('div');
    header.className = 'ib-modal__header';
    var hIcon = document.createElement('i');
    hIcon.className = isTask ? 'fa-solid fa-list-check' : 'fa-solid fa-calendar-day';
    hIcon.setAttribute('aria-hidden', 'true');
    var hSpan = document.createElement('span');
    hSpan.textContent = window.t(isTask ? 'planner_task_reminder_now' : 'planner_event_reminder_now');
    var btnClose = document.createElement('button');
    btnClose.type = 'button';
    btnClose.className = 'ib-event-reminder__close';
    btnClose.setAttribute('aria-label', window.t('planner_event_dismiss'));
    btnClose.title = window.t('planner_event_dismiss');
    var closeIcon = document.createElement('i');
    closeIcon.className = 'fa-solid fa-xmark';
    closeIcon.setAttribute('aria-hidden', 'true');
    btnClose.appendChild(closeIcon);
    header.appendChild(hIcon);
    header.appendChild(hSpan);
    header.appendChild(btnClose);

    var body = document.createElement('div');
    body.className = 'ib-modal__body';
    var title = document.createElement('div');
    title.className = 'ib-event-reminder__title';
    // n.title is "Recordatori: <event title>" — drop the prefix for the modal.
    title.textContent = (n.title || '').replace(/^Recordatori:\s*/i, '');
    var msg = document.createElement('div');
    msg.className = 'ib-event-reminder__msg';
    msg.textContent = n.msg || '';
    body.appendChild(title);
    body.appendChild(msg);

    var footer = document.createElement('div');
    footer.className = 'ib-modal__footer';

    // Obrir → acknowledge and go to the event/task (same link as the bell).
    // Simply closing (×, Esc, click outside) still acknowledges without
    // touching anything; the footer no longer repeats that as "Descartar"
    // (15/10/2026, user request).
    var btnOpen = document.createElement('button');
    btnOpen.type = 'button';
    btnOpen.className = 'ib-btn ib-btn--ghost';
    btnOpen.textContent = window.t('planner_event_open');

    // Postposar opens a small dialog of its own: 30 min, 1 h, 2 h or a
    // number of hours, plus "edit the dates" (see openSnoozeDialog).
    var btnPostpone = document.createElement('button');
    btnPostpone.type = 'button';
    btnPostpone.className = 'ib-btn ib-btn--secondary';
    btnPostpone.textContent = window.t('planner_event_postpone');

    // Finalitzar writes finalized_date (events) or status=done (tasks), neither
    // of which exists for Google events — those are mirrored read-only, so they
    // get Descartar + Postposar and nothing that would pretend to close them.
    var canFinalize = ev.source !== 'gcal';
    var btnFinish = document.createElement('button');
    btnFinish.type = 'button';
    btnFinish.className = 'ib-btn ib-btn--primary';
    btnFinish.textContent = window.t('planner_event_finish');

    footer.appendChild(btnOpen);
    footer.appendChild(btnPostpone);
    // Left out of the DOM rather than [hidden]: .ib-btn sets display:inline-flex,
    // which wins over the UA's [hidden] rule at equal specificity.
    if (canFinalize) footer.appendChild(btnFinish);

    modal.appendChild(header);
    modal.appendChild(body);
    modal.appendChild(footer);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    function onKey(e) {
      if (e.key !== 'Escape') return;
      if (snoozeDlg) { closeSnoozeDialog(); return; }
      dismiss();
    }
    var closed = false;
    var deadlineTimer = null;
    var snoozeDlg = null;
    function closeModal() {
      // Reachable twice: an answer here whose markRead is in flight, and the
      // same answer echoed back from another window. Only the first counts.
      if (closed) return;
      closed = true;
      if (deadlineTimer) clearTimeout(deadlineTimer);
      closeSnoozeDialog();
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      document.removeEventListener('keydown', onKey);
      if (activePrompt && activePrompt.id === n.id) activePrompt = null;
      eventModalOpen = false;
      showNextEventModal();
    }
    activePrompt = { id: n.id, close: closeModal };

    // Once the event is over the modal steps back on its own; the bell keeps
    // the notification. setTimeout overflows past ~24.8 days, so cap it.
    var deadline = promptDeadline(n);
    if (deadline > 0) {
      var msLeft = Math.min(deadline * 1000 - Date.now(), 2147483647);
      deadlineTimer = setTimeout(closeModal, Math.max(0, msLeft));
    }
    function lockButtons() {
      btnOpen.disabled = true;
      btnPostpone.disabled = true;
      btnFinish.disabled = true;
      if (snoozeDlg) snoozeDlg.querySelectorAll('button, input').forEach(function (b) { b.disabled = true; });
    }
    document.addEventListener('keydown', onKey);

    // Every exit path clears the notification: the modal is the prompt, so once
    // it's been answered the bell must not keep counting it. The cron won't
    // re-prompt for the same event either — dispatchDueEventPrompts de-dupes on
    // the notification existing, read or not.
    function markRead() {
      announcePromptDone(n.id);
      return fetch('/notifications/mark-read', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: '_token=' + encodeURIComponent(csrfToken) + '&id=' + encodeURIComponent(n.id)
      }).then(function (r) { return r.json(); }, function () { return null; })
        .then(function (data) {
          if (data && data.ok) setBadge(parseInt(data.unread || 0, 10));
          if (typeof window.ibNotifRefreshPanel === 'function') window.ibNotifRefreshPanel();
          return data;
        });
    }

    // Descartar → acknowledge and move on. The event is left exactly as it is:
    // not finalized, not rescheduled.
    function dismiss() {
      lockButtons();
      markRead().then(closeModal);
    }
    btnClose.addEventListener('click', dismiss);

    // The prompt's own markers (at=1, until=) mean nothing to the page.
    function openTarget() {
      lockButtons();
      var url = String(n.url || '').replace(/([?&])(at|until)=[^&]*(&|$)/g, function (m, pre, k, post) {
        return post ? pre : '';
      }).replace(/([?&])(at|until)=[^&]*(&|$)/g, function (m, pre, k, post) {
        return post ? pre : '';
      }).replace(/[?&]$/, '');
      markRead().then(function () {
        closeModal();
        window.location.href = url || '/system/sys_notifications';
      });
    }
    btnOpen.addEventListener('click', openTarget);
    overlay.addEventListener('click', function (e) {
      if (e.target !== overlay) return;
      dismiss();
    });

    // ── Postposar → re-arm the prompt N minutes from now ──
    var SNOOZE_PRESETS = [30, 60, 120];
    var SNOOZE_MAX_HOURS = 168; // cal-event-snooze accepts up to a week

    function snoozeLabel(m) {
      if (m % 60 === 0) return (m / 60) + ' ' + window.t('planner_reminder_hour_abbr');
      if (m > 60) return Math.floor(m / 60) + ' ' + window.t('planner_reminder_hour_abbr') + ' ' + (m % 60) + ' ' + window.t('planner_reminder_minutes_abbr');
      return m + ' ' + window.t('planner_reminder_minutes_abbr');
    }

    function closeSnoozeDialog() {
      if (!snoozeDlg) return;
      if (snoozeDlg.parentNode) snoozeDlg.parentNode.removeChild(snoozeDlg);
      snoozeDlg = null;
      btnPostpone.focus();
    }

    function openSnoozeDialog() {
      if (snoozeDlg) return;
      var dlgOverlay = document.createElement('div');
      dlgOverlay.className = 'ib-overlay ib-event-snooze__overlay';
      var dlg = document.createElement('div');
      dlg.className = 'ib-modal ib-event-snooze';
      dlg.setAttribute('role', 'dialog');
      dlg.setAttribute('aria-modal', 'true');

      var head = document.createElement('div');
      head.className = 'ib-modal__header';
      var hTitle = document.createElement('span');
      hTitle.textContent = window.t('planner_snooze_title');
      head.appendChild(hTitle);

      var main = document.createElement('div');
      main.className = 'ib-modal__body';
      var grid = document.createElement('div');
      grid.className = 'ib-event-snooze__grid';
      SNOOZE_PRESETS.forEach(function (m) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ib-event-snooze__opt';
        b.textContent = snoozeLabel(m);
        b.addEventListener('click', function () { snooze(m); });
        grid.appendChild(b);
      });
      var customBtn = document.createElement('button');
      customBtn.type = 'button';
      customBtn.className = 'ib-event-snooze__opt';
      customBtn.textContent = window.t('planner_snooze_custom');
      grid.appendChild(customBtn);
      main.appendChild(grid);

      // Personalitzat: a number of hours (halves allowed).
      var custom = document.createElement('form');
      custom.className = 'ib-event-snooze__custom';
      custom.hidden = true;
      var hours = document.createElement('input');
      hours.type = 'number';
      hours.min = '0.5';
      hours.max = String(SNOOZE_MAX_HOURS);
      hours.step = '0.5';
      hours.value = '3';
      hours.className = 'ib-input ib-event-snooze__hours';
      hours.setAttribute('aria-label', window.t('planner_snooze_hours'));
      var unit = document.createElement('span');
      unit.textContent = window.t('planner_snooze_hours');
      var go = document.createElement('button');
      go.type = 'submit';
      go.className = 'ib-btn ib-btn--primary';
      go.textContent = window.t('planner_event_postpone');
      custom.appendChild(hours);
      custom.appendChild(unit);
      custom.appendChild(go);
      main.appendChild(custom);
      customBtn.addEventListener('click', function () {
        custom.hidden = false;
        customBtn.classList.add('is-active');
        hours.focus();
        hours.select();
      });
      custom.addEventListener('submit', function (e) {
        e.preventDefault();
        var h = parseFloat(String(hours.value).replace(',', '.'));
        if (!(h > 0) || h > SNOOZE_MAX_HOURS) { hours.focus(); hours.select(); return; }
        snooze(Math.max(1, Math.round(h * 60)));
      });

      var foot = document.createElement('div');
      foot.className = 'ib-modal__footer';
      // Opening the event to reschedule it is a real answer to the prompt
      // too, so clear the notification before navigating away.
      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'ib-btn ib-btn--ghost';
      editBtn.textContent = window.t('planner_event_edit_dates');
      editBtn.addEventListener('click', function () {
        lockButtons();
        markRead().then(function () { closeModal(); window.open(n.url, '_blank'); });
      });
      var cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'ib-btn ib-btn--secondary';
      cancelBtn.textContent = window.t('cancel');
      cancelBtn.addEventListener('click', closeSnoozeDialog);
      foot.appendChild(editBtn);
      foot.appendChild(cancelBtn);

      dlg.appendChild(head);
      dlg.appendChild(main);
      dlg.appendChild(foot);
      dlgOverlay.appendChild(dlg);
      dlgOverlay.addEventListener('click', function (e) { if (e.target === dlgOverlay) closeSnoozeDialog(); });
      document.body.appendChild(dlgOverlay);
      snoozeDlg = dlgOverlay;
      var first = grid.querySelector('button');
      if (first) first.focus();
    }

    function snooze(minutes) {
      lockButtons();
      var req = ev.id
        ? fetch('/planner/api/cal-event-snooze', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ id: ev.id, source: ev.source, minutes: minutes })
          }).then(function (r) { return r.json(); }, function () { return null; })
        : Promise.resolve(null);

      req.then(function (data) {
        if (data && data.ok) {
          showToast({
            type: 'info',
            icon: 'fa-solid fa-clock-rotate-left',
            title: window.t('planner_event_snoozed'),
            msg: snoozeLabel(minutes)
          });
        }
        return markRead();
      }).then(closeModal);
    }

    btnPostpone.addEventListener('click', openSnoozeDialog);

    // Finalitzar → mark the event finalized (finalized_date = today, like the
    // calendar's ✓ Finalitzar) AND mark the notification read so it drops off
    // the badge. Finalizing also stops the every-minute cron from re-prompting.
    btnFinish.addEventListener('click', function () {
      lockButtons();

      var action, body;
      if (isTask) {
        // Board task: the planner's own "finish" quick action (status=done +
        // completed_at), so it behaves exactly like ticking it off the board.
        action = 'action';
        body = { id: ev.id, action: 'finish' };
      } else {
        var d = new Date();
        var today = d.getFullYear() + '-'
          + String(d.getMonth() + 1).padStart(2, '0') + '-'
          + String(d.getDate()).padStart(2, '0');
        action = ev.source === 'planner' ? 'event-update' : 'cal-event-update';
        body = {
          id: ev.id,
          finalized_date: today,
          status: ev.source === 'planner' ? 'completed' : 'confirmed'
        };
      }

      var finalizeReq = ev.id
        ? fetch('/planner/api/' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(body)
          }).then(function (r) { return r.json(); }, function () { return null; })
        : Promise.resolve(null);

      finalizeReq.then(markRead).then(closeModal);
    });
  }

  function ensureToastRoot() {
    var root = document.getElementById('ib-notif-toasts');
    if (root) return root;
    root = document.createElement('div');
    root.id = 'ib-notif-toasts';
    root.className = 'ib-notif-toasts';
    document.body.appendChild(root);
    return root;
  }

  function showToast(n) {
    var root = ensureToastRoot();
    var el = document.createElement('a');
    el.className = 'ib-notif-toast ib-notif-toast--' + (n.type || 'info');
    if (n.url) el.href = n.url;
    el.setAttribute('role', 'alert');

    var icon = document.createElement('i');
    icon.className = (n.icon || 'fa-solid fa-bell') + ' ib-notif-toast__icon';
    icon.setAttribute('aria-hidden', 'true');
    el.appendChild(icon);

    var body = document.createElement('div');
    body.className = 'ib-notif-toast__body';
    var title = document.createElement('div');
    title.className = 'ib-notif-toast__title';
    title.textContent = n.title || '';
    body.appendChild(title);
    var msg = document.createElement('div');
    msg.className = 'ib-notif-toast__msg';
    msg.textContent = n.msg || '';
    body.appendChild(msg);
    el.appendChild(body);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'ib-notif-toast__close';
    close.setAttribute('aria-label', 'Close');
    close.textContent = '\u00d7';
    close.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      dismiss(el);
    });
    el.appendChild(close);

    root.appendChild(el);
    requestAnimationFrame(function(){ el.classList.add('ib-notif-toast--in'); });
    var timer = setTimeout(function(){ dismiss(el); }, 10000);
    el.addEventListener('mouseenter', function(){ clearTimeout(timer); });
  }

  function dismiss(el) {
    if (!el || !el.parentNode) return;
    el.classList.remove('ib-notif-toast--in');
    el.classList.add('ib-notif-toast--out');
    setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 250);
  }

  function pulseBell() {
    bell.classList.remove('ib-notif__btn--pulse');
    void bell.offsetWidth;
    bell.classList.add('ib-notif__btn--pulse');
    setTimeout(function(){ bell.classList.remove('ib-notif__btn--pulse'); }, 1800);
  }

  function setBadge(count) {
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 99 ? '99' : String(count);
      badge.classList.remove('ib-notif-badge--hidden');
    } else {
      badge.textContent = '';
      badge.classList.add('ib-notif-badge--hidden');
    }
  }

  async function poll() {
    try {
      // Ask about the prompts this page is showing or holding, so one answered
      // from another device (or expired) comes down here within a minute —
      // or at once on the poll that fires when the tab becomes visible.
      var showing = eventModalQueue.map(function (q) { return q.id; });
      if (activePrompt) showing.unshift(activePrompt.id);
      var res = await fetch('/notifications/poll?since=' + lastSeenId
          + (showing.length ? '&check=' + showing.join(',') : ''), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) return;
      var data = await res.json();
      if (!data || !data.ok) return;

      setBadge(parseInt(data.unread || 0, 10));
      (Array.isArray(data.gone) ? data.gone : []).forEach(function (id) { settlePrompt(parseInt(id, 10)); });

      var items = Array.isArray(data.items) ? data.items : [];
      if (items.length === 0) return;

      var maxId = lastSeenId;
      items.forEach(function(it){ if (it.id > maxId) maxId = it.id; });

      // "Event starts now" reminders get a centre modal regardless of `primed`
      // so a just-started event isn't missed on the first poll after load.
      var eventItems = items.filter(isEventTimeNotif);
      var normalItems = items.filter(function(it){ return !isEventTimeNotif(it); });

      eventItems.sort(function(a,b){ return a.id - b.id; });
      eventItems.forEach(queueEventModal);

      if (primed) {
        normalItems.sort(function(a,b){ return a.id - b.id; });
        normalItems.forEach(function(it){ showToast(it); });
        if (normalItems.length > 0) pulseBell();
      }
      if ((eventItems.length || normalItems.length) && typeof window.ibNotifRefreshPanel === 'function') {
        window.ibNotifRefreshPanel();
      }

      lastSeenId = maxId;
      localStorage.setItem(STORAGE_KEY, String(lastSeenId));
      primed = true;
    } catch (_) {}
  }

  // On load, surface any still-pending (unread) "event starts now" reminders as
  // modals — independent of lastSeenId, so a prompt created while the tab was
  // closed (or under stale JS) isn't lost. queueEventModal skips the ones whose
  // event is already over (they stay on the bell). `since=0` returns the recent
  // unread set.
  async function primeEventModals() {
    try {
      var res = await fetch('/notifications/poll?since=0', {
        credentials: 'same-origin', headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) return;
      var data = await res.json();
      if (!data || !data.ok) return;
      (Array.isArray(data.items) ? data.items : [])
        .filter(isEventTimeNotif)
        .sort(function (a, b) { return a.id - b.id; })
        .forEach(queueEventModal);
    } catch (_) {}
  }

  primeEventModals();
  poll();
  setInterval(poll, 60000);
  document.addEventListener('visibilitychange', function(){
    if (document.visibilityState === 'visible') poll();
  });
}

document.addEventListener('DOMContentLoaded', function () {
  ibInitNotificationBell();
  ibInitGlobalSearch();
  ibInitNotificationPolling();
});

/* ==========================================================================
   14. Ctrl+S / Cmd+S — Save form shortcut
   ========================================================================== */

/**
 * Pressing Ctrl+S (Windows/Linux) or Cmd+S (Mac) while in an .ib-editor form
 * submits the form. Shows a brief visual indication that the save was triggered.
 * Does not intercept browser save dialog for non-editor pages.
 */
function ibInitFormSaveShortcut() {
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      var form = document.querySelector('.ib-editor .ib-form');
      if (!form) return; // Only active in editor pages
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.classList.add('ib-btn--saving');
        btn.disabled = true;
        setTimeout(function () { btn.disabled = false; btn.classList.remove('ib-btn--saving'); }, 1500);
      }
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
  });
}

/* ==========================================================================
   15. Form validation — highlight missing required fields on submit
   ========================================================================== */

/**
 * On form submit, checks all required inputs/selects/textareas.
 * If any are empty, adds .ib-input--error and shows an inline error message.
 * Scrolls to the first invalid field. Returns false if invalid (blocks submit).
 */
function ibInitFormValidation() {
  var forms = document.querySelectorAll('.ib-form');
  forms.forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var invalid = [];
      form.querySelectorAll('[required]').forEach(function (el) {
        var val = el.value ? el.value.trim() : '';
        // For selects, empty = no selection
        if (val === '' || val === null) {
          el.classList.add('ib-input--error');
          var row = el.closest('.ib-form__row');
          if (row && !row.querySelector('.ib-form__error-msg')) {
            var msg = document.createElement('p');
            msg.className = 'ib-form__error-msg';
            msg.textContent = el.getAttribute('data-error-msg') || 'Camp obligatori';
            el.closest('.ib-form__control').appendChild(msg);
          }
          invalid.push(el);
        } else {
          el.classList.remove('ib-input--error');
          var existingMsg = el.closest('.ib-form__row') && el.closest('.ib-form__row').querySelector('.ib-form__error-msg');
          if (existingMsg) existingMsg.remove();
        }
      });

      if (invalid.length > 0) {
        e.preventDefault();
        // Si el camp és en una pestanya amagada, obre-la: si no, no es veu què falta
        var panel = invalid[0].closest('.ib-tabs__panel');
        if (panel && !panel.classList.contains('ib-tabs__panel--active')) {
          var nav = panel.closest('.ib-tabs') && panel.closest('.ib-tabs').querySelector('.ib-tabs__nav-item[data-tab="' + panel.id + '"]');
          if (nav) nav.click();
        }
        invalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        invalid[0].focus();
        return false;
      }
    });

    // Clear error on input change
    form.addEventListener('input', function (e) {
      if (e.target.hasAttribute('required') && e.target.value.trim() !== '') {
        e.target.classList.remove('ib-input--error');
        var msg = e.target.closest('.ib-form__row') && e.target.closest('.ib-form__row').querySelector('.ib-form__error-msg');
        if (msg) msg.remove();
      }
    }, true);

    form.addEventListener('change', function (e) {
      if (e.target.hasAttribute('required') && e.target.value.trim() !== '') {
        e.target.classList.remove('ib-input--error');
        var msg = e.target.closest('.ib-form__row') && e.target.closest('.ib-form__row').querySelector('.ib-form__error-msg');
        if (msg) msg.remove();
      }
    }, true);
  });
}

/* ==========================================================================
   16. Invoice / financial form — live total preview
   ========================================================================== */

/**
 * When an .ib-form has fields named base_amount, vat_pct, irpf_pct,
 * shows a live calculation preview panel below the form fields.
 * Works for ErpInvoices, ErpSupplierInvoices, ErpProformaInvoices, etc.
 */
function ibInitLiveInvoiceCalc() {
  var baseInput  = document.getElementById('field_base_amount');
  var vatInput   = document.getElementById('field_vat_pct');
  var irpfInput  = document.getElementById('field_irpf_pct');

  if (!baseInput) return; // Not an invoice form

  // Create preview panel
  var preview = document.getElementById('ib-invoice-preview');
  if (!preview) {
    preview = document.createElement('div');
    preview.id = 'ib-invoice-preview';
    preview.className = 'ib-invoice-preview';
    // Insert after form actions or at end of form fields
    var formFields = baseInput.closest('.ib-form__fields') || baseInput.closest('.ib-tabs__panel');
    if (formFields) formFields.appendChild(preview);
  }

  function calc() {
    var base = parseFloat(baseInput.value) || 0;
    var vatPct  = vatInput  ? (parseFloat(vatInput.value) || 0) : 0;
    var irpfPct = irpfInput ? (parseFloat(irpfInput.value) || 0) : 0;
    var vatAmt  = Math.round(base * vatPct  / 100 * 100) / 100;
    var irpfAmt = Math.round(base * irpfPct / 100 * 100) / 100;
    var total   = Math.round((base + vatAmt - irpfAmt) * 100) / 100;

    var fmt = function(n) {
      return n.toLocaleString('ca-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    preview.innerHTML =
      '<div class="ib-invoice-preview__row"><span>Base:</span><strong>' + fmt(base) + ' €</strong></div>' +
      (vatPct > 0 ? '<div class="ib-invoice-preview__row"><span>IVA (' + vatPct + '%):</span><strong>' + fmt(vatAmt) + ' €</strong></div>' : '') +
      (irpfPct > 0 ? '<div class="ib-invoice-preview__row ib-invoice-preview__row--deduct"><span>IRPF (' + irpfPct + '%):</span><strong>-' + fmt(irpfAmt) + ' €</strong></div>' : '') +
      '<div class="ib-invoice-preview__row ib-invoice-preview__row--total"><span>Total:</span><strong>' + fmt(total) + ' €</strong></div>';
  }

  baseInput.addEventListener('input', calc);
  baseInput.addEventListener('change', calc);
  if (vatInput)  { vatInput.addEventListener('input', calc);  vatInput.addEventListener('change', calc); }
  if (irpfInput) { irpfInput.addEventListener('input', calc); irpfInput.addEventListener('change', calc); }

  // Initial calculation
  calc();
}


/* ==========================================================================
   17. Form submit loading state
   ========================================================================== */

function ibInitFormLoading() {
  document.querySelectorAll('.ib-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
      // La validació ha aturat l'enviament: el botó no es pot quedar bloquejat
      if (e.defaultPrevented) return;
      var btn = form.querySelector('button.ib-btn--primary[type="submit"]');
      if (btn && !btn.disabled) {
        btn.classList.add('ib-btn--saving');
        btn.disabled = true;
      }
    });
  });
}

/* ==========================================================================
   18. Tags input
   ========================================================================== */

function ibInitTagsInput() {
  document.querySelectorAll('.ib-tags-input').forEach(function(wrap) {
    var hidden = wrap.querySelector('input[type="hidden"]');
    var field  = wrap.querySelector('.ib-tags-input__field');
    var list   = wrap.querySelector('.ib-tags-input__list');
    if (!hidden || !field) return;

    function getTags() {
      return hidden.value ? hidden.value.split(',').map(function(t) { return t.trim(); }).filter(Boolean) : [];
    }

    function sync(tags) {
      hidden.value = tags.join(', ');
    }

    function renderTags(tags) {
      list.innerHTML = '';
      tags.forEach(function(tag) {
        var span = document.createElement('span');
        span.className = 'ib-tags-input__tag';
        span.textContent = tag;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ib-tags-input__remove';
        btn.innerHTML = '&times;';
        btn.addEventListener('click', function() {
          var current = getTags().filter(function(t) { return t !== tag; });
          sync(current);
          renderTags(current);
        });
        span.appendChild(btn);
        list.appendChild(span);
      });
    }

    function addTag(text) {
      text = text.trim();
      if (!text) return;
      var tags = getTags();
      if (tags.indexOf(text) === -1) {
        tags.push(text);
        sync(tags);
        renderTags(tags);
      }
      field.value = '';
    }

    field.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        addTag(field.value);
      }
      if (e.key === 'Backspace' && field.value === '') {
        var tags = getTags();
        if (tags.length) {
          tags.pop();
          sync(tags);
          renderTags(tags);
        }
      }
    });

    // Also add on blur
    field.addEventListener('blur', function() {
      if (field.value.trim()) addTag(field.value);
    });

    // Handle clicks on pre-rendered remove buttons
    list.addEventListener('click', function(e) {
      var btn = e.target.closest('.ib-tags-input__remove');
      if (!btn) return;
      var tag = btn.getAttribute('data-tag');
      if (tag) {
        var current = getTags().filter(function(t) { return t !== tag; });
        sync(current);
        renderTags(current);
      }
    });
  });
}

// ─── Generic drag-reorder utility ──────────────────────────────────────────
// Used by DataBrowser (table row reorder) and SysModuleManager (module reorder).
//
// Options:
//   scope            – root element or selector (limits event listeners)
//   container        – direct parent of draggable items (element or selector)
//   handleSelector   – CSS selector for the drag handle inside each item
//   itemSelector     – CSS selector for the draggable items
//   draggingClass    – class added to the item being dragged
//   bodyDraggingClass– class added to <body> during drag (optional)
//   crossContainer   – allow dragging between multiple containers matching `container` within `scope`
//   onCreatePlaceholder(dragItem) – return a placeholder element (optional)
//   onReorder()      – called after a successful drop
//
function ibDragReorder(opts) {
  var scopeEl = typeof opts.scope === 'string' ? document.querySelector(opts.scope) : opts.scope;
  if (!scopeEl) return;

  var containerSelector = typeof opts.container === 'string' ? opts.container : null;

  function getContainers() {
    if (opts.crossContainer) {
      return scopeEl.querySelectorAll(containerSelector || ':scope');
    } else {
      var c = containerSelector ? scopeEl.querySelector(containerSelector) : (opts.container || scopeEl);
      return c ? [c] : [];
    }
  }

  // Verify at least one container exists at init
  if (!getContainers().length) return;

  var dragItem = null;
  var placeholder = null;

  scopeEl.addEventListener('mousedown', function (e) {
    var handle = e.target.closest(opts.handleSelector);
    if (!handle) return;
    var item = handle.closest(opts.itemSelector);
    if (!item) return;
    e.preventDefault();

    var containers = getContainers();
    dragItem = item;
    var rect = item.getBoundingClientRect();
    var offsetX = e.clientX - rect.left;
    var offsetY = e.clientY - rect.top;

    // Create placeholder
    if (opts.onCreatePlaceholder) {
      placeholder = opts.onCreatePlaceholder(item);
    } else {
      placeholder = document.createElement(item.tagName);
      placeholder.style.height = rect.height + 'px';
      placeholder.style.background = 'var(--ib-primary-light, #e8f0fe)';
      placeholder.style.border = '2px dashed var(--ib-primary, #000)';
      placeholder.style.borderRadius = '4px';
    }
    item.parentNode.insertBefore(placeholder, item.nextSibling);

    // Style dragged item
    item.classList.add(opts.draggingClass || 'ib-dragging');
    item.style.position = 'fixed';
    item.style.zIndex = '9999';
    item.style.width = rect.width + 'px';
    item.style.left = rect.left + 'px';
    item.style.top = rect.top + 'px';
    item.style.pointerEvents = 'none';

    if (opts.bodyDraggingClass) document.body.classList.add(opts.bodyDraggingClass);

    function onMove(ev) {
      item.style.left = (ev.clientX - offsetX) + 'px';
      item.style.top = (ev.clientY - offsetY) + 'px';

      // Find which container we're over
      for (var ci = 0; ci < containers.length; ci++) {
        var cont = containers[ci];
        var children = cont.querySelectorAll(opts.itemSelector);
        for (var i = 0; i < children.length; i++) {
          var child = children[i];
          if (child === item || child === placeholder) continue;
          var cr = child.getBoundingClientRect();
          var midY = cr.top + cr.height / 2;
          if (ev.clientY < midY) {
            cont.insertBefore(placeholder, child);
            return;
          }
        }
        // After all children
        var contRect = cont.getBoundingClientRect();
        if (ev.clientX >= contRect.left && ev.clientX <= contRect.right &&
            ev.clientY >= contRect.top && ev.clientY <= contRect.bottom) {
          cont.appendChild(placeholder);
        }
      }
    }

    function onUp() {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);

      // Move item to placeholder position
      if (placeholder && placeholder.parentNode) {
        placeholder.parentNode.insertBefore(item, placeholder);
        placeholder.remove();
      }

      item.classList.remove(opts.draggingClass || 'ib-dragging');
      item.style.position = '';
      item.style.zIndex = '';
      item.style.width = '';
      item.style.left = '';
      item.style.top = '';
      item.style.pointerEvents = '';

      if (opts.bodyDraggingClass) document.body.classList.remove(opts.bodyDraggingClass);

      placeholder = null;
      dragItem = null;

      if (opts.onReorder) opts.onReorder();
    }

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
}

// =========================================================================
// Relation Autocomplete Component
// =========================================================================

function ibInitRelationAutocomplete() {
  document.querySelectorAll('.ib-relation-autocomplete').forEach(function (wrapper) {
    var hiddenInput  = wrapper.querySelector('input[type="hidden"]');
    var displayInput = wrapper.querySelector('.ib-relation-autocomplete__input');
    var list         = wrapper.querySelector('.ib-relation-autocomplete__list');
    var clearBtn     = wrapper.querySelector('.ib-relation-autocomplete__clear');
    if (!hiddenInput || !displayInput || !list) return;

    var table    = wrapper.dataset.raTable || '';
    var key      = wrapper.dataset.raKey || 'id';
    var column   = wrapper.dataset.raColumn || '';
    var limit    = parseInt(wrapper.dataset.raLimit, 10) || 10;
    var minChars = parseInt(wrapper.dataset.raMinChars, 10) || 2;
    var where    = wrapper.dataset.raWhere || '';

    var debounceTimer = null;
    var activeIndex   = -1;
    var results       = [];

    function showList() {
      list.hidden = false;
      displayInput.setAttribute('aria-expanded', 'true');
    }

    function hideList() {
      list.hidden = true;
      displayInput.setAttribute('aria-expanded', 'false');
      activeIndex = -1;
    }

    function clearList() {
      while (list.firstChild) { list.removeChild(list.firstChild); }
    }

    function renderResults(data) {
      results = data;
      clearList();
      if (!data.length) {
        var li = document.createElement('li');
        li.className = 'ib-relation-autocomplete__item ib-relation-autocomplete__item--empty';
        li.textContent = 'No results';
        list.appendChild(li);
        showList();
        return;
      }
      data.forEach(function (item, idx) {
        var li = document.createElement('li');
        li.className = 'ib-relation-autocomplete__item';
        li.setAttribute('role', 'option');
        li.dataset.id = item.id;
        li.dataset.idx = idx;
        li.textContent = item.label;
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          selectItem(idx);
        });
        list.appendChild(li);
      });
      activeIndex = -1;
      showList();
    }

    function selectItem(idx) {
      if (!results[idx]) return;
      hiddenInput.value  = results[idx].id;
      displayInput.value = results[idx].label;
      if (clearBtn) clearBtn.style.display = 'inline';
      hideList();
      hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function highlightItem(idx) {
      var items = list.querySelectorAll('.ib-relation-autocomplete__item[role="option"]');
      items.forEach(function (li) { li.classList.remove('ib-relation-autocomplete__item--active'); });
      if (idx >= 0 && idx < items.length) {
        items[idx].classList.add('ib-relation-autocomplete__item--active');
        items[idx].scrollIntoView({ block: 'nearest' });
      }
    }

    function doSearch(q) {
      var url = window.location.pathname + '?_=relation_autocomplete'
        + '&q=' + encodeURIComponent(q)
        + '&table=' + encodeURIComponent(table)
        + '&column=' + encodeURIComponent(column)
        + '&key=' + encodeURIComponent(key)
        + '&limit=' + limit;
      if (where) url += '&where=' + encodeURIComponent(where);

      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (Array.isArray(data)) {
            renderResults(data);
          }
        })
        .catch(function () {
          hideList();
        });
    }

    displayInput.addEventListener('input', function () {
      var q = displayInput.value.trim();
      hiddenInput.value = '';
      if (clearBtn) clearBtn.style.display = 'none';

      if (q.length < minChars) {
        hideList();
        return;
      }
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () { doSearch(q); }, 250);
    });

    displayInput.addEventListener('keydown', function (e) {
      var items = list.querySelectorAll('.ib-relation-autocomplete__item[role="option"]');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (list.hidden) return;
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        highlightItem(activeIndex);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (list.hidden) return;
        activeIndex = Math.max(activeIndex - 1, 0);
        highlightItem(activeIndex);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (!list.hidden && activeIndex >= 0) {
          selectItem(activeIndex);
        }
      } else if (e.key === 'Escape') {
        hideList();
      }
    });

    displayInput.addEventListener('blur', function () {
      setTimeout(function () { hideList(); }, 200);
    });

    displayInput.addEventListener('focus', function () {
      if (displayInput.value.trim().length >= minChars && results.length > 0) {
        showList();
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        hiddenInput.value = '';
        displayInput.value = '';
        clearBtn.style.display = 'none';
        displayInput.focus();
        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  ibInitFormSaveShortcut();
  ibInitFormValidation();
  ibInitLiveInvoiceCalc();
  ibInitFormLoading();
  ibInitTagsInput();
  ibInitRelationAutocomplete();
  ibInitNativeBridge();
  ibInitMobileKeyboardScrollFix();
});

/* ==========================================================================
   Mobile keyboard scroll-reset
   --------------------------------------------------------------------------
   iOS Safari/WKWebView scrolls window.scrollY > 0 to bring a focused input
   above the keyboard, and does NOT auto-reset on dismiss. Worse, fixed
   elements can become "unmoored" and remain shifted when the keyboard
   hides — leaving an empty gap above the fixed header. Restore everything
   once the visual viewport returns to its pre-keyboard height.
   ========================================================================== */
function ibInitMobileKeyboardScrollFix() {
  var isMobileUA = /Mobi|Android|iPhone|iPad|iPod|IntrabaseMobile/i.test(navigator.userAgent);
  var isSmallVp = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
  if (!isMobileUA && !isSmallVp) return;

  function resetScroll() {
    // Reset every plausible scroll container — different iOS versions/modes
    // use different scrolling elements.
    try { window.scrollTo(0, 0); } catch (e) {}
    if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
  }

  function forceLayoutRecalc() {
    // iOS WebKit can leave position:fixed elements offset after the keyboard
    // hides. Toggling a no-op transform forces a paint/layout recalc that
    // re-anchors fixed nodes to the viewport.
    var el = document.documentElement;
    el.style.transform = 'translateZ(0)';
    // Force reflow read before clearing.
    void el.offsetHeight;
    requestAnimationFrame(function () { el.style.transform = ''; });
  }

  function fullRestore() {
    resetScroll();
    forceLayoutRecalc();
  }

  // Spread several attempts across the keyboard-close animation (~250-400ms
  // on iOS, slower on Android). Empirically more reliable than a single fire.
  function scheduleRestores() {
    [0, 50, 150, 300, 600].forEach(function (t) { setTimeout(fullRestore, t); });
  }

  var kbOpenState = false;

  // visualViewport is the canonical signal. Use the ratio against
  // window.innerHeight so it works regardless of what initial size happened
  // to be captured (avoids the stale-baseline trap with a fixed threshold).
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', function () {
      var vh = window.visualViewport.height;
      var iw = window.innerHeight || vh;
      var isOpen = (vh / iw) < 0.85;
      if (kbOpenState && !isOpen) scheduleRestores();
      kbOpenState = isOpen;
    });

    // Some iOS builds report the keyboard via visualViewport.offsetTop > 0
    // even before resize fires. Treat any non-zero offset as a signal that
    // the layout has been shifted — restore on its scroll event too.
    window.visualViewport.addEventListener('scroll', function () {
      if (window.visualViewport.offsetTop > 0) {
        // Don't immediately reset (keyboard might still be opening), but
        // mark so the next "back to top" resize triggers the restore.
        kbOpenState = true;
      }
    });
  }

  // Defense in depth: focusout on inputs/contenteditable. Less reliable when
  // the keyboard is dismissed via the system "Done" affordance.
  document.addEventListener('focusout', function (e) {
    var t = e.target;
    if (!t || !t.matches) return;
    if (!t.matches('input, textarea, select, [contenteditable], [contenteditable="true"]')) return;
    scheduleRestores();
  }, true);

  // Final safety net: any time the window resizes back to a "no keyboard"
  // height (e.g. orientation change recovery), restore.
  window.addEventListener('resize', function () {
    if (window.visualViewport) return; // already handled above
    setTimeout(fullRestore, 50);
  });
}

/* ==========================================================================
   iOS / iPadOS detection
   --------------------------------------------------------------------------
   Every "don't navigate the webview to a file URL" workaround in the app
   (mail attachments, chat files, drive downloads) hangs off this test, so it
   has to recognise iPads too — and a /iPad/ user-agent match does NOT.

   Since iPadOS 13, Safari on iPad requests desktop sites by default and its
   UA reads "Macintosh; Intel Mac OS X 10_15_7 ... Safari", with no mention of
   iPad; WKWebView inside the native app reports the same when it runs in
   desktop content mode. Those iPads still behave like iOS: <a download> is
   ignored and the webview navigates to the file instead, which in the native
   shell (no address bar, no back button) drops the user on the black system
   "Open in…" screen with no way back into Intrabase.

   maxTouchPoints is the discriminator: iPads report 5, real Macs report 0.
   ========================================================================== */

function ibIsIOS() {
  var ua = navigator.userAgent || '';
  if (/iPad|iPhone|iPod/.test(ua)) return true;
  var isMacLike = /Macintosh|Mac OS X/.test(ua) || /^Mac/.test(navigator.platform || '');
  return isMacLike && (navigator.maxTouchPoints || 0) > 1;
}
window.ibIsIOS = ibIsIOS;

/* ==========================================================================
   Native App Bridge (Capacitor Mobile / Tauri Desktop)
   ========================================================================== */

var ibNative = {
  /** True if running inside Capacitor mobile app */
  isMobile: !!(window.IntrabaseMobile && window.IntrabaseMobile.isMobile),
  /** True if running inside Tauri desktop app */
  isDesktop: !!window.__TAURI_INTERNALS__,
  /** True if running in any native shell */
  isNative: !!(window.IntrabaseMobile && window.IntrabaseMobile.isMobile) || !!window.__TAURI_INTERNALS__,
};

/**
 * Open a URL: external links open in system browser, internal stay in webview.
 */
ibNative.openUrl = function(url) {
  if (!url) return;
  try {
    var host = new URL(url, location.origin).hostname;
    var internal = [location.hostname];
    var isInternal = internal.some(function(d) { return host === d || host.endsWith('.' + d); });
    if (isInternal) { location.href = url; return; }
  } catch(e) { location.href = url; return; }
  if (ibNative.isMobile && window.IntrabaseMobile.openExternal) {
    window.IntrabaseMobile.openExternal(url);
  } else {
    window.open(url, '_blank');
  }
};

/**
 * Copy text to clipboard (native or web fallback).
 */
ibNative.copyToClipboard = function(text) {
  if (ibNative.isMobile && window.IntrabaseMobile.copyToClipboard) {
    return window.IntrabaseMobile.copyToClipboard(text);
  }
  if (navigator.clipboard) {
    return navigator.clipboard.writeText(text);
  }
};

/**
 * Share content via native share sheet or Web Share API.
 */
ibNative.share = function(opts) {
  if (ibNative.isMobile && window.IntrabaseMobile.share) {
    return window.IntrabaseMobile.share(opts);
  }
  if (navigator.share) {
    return navigator.share(opts);
  }
};

/**
 * Haptic feedback (mobile only).
 * @param {string} style - 'light', 'medium', 'heavy'
 */
ibNative.haptic = function(style) {
  if (ibNative.isMobile && window.IntrabaseMobile.hapticImpact) {
    window.IntrabaseMobile.hapticImpact(style || 'medium');
  } else if (navigator.vibrate) {
    navigator.vibrate(style === 'heavy' ? 50 : style === 'light' ? 10 : 30);
  }
};

/**
 * Haptic notification feedback (mobile only).
 * @param {string} type - 'success', 'warning', 'error'
 */
ibNative.hapticNotify = function(type) {
  if (ibNative.isMobile && window.IntrabaseMobile.hapticNotification) {
    window.IntrabaseMobile.hapticNotification(type || 'success');
  }
};

/**
 * Take a photo using device camera (mobile only).
 * Returns promise with {base64, format}.
 */
ibNative.takePhoto = function() {
  if (ibNative.isMobile && window.IntrabaseMobile.takePhoto) {
    return window.IntrabaseMobile.takePhoto();
  }
  return Promise.reject('Camera not available');
};

/**
 * Pick a photo from gallery (mobile only).
 * Returns promise with {base64, format}.
 */
ibNative.pickPhoto = function() {
  if (ibNative.isMobile && window.IntrabaseMobile.pickPhoto) {
    return window.IntrabaseMobile.pickPhoto();
  }
  return Promise.reject('Photo picker not available');
};

/**
 * Get current GPS position.
 * Returns promise with {lat, lng, accuracy}.
 */
ibNative.getPosition = function() {
  if (ibNative.isMobile && window.IntrabaseMobile.getCurrentPosition) {
    return window.IntrabaseMobile.getCurrentPosition();
  }
  if (navigator.geolocation) {
    return new Promise(function(resolve, reject) {
      navigator.geolocation.getCurrentPosition(function(pos) {
        resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy });
      }, reject);
    });
  }
  return Promise.reject('Geolocation not available');
};

/**
 * Get network status.
 */
ibNative.getNetworkStatus = function() {
  if (ibNative.isMobile && window.IntrabaseMobile.getNetworkStatus) {
    return window.IntrabaseMobile.getNetworkStatus();
  }
  return Promise.resolve({ connected: navigator.onLine, type: 'unknown' });
};

/**
 * Register for push notifications (mobile only).
 */
ibNative.registerPush = function() {
  if (ibNative.isMobile && window.IntrabaseMobile.registerPush) {
    return window.IntrabaseMobile.registerPush();
  }
  return Promise.reject('Push not available');
};

/**
 * Get device info (mobile only).
 */
ibNative.getDeviceInfo = function() {
  if (ibNative.isMobile && window.IntrabaseMobile.getDeviceInfo) {
    return window.IntrabaseMobile.getDeviceInfo();
  }
  return Promise.resolve({ platform: 'web' });
};

// ── Desktop-only (Tauri) functions ──────────────────────────────────────

/**
 * Send a native desktop notification (Tauri only).
 */
ibNative.desktopNotify = function(title, body) {
  // Tauri native notifications
  if (ibNative.isDesktop && window.__TAURI_INTERNALS__) {
    return window.__TAURI_INTERNALS__.invoke('send_notification', { title: title, body: body });
  }
  // Web / PWA fallback via Notification API
  if (typeof Notification !== 'undefined') {
    if (Notification.permission === 'granted') {
      new Notification(title, { body: body, icon: '/assets/img/logo.svg' });
      return Promise.resolve();
    }
    if (Notification.permission !== 'denied') {
      return Notification.requestPermission().then(function(perm) {
        if (perm === 'granted') {
          new Notification(title, { body: body, icon: '/assets/img/logo.svg' });
        }
      });
    }
  }
  return Promise.resolve();
};

/**
 * Update dock/tray badge count (Tauri only).
 */
ibNative.setBadgeCount = function(count) {
  if (ibNative.isDesktop && window.__TAURI_INTERNALS__) {
    return window.__TAURI_INTERNALS__.invoke('set_badge_count', { count: count });
  }
  return Promise.resolve();
};

/**
 * Show native "Save As" dialog (Tauri only).
 * Returns the selected file path or null.
 */
ibNative.saveFileDialog = function(filename) {
  if (ibNative.isDesktop && window.__TAURI_INTERNALS__) {
    return window.__TAURI_INTERNALS__.invoke('save_file_dialog', { filename: filename });
  }
  return Promise.resolve(null);
};

/**
 * Save/open a file on iOS without ever navigating the webview away.
 *
 * Order matters: the native shell's own bridge first (it writes the file to
 * Documents and presents the system share sheet on top of the app, so the app
 * is still there when the sheet is dismissed), then the Web Share API for
 * Safari and the home-screen PWA, and finally a same-origin blob: anchor,
 * which WebKit saves instead of treating as a page to open.
 *
 * Returns a promise resolving true when the file reached the OS.
 */
function ibSaveFileNatively(url, filename) {
  var name = filename || 'fitxer';

  if (ibNative.isMobile && window.IntrabaseMobile && window.IntrabaseMobile.openFileWithOS) {
    return Promise.resolve(window.IntrabaseMobile.openFileWithOS(url, name))
      .then(function() { return true; })
      .catch(function() { return ibSaveFileViaBlob(url, name); });
  }
  return ibSaveFileViaBlob(url, name);
}

function ibSaveFileViaBlob(url, name) {
  return fetch(url, { credentials: 'same-origin' })
    .then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.blob();
    })
    .then(function(blob) { return ibDeliverBlob(blob, name); })
    .catch(function() { return false; });
}

/**
 * Hand an already-loaded Blob to the user (share sheet, or a blob: download).
 * Callers that build the bytes themselves — zipped folders, generated exports —
 * use this so they don't have to re-fetch anything.
 */
function ibDeliverBlob(blob, name) {
  return Promise.resolve()
    .then(function() {
      var type = blob.type || 'application/octet-stream';
      var file = new File([blob], name, { type: type });
      // Share sheet: "Desa a Fitxers", AirDrop, "Obre amb…" — all of them
      // present over Intrabase and return to it on cancel.
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        return navigator.share({ files: [file] })
          .then(function() { return true; })
          .catch(function() { return true; }); // user cancelled: nothing to fall back to
      }
      // No Web Share (or no transient activation left after the fetch): a
      // blob: URL is same-origin and carries its own filename, so WebKit
      // downloads it rather than navigating to the remote file.
      var blobUrl = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = blobUrl;
      a.download = name;
      a.rel = 'noopener';
      a.style.display = 'none';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function() { URL.revokeObjectURL(blobUrl); }, 30000);
      return true;
    })
    .catch(function() { return false; });
}

window.ibSaveFileNatively = ibSaveFileNatively;
window.ibDeliverBlob = ibDeliverBlob;

/**
 * Download a URL to disk via native Save As dialog (Tauri desktop).
 * Fetches the file, shows Save As, writes to the selected path.
 * Returns true if saved, false if cancelled or error.
 */
ibNative.downloadFile = function(url, filename) {
  // iOS / iPadOS (Safari, home-screen PWA and the native shell alike) ignores
  // the download attribute: the anchor below would NAVIGATE to the file, and
  // for anything WebKit can't render inline the user lands on the black system
  // "Open in…" sheet — inside the native app that is a dead end, because there
  // is no address bar, no back button and the SPA is gone. Hand the bytes to
  // the OS instead, which keeps Intrabase on screen underneath.
  if (ibIsIOS()) {
    return ibSaveFileNatively(url, filename);
  }
  if (!ibNative.isDesktop || !window.__TAURI_INTERNALS__) {
    // Web fallback: trigger download via hidden <a>
    var a = document.createElement('a');
    a.href = url;
    a.download = filename || '';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    return Promise.resolve(true);
  }
  // Tauri: fetch blob, show Save As dialog, write via IPC
  return fetch(url, { credentials: 'same-origin' })
    .then(function(r) { return r.arrayBuffer(); })
    .then(function(buf) {
      return window.__TAURI_INTERNALS__.invoke('save_file_dialog', { filename: filename || 'fitxer' })
        .then(function(path) {
          if (!path) return false; // user cancelled
          var bytes = Array.from(new Uint8Array(buf));
          return window.__TAURI_INTERNALS__.invoke('write_file_bytes', { path: path, bytes: bytes })
            .then(function() { return true; });
        });
    });
};

/**
 * Open a URL with the appropriate OS application (Tauri desktop).
 * Downloads to a temp file and opens it with the default app.
 */
ibNative.openFileWithOS = function(url, filename) {
  // Same trap as downloadFile: window.open() in a WKWebView loads the file in
  // place (Capacitor gives target=_blank no window of its own), so on iOS this
  // goes through the OS instead of the webview.
  if (ibIsIOS()) {
    return ibSaveFileNatively(url, filename);
  }
  if (!ibNative.isDesktop || !window.__TAURI_INTERNALS__) {
    window.open(url, '_blank');
    return Promise.resolve();
  }
  return fetch(url, { credentials: 'same-origin' })
    .then(function(r) { return r.arrayBuffer(); })
    .then(function(buf) {
      var bytes = Array.from(new Uint8Array(buf));
      return window.__TAURI_INTERNALS__.invoke('open_file_with_os', {
        filename: filename || 'fitxer',
        bytes: bytes
      });
    });
};

/**
 * Convert base64 photo to a File object for upload.
 */
ibNative.base64ToFile = function(base64, format) {
  var mime = format === 'png' ? 'image/png' : 'image/jpeg';
  var ext = format === 'png' ? 'png' : 'jpg';
  var binary = atob(base64);
  var arr = new Uint8Array(binary.length);
  for (var i = 0; i < binary.length; i++) arr[i] = binary.charCodeAt(i);
  return new File([arr], 'photo_' + Date.now() + '.' + ext, { type: mime });
};

// =========================================================================
// Quick Actions – sidebar context menu + header badges
// =========================================================================

function ibInitQuickActions() {
  var container = document.getElementById('ib-header-qa');
  var contextMenu = null;

  // Create the context menu element (shared)
  function getContextMenu() {
    if (contextMenu) return contextMenu;
    contextMenu = document.createElement('div');
    contextMenu.className = 'ib-qa-context';
    contextMenu.id = 'ib-qa-context';
    document.body.appendChild(contextMenu);
    return contextMenu;
  }

  function hideContextMenu() {
    var m = document.getElementById('ib-qa-context');
    if (m) m.classList.remove('show');
  }

  // Close on click outside
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.ib-qa-context')) hideContextMenu();
  });
  document.addEventListener('contextmenu', function (e) {
    if (!e.target.closest('.ib-sidebar__link') && !e.target.closest('.ib-header-qa__item')) {
      hideContextMenu();
    }
  });

  // Check if a URL is already in quick actions
  function isQuickAction(url) {
    if (!container) return false;
    var items = container.querySelectorAll('.ib-header-qa__item');
    for (var i = 0; i < items.length; i++) {
      if (items[i].getAttribute('href') === url) return true;
    }
    return false;
  }

  // Create a quick action DOM element safely (no innerHTML)
  function createQaElement(label, icon, url, id) {
    var a = document.createElement('a');
    a.className = 'ib-header-qa__item';
    a.href = url;
    a.title = label;
    a.setAttribute('data-qa-id', id);
    var i = document.createElement('i');
    i.className = icon;
    i.setAttribute('aria-hidden', 'true');
    var span = document.createElement('span');
    span.className = 'ib-header-qa__label';
    span.textContent = label;
    a.appendChild(i);
    a.appendChild(span);
    return a;
  }

  // Create a context menu button safely (no innerHTML)
  function createMenuButton(iconClass, text, action) {
    var btn = document.createElement('button');
    btn.className = 'ib-qa-context__item';
    btn.setAttribute('data-action', action);
    var i = document.createElement('i');
    i.className = iconClass;
    btn.appendChild(i);
    btn.appendChild(document.createTextNode(' ' + text));
    return btn;
  }

  // Add quick action via API
  function addQuickAction(label, icon, url) {
    fetch('/user/quick-actions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ label: label, icon: icon, url: url })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok && container) {
        var el = createQaElement(label, icon, url, data.id);
        container.appendChild(el);
        bindQaContextMenu(el);
      }
    });
  }

  // Remove quick action via API
  function removeQuickAction(id, url) {
    var body = id ? { id: id } : { url: url };
    fetch('/user/quick-actions', {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok && container) {
        var sel = id ? '[data-qa-id="' + id + '"]' : '[href="' + CSS.escape(url) + '"]';
        var el = container.querySelector(sel);
        if (el) el.remove();
      }
    });
  }

  // Context menu on sidebar links (right-click to add/remove from quick actions)
  var sidebarLinks = document.querySelectorAll('.ib-sidebar__link--sub');
  sidebarLinks.forEach(function (link) {
    link.addEventListener('contextmenu', function (e) {
      e.preventDefault();
      hideContextMenu();

      var url = link.getAttribute('href');
      var labelEl = link.querySelector('.ib-sidebar__label');
      var iconEl = link.querySelector('.ib-sidebar__icon');
      var label = labelEl ? labelEl.textContent.trim() : 'Shortcut';
      var icon = iconEl ? iconEl.className.replace(/\s*ib-sidebar__icon\S*/g, '').trim() : 'fa-solid fa-link';

      var menu = getContextMenu();
      menu.textContent = ''; // clear previous content safely
      var alreadyAdded = isQuickAction(url);

      var btn;
      if (alreadyAdded) {
        btn = createMenuButton('fa-solid fa-xmark', "Treure d'accions ràpides", 'remove');
      } else {
        btn = createMenuButton('fa-solid fa-bolt', "Afegir a accions ràpides", 'add');
      }
      menu.appendChild(btn);

      // Position
      var x = e.clientX, y = e.clientY;
      menu.style.left = Math.min(x, window.innerWidth - 220) + 'px';
      menu.style.top = Math.min(y, window.innerHeight - 60) + 'px';
      menu.classList.add('show');

      btn.addEventListener('click', function () {
        var action = this.getAttribute('data-action');
        if (action === 'add') {
          addQuickAction(label, icon, url);
        } else {
          removeQuickAction(null, url);
        }
        hideContextMenu();
      }, { once: true });
    });
  });

  // Context menu on header quick action badges (right-click to remove)
  function bindQaContextMenu(item) {
    item.addEventListener('contextmenu', function (e) {
      e.preventDefault();
      hideContextMenu();

      var qaId = item.getAttribute('data-qa-id');
      var qaUrl = item.getAttribute('href');

      var menu = getContextMenu();
      menu.textContent = ''; // clear safely
      var btn = createMenuButton('fa-solid fa-xmark', "Treure d'accions ràpides", 'remove');
      menu.appendChild(btn);

      var x = e.clientX, y = e.clientY;
      menu.style.left = Math.min(x, window.innerWidth - 220) + 'px';
      menu.style.top = Math.min(y, window.innerHeight - 60) + 'px';
      menu.classList.add('show');

      btn.addEventListener('click', function () {
        removeQuickAction(qaId ? parseInt(qaId) : null, qaUrl);
        hideContextMenu();
      }, { once: true });
    });
  }

  // Bind existing header quick action items
  if (container) {
    container.querySelectorAll('.ib-header-qa__item').forEach(bindQaContextMenu);
  }

  // Mobile trigger button — shows quick actions in a context menu
  var trigger = document.getElementById('ib-qa-trigger');
  if (trigger && container) {
    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      hideContextMenu();

      var items = container.querySelectorAll('.ib-header-qa__item');
      if (items.length === 0) return;

      var menu = getContextMenu();
      menu.textContent = '';

      // Header
      var header = document.createElement('div');
      header.style.cssText = 'padding:10px 14px 6px;font-size:0.6875rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;opacity:.45';
      header.textContent = trigger.getAttribute('aria-label') || 'Quick Actions';
      menu.appendChild(header);

      items.forEach(function (item) {
        var url = item.getAttribute('href');
        var label = item.getAttribute('title') || item.textContent.trim();
        var iconEl = item.querySelector('i');
        var iconClass = iconEl ? iconEl.className : 'fa-solid fa-link';

        var btn = document.createElement('a');
        btn.className = 'ib-qa-context__item';
        btn.href = url;
        var i = document.createElement('i');
        i.className = iconClass;
        var span = document.createElement('span');
        span.textContent = label;
        btn.appendChild(i);
        btn.appendChild(span);
        menu.appendChild(btn);
      });

      // Position below the trigger
      var rect = trigger.getBoundingClientRect();
      var menuW = 220;
      var left = Math.min(rect.left, window.innerWidth - menuW - 8);
      menu.style.left = Math.max(8, left) + 'px';
      menu.style.top = (rect.bottom + 6) + 'px';
      menu.classList.add('show');
    });
  }
}

/**
 * Initialize native bridge integrations.
 */
function ibInitNativeBridge() {
  // Request web notification permission on the FIRST user gesture: browsers
  // only honor the prompt inside a short user-generated event handler and
  // log a console warning if it is requested straight from page load.
  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    var ibAskNotifPerm = function () {
      document.removeEventListener('click', ibAskNotifPerm, true);
      document.removeEventListener('keydown', ibAskNotifPerm, true);
      if (Notification.permission === 'default') {
        Notification.requestPermission();
      }
    };
    document.addEventListener('click', ibAskNotifPerm, true);
    document.addEventListener('keydown', ibAskNotifPerm, true);
  }

  if (!ibNative.isNative) return;

  // 1. Intercept external links globally
  document.addEventListener('click', function(e) {
    var anchor = e.target.closest('a[href]');
    if (!anchor) return;
    var href = anchor.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    if (/^https?:\/\//.test(href)) {
      try {
        var host = new URL(href).hostname;
        var internal = [location.hostname];
        var isInternal = internal.some(function(d) { return host === d || host.endsWith('.' + d); });
        if (!isInternal) {
          e.preventDefault();
          ibNative.openUrl(href);
        }
      } catch(ex) { /* let browser handle */ }
    }
  }, true);

  // 2. Register push notifications after login (mobile)
  if (ibNative.isMobile) {
    ibNative.registerPush().then(function(result) {
      if (result && result.token) {
        fetch('/api/push-token', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ibGetCsrfToken() },
          body: JSON.stringify({ token: result.token, platform: 'capacitor' })
        }).catch(function() {});
      }
    }).catch(function() {});
  }

  // 3. Listen for network changes — show/hide offline banner
  if (ibNative.isMobile && window.IntrabaseMobile.onNetworkChange) {
    window.IntrabaseMobile.onNetworkChange(function(status) {
      var banner = document.getElementById('ib-offline-banner');
      if (!status.connected) {
        if (!banner) {
          banner = document.createElement('div');
          banner.id = 'ib-offline-banner';
          banner.className = 'ib-offline-banner';
          banner.textContent = 'Sense connexió';
          document.body.appendChild(banner);
        }
        banner.style.display = '';
      } else if (banner) {
        banner.style.display = 'none';
      }
    });
  }

  // 4. Resume from background: notify modules
  if (ibNative.isMobile) {
    window.IntrabaseMobile._onResume = function() {
      document.dispatchEvent(new CustomEvent('ib:app-resume'));
    };
  }

  // 5. Session heartbeat: detect expired/changed sessions and redirect to login
  (function() {
    var checkInterval = 60000; // check every 60 seconds
    if (ibIsPublicAuthPage()) return;

    function checkSession() {
      fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.text(); })
        .then(function(body) {
          if (body.indexOf('__ibUser=null') !== -1) {
            ibSessionExpired();
          }
        })
        .catch(function() {}); // network error, skip
    }

    setInterval(checkSession, checkInterval);

    // Also check on visibility change (tab becomes active again)
    document.addEventListener('visibilitychange', function() {
      if (!document.hidden) checkSession();
    });
  })();
}


/* ==========================================================================
   IbModal — application dialogs replacing the browser's native ones
   ==========================================================================
   confirm() / alert() / prompt() are the browser's, not the app's: they wear
   the operating system's chrome, cannot be translated (the buttons follow the
   BROWSER's language, not ib_users.locale), cannot be styled or themed, and on
   mobile they are drawn as a system sheet over everything. Since 22/08/2026 the
   app's own dialog is used instead (user request, example: "delete label").

   The classes are ib-dlg*, not the ib-modal* one might expect: .ib-modal and
   .ib-modal-backdrop are already taken — by this very stylesheet, by
   calendar.css and by the dashboard widgets — and reusing them silently
   restyled the event-reminder modal and every calendar dialog.

   Two ad-hoc versions of this already existed — one in drive.js (callback,
   delete-only) and one in mail.js (Promise, no title) — and mail.js's is a bare
   `function ibConfirm`, i.e. a global. Hence the namespace: IbModal never
   collides with them, and they can migrate to it one at a time.

     await IbModal.confirm('Sure?')                       → boolean
     await IbModal.confirm({title, message, okLabel, danger, icon})
     await IbModal.alert({title, message})                → undefined
     await IbModal.prompt({title, message, value})        → string | null
     await IbModal.choice({title, message, options: [{value, label}], value, okLabel})
                                                          → chosen value | null
       (a radio list, Google-Calendar style: "this event / this and following
        / all events"; `value` preselects one, the first otherwise)

   Escape and the backdrop cancel, Enter confirms, focus is trapped inside the
   dialog and handed back to whatever had it when the dialog closes. Dialogs
   stack: opening one from another is allowed, the top one keeps the keyboard.
   ========================================================================== */
(function () {
  'use strict';

  function T(k, fallback) {
    var v = (typeof window.t === 'function') ? window.t(k) : k;
    // t() echoes the key back when the constant is missing from the bundle:
    // that would put "cancel" on a button, so fall back to real words.
    return (!v || v === k) ? fallback : v;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var openCount = 0;

  function open(opts) {
    opts = (typeof opts === 'string') ? { message: opts } : (opts || {});
    var kind = opts.kind || 'confirm';

    return new Promise(function (resolve) {
      var lastFocus = document.activeElement;

      var backdrop = document.createElement('div');
      backdrop.className = 'ib-dlg-backdrop';
      backdrop.style.zIndex = String(100000 + openCount * 2);

      var dialog = document.createElement('div');
      dialog.className = 'ib-dlg' + (opts.danger ? ' ib-dlg--danger' : '');
      dialog.setAttribute('role', kind === 'alert' ? 'alertdialog' : 'dialog');
      dialog.setAttribute('aria-modal', 'true');

      var html = '';
      // Creueta de tancar a dalt a la dreta (opcional). Cancel·la com el
      // fons o l'Escape: qualsevol data-ib-dlg que no sigui "ok".
      if (opts.closeX) {
        html += '<button type="button" class="ib-dlg__x" data-ib-dlg="cancel"'
          + ' aria-label="' + esc(T('close', 'Tancar')) + '"'
          + ' title="' + esc(T('close', 'Tancar')) + '">'
          + '<i class="fa-solid fa-xmark" aria-hidden="true"></i></button>';
      }
      if (opts.title) {
        html += '<div class="ib-dlg__title" id="ib-dlg-title">'
          + (opts.icon ? '<i class="' + esc(opts.icon) + '"></i> ' : '')
          + esc(opts.title) + '</div>';
      }
      // The message keeps its line breaks (white-space: pre-line in the CSS):
      // several call sites pass two sentences separated by \n.
      html += '<div class="ib-dlg__body">' + esc(opts.message || '') + '</div>';
      if (kind === 'prompt') {
        html += '<input type="text" class="ib-input ib-dlg__input" id="ib-dlg-input"'
          + ' value="' + esc(opts.value || '') + '"'
          + ' placeholder="' + esc(opts.placeholder || '') + '">';
      }
      if (kind === 'choice') {
        html += '<div class="ib-dlg__choices" role="radiogroup">';
        (opts.options || []).forEach(function (o, i) {
          var checked = (opts.value != null) ? String(o.value) === String(opts.value) : i === 0;
          html += '<label class="ib-dlg__choice">'
            + '<input type="radio" name="ib-dlg-choice" value="' + esc(o.value) + '"' + (checked ? ' checked' : '') + '>'
            + '<span>' + esc(o.label) + '</span></label>';
        });
        html += '</div>';
      }
      // `hideActions`: el cos que passa qui crida ja porta els seus botons
      // (el formulari de filtres, per exemple) i no cal duplicar-los.
      if (!opts.hideActions) {
        html += '<div class="ib-dlg__actions">';
        if (kind !== 'alert') {
          html += '<button type="button" class="ib-btn ib-btn--secondary" data-ib-dlg="cancel">'
            + esc(opts.cancelLabel || T('cancel', 'Cancel·lar')) + '</button>';
        }
        html += '<button type="button" class="ib-btn ' + (opts.danger ? 'ib-btn--danger' : 'ib-btn--primary') + '" data-ib-dlg="ok">'
          + esc(opts.okLabel || (kind === 'alert' ? T('close', 'Tancar') : T('yes', 'Sí'))) + '</button>';
        html += '</div>';
      }
      dialog.innerHTML = html;
      // An affordance that belongs to the MESSAGE (a copy icon at the end of a
      // list, say), not a third answer: the caller builds it and wires its own
      // clicks, we only place it between the body and the buttons. It must go
      // in after innerHTML, which would otherwise put it below the actions.
      if (opts.bodyExtra && opts.bodyExtra.nodeType === 1) {
        dialog.insertBefore(opts.bodyExtra, dialog.querySelector('.ib-dlg__actions'));
      }
      if (opts.title) dialog.setAttribute('aria-labelledby', 'ib-dlg-title');

      backdrop.appendChild(dialog);
      document.body.appendChild(backdrop);
      openCount++;
      document.body.classList.add('ib-dlg-open');

      var input = dialog.querySelector('#ib-dlg-input');
      var okBtn = dialog.querySelector('[data-ib-dlg="ok"]');
      // The input first when there is one, so typing works straight away;
      // otherwise the confirming button, which is what Enter would do anyway.
      // Amb `hideActions` no hi ha cap dels dos: llavors, el primer element
      // enfocable del cos (abans peta va aqui amb un null).
      var firstFocus = input || dialog.querySelector('input[type="radio"]:checked') || okBtn
        || dialog.querySelector('button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (firstFocus && firstFocus.focus) {
        firstFocus.focus();
      } else {
        dialog.setAttribute('tabindex', '-1');
        dialog.focus();
      }
      if (input) input.select();

      function close(value) {
        document.removeEventListener('keydown', onKey, true);
        backdrop.remove();
        openCount = Math.max(0, openCount - 1);
        if (!openCount) document.body.classList.remove('ib-dlg-open');
        // Give the keyboard back to whatever opened the dialog.
        if (lastFocus && typeof lastFocus.focus === 'function' && document.contains(lastFocus)) {
          lastFocus.focus();
        }
        resolve(value);
      }

      function cancelValue() {
        return kind === 'confirm' ? false : ((kind === 'prompt' || kind === 'choice') ? null : undefined);
      }

      // What "OK" resolves with: the text for a prompt, the picked radio for
      // a choice, true for a confirm, nothing for an alert.
      function okValue() {
        if (kind === 'prompt') return input ? input.value : '';
        if (kind === 'choice') {
          var picked = dialog.querySelector('input[name="ib-dlg-choice"]:checked');
          return picked ? picked.value : null;
        }
        return kind === 'alert' ? undefined : true;
      }

      function onKey(e) {
        // Only the topmost dialog answers the keyboard. (:last-of-type would
        // match the last DIV of the body, not the last backdrop.)
        var stack = document.querySelectorAll('.ib-dlg-backdrop');
        if (stack.length && backdrop !== stack[stack.length - 1]) return;
        if (e.key === 'Escape') {
          e.preventDefault(); e.stopPropagation();
          close(cancelValue());
        } else if (e.key === 'Enter' && (!input || e.target === input)) {
          e.preventDefault(); e.stopPropagation();
          close(okValue());
        } else if (e.key === 'Tab') {
          // Focus trap: the dialog has two or three focusable nodes, so the
          // cycle is short enough to compute every time.
          var f = dialog.querySelectorAll('button, input, [tabindex]:not([tabindex="-1"])');
          if (!f.length) return;
          var first = f[0], last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }
      document.addEventListener('keydown', onKey, true);

      dialog.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ib-dlg]');
        if (!btn) return;
        if (btn.dataset.ibDlg === 'ok') {
          close(okValue());
        } else {
          close(cancelValue());
        }
      });
      // Clicking the backdrop cancels — but not a drag that STARTED inside the
      // dialog and ended outside it (selecting the message text, typically).
      var downOnBackdrop = false;
      backdrop.addEventListener('mousedown', function (e) { downOnBackdrop = (e.target === backdrop); });
      backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop && downOnBackdrop) close(cancelValue());
      });
    });
  }

  window.IbModal = {
    confirm: function (opts) {
      opts = (typeof opts === 'string') ? { message: opts } : (opts || {});
      opts.kind = 'confirm';
      return open(opts);
    },
    alert: function (opts) {
      opts = (typeof opts === 'string') ? { message: opts } : (opts || {});
      opts.kind = 'alert';
      return open(opts);
    },
    prompt: function (opts) {
      opts = (typeof opts === 'string') ? { message: opts } : (opts || {});
      opts.kind = 'prompt';
      return open(opts);
    },
    choice: function (opts) {
      opts = opts || {};
      opts.kind = 'choice';
      return open(opts);
    },
  };
})();


/* ==========================================================================
   Filtres del llistat en un modal centrat (04/09/2026)
   --------------------------------------------------------------------------
   El panell (#ib-filters-panel) es queda al DOM, amagat, i es PRESTA al
   dialeg: aixi el <form> conserva els seus camps, els seus hidden (q, sortby,
   vistes...) i les seves accions Aplicar/Netejar, que naveguen soles. Per
   aixo el modal s'obre com a `alert`: nomes hi posa un boto de tancar i no
   duplica cap accio. En tancar-lo, el panell torna al seu lloc exacte.
   ========================================================================== */
(function () {
  'use strict';

  function T(k, fallback) {
    var v = (typeof window.t === 'function') ? window.t(k) : k;
    // t() torna la clau quan la constant no hi es al bundle.
    return (!v || v === k) ? fallback : v;
  }

  document.addEventListener('click', function (ev) {
    var trigger = ev.target.closest && ev.target.closest('[data-ib-filters-open]');
    if (!trigger) return;
    ev.preventDefault();

    var panel = document.getElementById('ib-filters-panel');
    if (!panel || !window.IbModal) return;

    var home = document.createComment('ib-filters-panel');
    panel.parentNode.insertBefore(home, panel);
    panel.hidden = false;

    window.IbModal.alert({
      title: T('filters', 'Filtres'),
      message: '',
      icon: 'fa-solid fa-sliders',
      bodyExtra: panel,
      closeX: true,       // es tanca amb la creueta de dalt a la dreta
      hideActions: true   // Aplicar i Netejar ja son al formulari
    }).then(function () {
      panel.hidden = true;
      if (home.parentNode) {
        home.parentNode.insertBefore(panel, home);
        home.parentNode.removeChild(home);
      }
    });
  });
})();


/* ==========================================================================
   Commutadors en linia del llistat (04/09/2026)
   --------------------------------------------------------------------------
   Equivalent de l'updateObject() del KMS, pero contra l'accio `toggle` del
   modul, que comprova token, permisos i llista blanca de camps. Si el servidor
   diu que no, la casella torna enrere: mai es deixa la pantalla dient una cosa
   diferent del que hi ha desat.
   ========================================================================== */
(function () {
  'use strict';

  document.addEventListener('change', function (ev) {
    var box = ev.target;
    if (!box || !box.classList || !box.classList.contains('ib-inline-toggle')) return;

    var url   = box.getAttribute('data-url') || window.location.pathname;
    var field = box.getAttribute('data-field') || '';
    var id    = parseInt(box.getAttribute('data-id') || '0', 10);
    var want  = box.checked ? 1 : 0;
    if (!field || !id) return;

    var token = (typeof ibGetCsrfToken === 'function') ? ibGetCsrfToken() : '';
    box.disabled = true;

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify({ _: 'toggle', id: id, field: field, value: want })
    }).then(function (r) {
      return r.json().catch(function () { return null; });
    }).then(function (d) {
      box.disabled = false;
      if (!d || !d.ok) {
        box.checked = !box.checked;   // el servidor no ho ha desat
        if (window.IbModal) {
          window.IbModal.alert({ message: (d && d.error) ? d.error : 'No s\'ha pogut desar el canvi.' });
        }
        return;
      }
      // El KMS treu la negreta de la fila en validar-la, a l'acte.
      var tr = box.closest('tr');
      if (tr && field === 'validat') {
        tr.classList.toggle('ib-row--not-validated', want !== 1);
      }
    }).catch(function () {
      box.disabled = false;
      box.checked = !box.checked;
    });
  });
})();
