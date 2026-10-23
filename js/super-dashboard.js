(() => {
  const greeting = document.getElementById('superadmin-username-display');
  const setGreeting = (username) => { if (greeting) greeting.textContent = username || 'User'; };
  setGreeting(window.cooltimeDashboardIdentity);
  window.addEventListener('cooltime:dashboard-identity', (event) => setGreeting(event.detail?.username));

  const nav = document.querySelector('.super-side-nav');
  const links = [...(nav?.querySelectorAll('[data-dashboard-view-link]') || [])];
  const views = [...document.querySelectorAll('.super-dashboard-view[data-dashboard-view]')];
  function activateView(name, updateHistory = false) {
    const requestedName = String(name || '').replace(/^#/, '');
    const view = views.find((item) => item.dataset.dashboardView === requestedName) || views[0];
    if (!view) return;
    const activeName = view.dataset.dashboardView;
    views.forEach((item) => {
      const active = item === view;
      item.hidden = !active;
      item.classList.toggle('is-active', active);
    });
    links.forEach((link) => {
      const active = link.dataset.dashboardViewLink === activeName;
      link.classList.toggle('active', active);
      link.setAttribute('aria-selected', String(active));
      link.tabIndex = active ? 0 : -1;
    });
    if (activeName === 'home') window.dispatchEvent(new Event('cooltime:dashboard-home-activated'));
    if (updateHistory && location.hash !== `#${activeName}`) history.pushState({ view: activeName }, '', `#${activeName}`);
  }

  links.forEach((link, index) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      activateView(link.dataset.dashboardViewLink, true);
    });
    link.addEventListener('keydown', (event) => {
      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const nextIndex = event.key === 'Home' ? 0
        : event.key === 'End' ? links.length - 1
          : (index + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
      links[nextIndex].focus();
      links[nextIndex].click();
    });
  });

  window.addEventListener('popstate', () => activateView(location.hash.slice(1)));
  window.addEventListener('hashchange', () => activateView(location.hash.slice(1)));
  activateView(location.hash.slice(1));
})();

