export function setActiveNavigation() {
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.main-nav a, .side-nav a').forEach((link) => {
    const targetPage = link.getAttribute('href');
    if (targetPage === currentPage) {
      link.classList.add('active');
    }
  });
}

export function preventPlaceholderForms() {
  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      alert('Frontend placeholder only. Firebase logic will be added in the next phase.');
    });
  });
}

export function toggleSidebar() {
  const sidebar = document.querySelector('.sidebar');
  if (sidebar) {
    sidebar.classList.toggle('is-open');
  }
}
