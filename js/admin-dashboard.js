(() => {
  const greeting = document.getElementById('admin-username-display');
  const setGreeting = (username) => { if (greeting) greeting.textContent = username || 'User'; };
  setGreeting(window.cooltimeDashboardIdentity);
  window.addEventListener('cooltime:dashboard-identity', (event) => setGreeting(event.detail?.username));

  const nav = document.querySelector('.admin-side-nav');
  const links = [...(nav?.querySelectorAll('[data-dashboard-view-link]') || [])];
  const views = [...document.querySelectorAll('.admin-dashboard-view[data-dashboard-view]')];
  const title = document.getElementById('adminViewTitle');
  const description = document.getElementById('adminViewDescription');
  const viewCopy = {
    home: ['Dashboard', 'A quick overview of CoolTime operations.'],
    services: ['Services', 'Manage the services and prices offered by CoolTime.'],
    bookings: ['Bookings', 'Review appointments and cancellation requests.'],
    customers: ['Customers', 'Review customer requests, records, and account access.'],
    reports: ['Reports', 'Generate operational reports and review report history.'],
    'pos-transactions': ['POS Transactions', 'Review point of sale invoices and payments.'],
    notifications: ['Notifications', 'Review current customer notifications.']
  };

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
    const copy = viewCopy[activeName] || viewCopy.home;
    if (title) title.textContent = copy[0];
    if (description) description.textContent = copy[1];
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



