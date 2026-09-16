'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const body = document.body;
  const sidebar = document.getElementById('appSidebar');
  const toggle = document.querySelector('.mobile-nav-toggle');
  const backdrop = document.querySelector('.sidebar-backdrop');
  let previouslyFocused = null;

  const focusableSelector = [
    'a[href]:not([tabindex="-1"])',
    'button:not([disabled]):not([tabindex="-1"])',
    'input:not([disabled]):not([tabindex="-1"])',
    'select:not([disabled]):not([tabindex="-1"])',
    'textarea:not([disabled]):not([tabindex="-1"])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  const isMobileNavigation = () => window.matchMedia('(max-width: 900px)').matches;

  const setDrawerState = (open, restoreFocus = true) => {
    if (!sidebar || !toggle) return;

    body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
    sidebar.setAttribute('aria-hidden', open || !isMobileNavigation() ? 'false' : 'true');

    if (open) {
      previouslyFocused = document.activeElement;
      const firstFocusable = sidebar.querySelector(focusableSelector);
      (firstFocusable || sidebar).focus();
    } else if (restoreFocus && previouslyFocused instanceof HTMLElement) {
      previouslyFocused.focus();
      previouslyFocused = null;
    }
  };

  toggle?.addEventListener('click', () => {
    setDrawerState(!body.classList.contains('nav-open'));
  });

  backdrop?.addEventListener('click', () => setDrawerState(false));

  sidebar?.addEventListener('click', (event) => {
    if (isMobileNavigation() && event.target.closest('a')) {
      setDrawerState(false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!body.classList.contains('nav-open')) return;

    if (event.key === 'Escape') {
      event.preventDefault();
      setDrawerState(false);
      return;
    }

    if (event.key !== 'Tab' || !sidebar) return;
    const focusable = Array.from(sidebar.querySelectorAll(focusableSelector))
      .filter((element) => element instanceof HTMLElement && element.offsetParent !== null);
    if (!focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  const syncNavigationMode = () => {
    if (!sidebar || !toggle) return;
    if (isMobileNavigation()) {
      if (!body.classList.contains('nav-open')) sidebar.setAttribute('aria-hidden', 'true');
    } else {
      body.classList.remove('nav-open');
      sidebar.setAttribute('aria-hidden', 'false');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open navigation menu');
    }
  };

  syncNavigationMode();
  window.addEventListener('resize', syncNavigationMode);

  const enhanceDataTable = (table) => {
    if (!(table instanceof HTMLTableElement) || table.dataset.responsiveReady === 'true') return;

    const headers = Array.from(table.querySelectorAll('tr:first-child th'))
      .map((header) => header.textContent.trim());
    table.querySelector('tr:first-child')?.classList.add('mobile-table-header');
    const rows = Array.from(table.querySelectorAll('tr')).filter((row) => row.querySelector('td'));

    if (table.dataset.mobileTable !== 'scroll') {
      table.classList.add('mobile-card-table');
      rows.forEach((row) => {
        Array.from(row.children).forEach((cell, index) => {
          if (cell instanceof HTMLTableCellElement) {
            cell.dataset.label = headers[index] || 'Details';
          }
        });
      });
    }

    if (!table.parentElement?.classList.contains('responsive-table-shell')) {
      const shell = document.createElement('div');
      shell.className = 'responsive-table-shell';
      table.parentNode.insertBefore(shell, table);
      shell.appendChild(table);

      if (table.dataset.mobileTable === 'scroll') {
        const hint = document.createElement('p');
        hint.className = 'scroll-hint';
        hint.textContent = 'Swipe horizontally to see all columns.';
        shell.insertAdjacentElement('afterend', hint);
      }
    }

    table.dataset.responsiveReady = 'true';
  };

  const enhanceTablesWithin = (root) => {
    if (!(root instanceof Element) && root !== document) return;
    if (root instanceof HTMLTableElement && root.matches('table.data-table')) enhanceDataTable(root);
    root.querySelectorAll?.('table.data-table').forEach(enhanceDataTable);
  };

  enhanceTablesWithin(document);
  const tableObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node instanceof Element) enhanceTablesWithin(node);
      });
    });
  });
  tableObserver.observe(document.body, { childList: true, subtree: true });

  document.querySelectorAll('.logout-btn').forEach((btn) => {
    btn.addEventListener('click', async (event) => {
      event.preventDefault();
      await fetch('php/api/auth.php?action=logout', { method: 'POST' }).catch(() => {});
      window.location.href = 'index.html?reason=logged_out';
    });
  });
});
