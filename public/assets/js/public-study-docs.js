// Documentos del estudio (estudio.php?d=): marca el documento que se está viendo en el visor.
document.addEventListener('click', function (event) {
  var link = event.target.closest('a[target="ps-visor"]');
  if (!link) return;
  document.querySelectorAll('.ps-viewer .ps-docs li.is-current').forEach(function (item) { item.classList.remove('is-current'); });
  link.parentElement.classList.add('is-current');
});
