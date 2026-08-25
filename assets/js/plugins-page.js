/* RestorePilot — confirm prompt before the plugin is deleted from the Plugins screen. */
(function () {
  var data = window.restorePilotPluginsPage || {};
  var slug = data.slug || 'restorepilot-backup-migration';
  var message = data.confirmMessage || '';
  if (!message) {
    return;
  }
  var row = document.querySelector('tr[data-slug="' + slug + '"]');
  if (!row) {
    return;
  }
  var deleteLink = row.querySelector('.delete a, .deactivate + .delete a, a[href*="delete-plugin"]');
  if (!deleteLink) {
    return;
  }
  deleteLink.addEventListener('click', function (e) {
    if (!window.confirm(message)) {
      e.preventDefault();
    }
  });
})();
