/* prices.js — подтягивает актуальные цены из админки (prices.php) на страницы и в корзину.
   Если запрос не удался, остаются цены, прописанные в самой странице. */
(function () {
  'use strict';

  var KEY = 'treelife_cart';

  function fmt(n) { return Number(n).toLocaleString('ru-RU') + '\u00a0₽'; }

  /* меняет только число, префикс вроде «от» остаётся */
  function setPrice(el, price) {
    var small = el.querySelector('small');
    var txt = fmt(price);
    if (small) {
      var last = el.lastChild;
      if (last && last.nodeType === 3) last.nodeValue = ' ' + txt; else el.appendChild(document.createTextNode(' ' + txt));
    } else {
      el.textContent = txt;
    }
  }

  function apply(P) {
    // кнопки «В корзину» и цена рядом с ними
    Array.prototype.forEach.call(document.querySelectorAll('[data-id][data-price]'), function (btn) {
      var p = P[btn.getAttribute('data-id')];
      if (!p) return;
      btn.setAttribute('data-price', p.price);
      var box = btn.closest('.product-card, .pd-buy');
      var el = box && box.querySelector('.product-card__price, .pd-price');
      if (el) setPrice(el, p.price);
    });

    // карточки «Другие аппараты»
    Array.prototype.forEach.call(document.querySelectorAll('a.other-card[href]'), function (a) {
      var id = (a.getAttribute('href') || '').replace(/^\//, '');
      var p = P[id], s = a.querySelector('strong');
      if (!p || !s) return;
      var from = /^от\s/.test(s.textContent) ? 'от ' : '';
      s.textContent = from + fmt(p.price);
    });

    // корзина в localStorage: цена и название берутся из каталога
    var changed = false, items;
    try { items = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { items = []; }
    if (Array.isArray(items) && items.length) {
      items = items.filter(function (i) { return i && P[i.id]; }).map(function (i) {
        var p = P[i.id];
        if (Number(i.price) !== p.price || i.name !== p.name) { changed = true; i.price = p.price; i.name = p.name; }
        return i;
      });
      if (changed) {
        try { localStorage.setItem(KEY, JSON.stringify(items)); } catch (e) {}
        if (typeof window.tlCartRender === 'function') window.tlCartRender();
      }
    }
  }

  fetch('prices.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success && d.products) apply(d.products); })
    .catch(function () {});
})();
