/* Applies the saved theme before first paint to avoid a flash of the
   wrong colour scheme. Kept separate and tiny so it can load blocking. */
(function () {
  try {
    var t = localStorage.getItem('qroute-theme');
    if (t === 'light' || t === 'dark') {
      document.documentElement.setAttribute('data-theme', t);
    }
  } catch (e) { /* storage blocked; fall back to the media query */ }
})();
