/* Contrato de mandato (vista interna de public/mandato.php): copiar el enlace del propietario. */
(function () {
  "use strict";
  var button = document.querySelector("[data-pm-copy]");
  var field = document.querySelector("[data-pm-link]");
  if (!button || !field) return;
  button.addEventListener("click", function () {
    var done = function () {
      button.textContent = "Copiado";
      window.setTimeout(function () { button.textContent = "Copiar"; }, 2000);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(field.value).then(done, function () { field.select(); });
    } else {
      field.select();
      try { document.execCommand("copy"); done(); } catch (_error) {}
    }
  });
})();
