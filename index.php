<?php
// Auth gate: the app requires a signed-in user. Unauthenticated visitors are sent
// to the login page. Everything data-related happens via api.php (also cookie-auth).
require_once __DIR__ . '/functions.php';
init_db();
if (current_user_email() === '') { header('Location: login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DataGrid</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400..700;1,400..700&display=swap">
  <?php readfile(__DIR__ . '/view/Stylesheet.html'); ?>
</head>
<body>
  <div id="app" class="app" aria-busy="true">

    <!-- Site navbar -->
    <header class="topbar">
      <div class="nav-inner">
        <div class="brand">
          <span class="brand-mark" aria-hidden="true">◧</span>
          <span class="brand-name">DataGrid</span>
          <span class="brand-sub">CRUD workspace</span>
        </div>
        <div class="topbar-right">
          <span id="whoami" class="whoami" title="You’re signed in as" hidden></span>
          <span id="readOnlyBadge" class="readonly-badge" title="Only an administrator can change this table" hidden>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            View only
          </span>
          <span id="rowCount" class="row-count" title="Records in the table"></span>
          <a id="sheetLink" class="ghost-link" href="#" target="_blank" rel="noopener" hidden>Open data sheet ↗</a>
          <a class="ghost-link" href="logout.php" title="Sign out of DataGrid">Sign out</a>
        </div>
      </div>
    </header>

    <div class="site-content">
      <div class="container">
      <div class="page-head">
        <h1 class="page-title">Workspace</h1>
        <p class="page-sub">Your tables, records and views</p>
      </div>

      <!-- Table tabs — switch the active table (replaces the old Settings switcher) -->
      <div id="tableTabs" class="table-tabs" role="tablist" aria-label="Tables" hidden></div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="search">
        <span class="search-icon" aria-hidden="true">⌕</span>
        <input id="searchInput" type="search" placeholder="Search all columns" autocomplete="off" aria-label="Search all columns">
        <button id="searchClear" class="search-clear" type="button" aria-label="Clear search" hidden>×</button>
      </div>
      <div class="toolbar-actions">
        <!-- Everyday view actions, always visible -->
        <button id="btnFilter" class="btn btn-ghost" type="button" title="Filter rows">
          <span class="btn-ico" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg></span> Filter<span id="filterBadge" class="count-badge" hidden></span>
        </button>
        <button id="btnTotal" class="btn btn-ghost" type="button">
          <span class="btn-ico" aria-hidden="true">∑</span> Total
        </button>
        <button id="btnExport" class="btn btn-ghost" type="button">
          <span class="btn-ico" aria-hidden="true">⇩</span> Export CSV
        </button>

        <span class="tb-sep" aria-hidden="true"></span>

        <!-- Overflow menu: occasional + admin actions, tucked away -->
        <div id="moreMenu" class="menu">
          <button id="btnMore" class="btn btn-ghost" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="moreMenuPanel" title="More actions">
            <span class="btn-ico" aria-hidden="true">⋯</span> More <span class="menu-caret" aria-hidden="true">▾</span>
          </button>
          <div id="moreMenuPanel" class="menu-panel" role="menu" aria-label="More actions" hidden>
            <button id="btnColorRules" class="menu-item" type="button" role="menuitem" title="Color rows by their values">
              <span class="menu-ico" aria-hidden="true">◧</span> Color rules<span id="colorBadge" class="count-badge" hidden></span>
            </button>
            <span class="menu-sep" role="separator" aria-hidden="true"></span>
            <button id="btnImport" class="menu-item" type="button" role="menuitem">
              <span class="menu-ico" aria-hidden="true">⇧</span> Import CSV
            </button>
            <button id="btnTrash" class="menu-item" type="button" role="menuitem" title="Deleted rows">
              <span class="menu-ico" aria-hidden="true">🗑</span> Trash<span id="trashBadge" class="count-badge" hidden></span>
            </button>
            <button id="btnActivity" class="menu-item" type="button" role="menuitem" hidden>
              <span class="menu-ico" aria-hidden="true">◷</span> Activity
            </button>
            <button id="btnAccess" class="menu-item" type="button" role="menuitem" hidden>
              <span class="menu-ico" aria-hidden="true">⚿</span> Access
            </button>
            <span class="menu-sep" role="separator" aria-hidden="true"></span>
            <button id="btnSettings" class="menu-item" type="button" role="menuitem" title="Appearance &amp; settings">
              <span class="menu-ico" aria-hidden="true">⚙</span> Settings
            </button>
          </div>
        </div>

        <span class="tb-sep" aria-hidden="true"></span>

        <!-- Create actions -->
        <button id="btnAddColumn" class="btn btn-secondary" type="button">
          <span class="btn-ico" aria-hidden="true">＋</span> Add column
        </button>
        <button id="btnAddRow" class="btn btn-primary" type="button">
          <span class="btn-ico" aria-hidden="true">＋</span> Add row
        </button>
      </div>
    </div>

    <!-- View bar: saved-view switcher + Grid/Kanban layout toggle (built by renderViewBar) -->
    <div id="viewBar" class="view-bar" hidden></div>

    <!-- Bulk action bar (shown only while rows are selected) -->
    <div id="bulkBar" class="bulk-bar" hidden></div>

    <!-- Table -->
    <main class="table-wrap">
      <div id="tableScroll" class="table-scroll">
        <table id="grid" class="grid" role="grid">
          <colgroup id="colgroup"></colgroup>
          <thead id="thead"></thead>
          <tbody id="tbody"></tbody>
        </table>
      </div>

      <!-- Kanban board (shown instead of the table when the view type is Kanban) -->
      <div id="kanban" class="kanban" hidden></div>

      <!-- Dashboard (shown instead of the table when the view type is Dashboard) -->
      <div id="dashboard" class="dashboard" hidden></div>

      <!-- Calendar (shown instead of the table when the view type is Calendar) -->
      <div id="calendar" class="calendar" hidden></div>

      <!-- Gallery (shown instead of the table when the view type is Gallery):
           a card grid whose hero images come from a chosen Attachment column -->
      <div id="gallery" class="gallery" hidden></div>

      <!-- Schedule roster (shown instead of the table for the built-in Shift
           Schedule): a weekly roster with green date bands, one line per person
           per day, and per-day notes. Built by renderSchedule. -->
      <div id="schedule" class="schedule" hidden></div>

      <!-- Empty states -->
      <div id="emptyNoColumns" class="empty" hidden>
        <div class="empty-art" aria-hidden="true">▦</div>
        <h2>Start by adding a column</h2>
        <p>This table is yours to shape. Add your first column — text, number, date, or a dropdown — then start adding rows.</p>
        <button class="btn btn-primary" type="button" onclick="document.getElementById('btnAddColumn').click()">
          <span class="btn-ico" aria-hidden="true">＋</span> Add column
        </button>
      </div>

      <div id="emptyNoRows" class="empty" hidden>
        <div class="empty-art" aria-hidden="true">▤</div>
        <h2>No rows yet</h2>
        <p>Add your first record to see it here.</p>
        <button class="btn btn-primary" type="button" onclick="document.getElementById('btnAddRow').click()">
          <span class="btn-ico" aria-hidden="true">＋</span> Add row
        </button>
      </div>

      <div id="emptyNoMatch" class="empty" hidden>
        <div class="empty-art" aria-hidden="true">⌕</div>
        <h2>No matches</h2>
        <p>No rows match your search. Try a different term.</p>
      </div>
    </main>

    <!-- Pagination -->
    <footer id="pager" class="pager" hidden>
      <div class="pager-left">
        <label class="pager-size">
          Rows per page
          <select id="pageSize" aria-label="Rows per page">
            <option>10</option>
            <option>25</option>
            <option>50</option>
            <option>100</option>
          </select>
        </label>
        <span id="pagerRange" class="pager-range"></span>
      </div>
      <div class="pager-right">
        <button id="pageFirst" class="page-btn" type="button" aria-label="First page">«</button>
        <button id="pagePrev"  class="page-btn" type="button" aria-label="Previous page">‹</button>
        <span id="pageInfo" class="page-info"></span>
        <button id="pageNext"  class="page-btn" type="button" aria-label="Next page">›</button>
        <button id="pageLast"  class="page-btn" type="button" aria-label="Last page">»</button>
      </div>
    </footer>
      </div>
    </div>

    <!-- Site footer -->
    <footer class="site-footer">
      <div class="footer-inner">
        <span class="footer-brand"><span aria-hidden="true">◧</span> DataGrid</span>
        <span class="footer-copy">A flexible data workspace · built with PHP &amp; SQLite</span>
      </div>
    </footer>

    <!-- Initial loading veil -->
    <div id="loader" class="loader">
      <div class="spinner" aria-hidden="true"></div>
      <p>Loading your workspace…</p>
    </div>
  </div>

  <!-- Modal -->
  <div id="modalOverlay" class="modal-overlay" hidden>
    <div id="modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-head">
        <h2 id="modalTitle" class="modal-title"></h2>
        <button id="modalClose" class="modal-close" type="button" aria-label="Close">×</button>
      </div>
      <div id="modalBody" class="modal-body"></div>
      <div id="modalFoot" class="modal-foot"></div>
    </div>
  </div>

  <!-- Toasts -->
  <div id="toasts" class="toasts" aria-live="polite"></div>

  <!-- Record side-panel (slide-over): view & edit a single record without leaving
       the grid. Opened by the expand affordance on each row; content is built by
       the RecordPanel controller in JavaScript.html. -->
  <div id="panelOverlay" class="panel-overlay" hidden>
    <aside id="recordPanel" class="record-panel" role="dialog" aria-modal="true" aria-labelledby="panelTitle">
      <div class="panel-head">
        <div class="panel-head-main">
          <h2 id="panelTitle" class="panel-title">Record</h2>
          <span id="panelSub" class="panel-sub"></span>
        </div>
        <div class="panel-head-actions">
          <span class="panel-step" role="group" aria-label="Move between records">
            <button id="panelPrev" class="icon-btn" type="button" title="Previous record" aria-label="Previous record">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </button>
            <button id="panelNext" class="icon-btn" type="button" title="Next record" aria-label="Next record">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
          </span>
          <button id="panelClose" class="panel-close" type="button" aria-label="Close record">×</button>
        </div>
      </div>
      <div id="panelBody" class="panel-body"></div>
      <div id="panelFoot" class="panel-foot"></div>
    </aside>
  </div>

  <!-- Sign-in gate. Shown first: greets the signed-in user, or asks for an email
       when we can't identify them. The client swaps #loginBody. -->
  <div id="loginScreen" class="login-screen" role="dialog" aria-modal="true" aria-labelledby="loginBrandName">
    <div class="login-card">
      <div class="login-brand">
        <span class="brand-mark" aria-hidden="true">◧</span>
        <span id="loginBrandName" class="brand-name">DataGrid</span>
      </div>
      <div id="loginBody" class="login-body">
        <div class="login-checking">
          <div class="spinner" aria-hidden="true"></div>
          <p>Checking your sign-in…</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Bridge the Apps Script client to the PHP backend: google.script.run.<fn>(args)
       becomes a POST to api.php, preserving the withSuccessHandler/withFailureHandler API. -->
  <script>
  (function () {
    function makeRunner(onSuccess, onFailure) {
      return new Proxy({}, {
        get: function (target, prop) {
          if (prop === 'withSuccessHandler') return function (cb) { return makeRunner(cb, onFailure); };
          if (prop === 'withFailureHandler') return function (cb) { return makeRunner(onSuccess, cb); };
          if (prop === 'withUserObject')     return function ()   { return makeRunner(onSuccess, onFailure); };
          return function () {
            var args = Array.prototype.slice.call(arguments);
            fetch('api.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ fn: String(prop), args: args })
            })
              .then(function (r) { return r.json(); })
              .then(function (res) {
                if (res && res.ok) { if (onSuccess) onSuccess(res.result); }
                else if (onFailure) onFailure(new Error((res && res.error) || 'Server error.'));
              })
              .catch(function (err) { if (onFailure) onFailure(err); });
            return undefined;
          };
        }
      });
    }
    window.google = window.google || {};
    window.google.script = window.google.script || {};
    window.google.script.run = makeRunner(null, null);
  })();
  </script>

  <?php readfile(__DIR__ . '/view/JavaScript.html'); ?>
</body>
</html>
