document.addEventListener("click", (event) => {
  const toggle = event.target.closest("[data-sidebar-toggle]");
  if (!toggle) return;

  const sidebar = document.getElementById(toggle.dataset.sidebarToggle);
  if (!sidebar) return;

  const isOpen = sidebar.classList.toggle("open");
  toggle.setAttribute("aria-expanded", String(isOpen));
});

document.addEventListener("click", (event) => {
  const openSidebar = document.querySelector(".sidebar.open");
  if (!openSidebar || event.target.closest(".sidebar, [data-sidebar-toggle]")) return;

  openSidebar.classList.remove("open");
  document.querySelector(`[data-sidebar-toggle="${openSidebar.id}"]`)
    ?.setAttribute("aria-expanded", "false");
});
