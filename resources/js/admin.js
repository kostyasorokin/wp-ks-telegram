(() => {
  const password = document.getElementById('ks-telegram-token');
  const tabsRoot = document.querySelector('[data-ks-telegram-tabs]');

  if (password) {
    password.addEventListener('focus', () => {
      password.removeAttribute('placeholder');
    });
  }

  if (!tabsRoot) {
    return;
  }

  const tabs = [...tabsRoot.querySelectorAll('[data-ks-telegram-tab]')];
  const panels = [...tabsRoot.querySelectorAll('[data-ks-telegram-panel]')];
  const relatedPanels = [...document.querySelectorAll('[data-ks-telegram-related-panel]')];
  const saveActions = document.querySelector('[data-ks-telegram-save-actions]');
  const storageKey = 'ksTelegramSettingsTab';
  const targets = tabs.map((tab) => tab.dataset.ksTelegramTab);
  const aliases = { integrations: 'plugins' };

  const activate = (target, persist = true) => {
    target = aliases[target] || target;
    target = targets.includes(target) ? target : 'settings';

    tabs.forEach((tab) => {
      const active = tab.dataset.ksTelegramTab === target;

      tab.classList.toggle('nav-tab-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.setAttribute('tabindex', active ? '0' : '-1');
    });

    panels.forEach((panel) => {
      panel.classList.toggle('is-active', panel.dataset.ksTelegramPanel === target);
    });

    relatedPanels.forEach((panel) => {
      const active = panel.dataset.ksTelegramRelatedPanel === target;

      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });

    if (saveActions) {
      saveActions.hidden = target === 'tests';
    }

    if (persist) {
      window.localStorage?.setItem(storageKey, target);
    }
  };

  tabsRoot.classList.add('ks-telegram-tabs-ready');
  activate(window.localStorage?.getItem(storageKey) || 'settings', false);

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => activate(tab.dataset.ksTelegramTab || 'settings'));
  });
})();
