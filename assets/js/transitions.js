/* BookTrack — cross-page cover hand-off.
   With cross-document view transitions, the cover a visitor clicks glides into
   place on the book page, and glides back into its card on the way back.
   Loaded without `defer` so the `pagereveal` listener exists before the first
   frame. Browsers without the feature skip all of this. */
(function () {
  'use strict';
  if (!('onpagereveal' in window)) return;

  var NAME = 'book-cover';
  var COVER = '.cover, .cover-md';

  function clear() {
    var tagged = document.querySelectorAll('[data-vt-cover]');
    for (var i = 0; i < tagged.length; i++) {
      tagged[i].style.viewTransitionName = '';
      tagged[i].removeAttribute('data-vt-cover');
    }
  }
  function tag(el) {
    clear();
    el.style.viewTransitionName = NAME;
    el.setAttribute('data-vt-cover', '');
  }

  // Leaving a list for a book: name the cover of the card that was clicked.
  document.addEventListener('click', function (ev) {
    if (ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    var link = ev.target.closest && ev.target.closest('a[href*="book.php?id="]');
    if (!link || document.querySelector('.book-hero')) return;
    var holder = link.closest('[data-book]');
    var cover = holder && holder.querySelector(COVER);
    if (cover) tag(cover);
  }, true);

  // Arriving back on a list from a book: name the matching card's cover.
  window.addEventListener('pagereveal', function (ev) {
    if (!ev.viewTransition || document.querySelector('.book-hero')) return;
    var from = window.navigation && navigation.activation && navigation.activation.from;
    var m = from && /book\.php\?(?:.*&)?id=(\d+)/.exec(from.url);
    var holder = m && document.querySelector('[data-book="' + m[1] + '"]');
    var cover = holder && holder.querySelector(COVER);
    if (!cover) return;
    tag(cover);
    ev.viewTransition.finished.finally(clear);
  });

  // A page restored from the back/forward cache may still carry an old name.
  window.addEventListener('pageshow', function (ev) { if (ev.persisted) clear(); });
})();
