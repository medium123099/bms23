/* cabinet.js — «Мои заказы» и привязка бота MAX в личном кабинете.
   Подгружается из account.js, когда человек открывает профиль. Данные: cabinet.php. */
(function () {
  'use strict';

  var box = null, busy = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return (Number(n) || 0).toLocaleString('ru-RU') + ' ₽'; }

  var css = document.createElement('style');
  css.textContent =
    '.cab{margin:18px 0 6px;text-align:left}' +
    '.cab h3{margin:0 0 10px;font-size:16px}' +
    '.cab-order{border:1px solid var(--teal-line,#dbe5e5);border-radius:12px;padding:10px 12px;margin-bottom:8px;font-size:14px}' +
    '.cab-order__top{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-weight:600}' +
    '.cab-order__items{color:var(--teal,#6b7d7d);margin-top:4px}' +
    '.cab-st{display:inline-block;border-radius:10px;padding:1px 9px;font-size:12px;font-weight:500;background:#e7f0ff;color:#2b5cb8}' +
    '.cab-st--receipt,.cab-st--done{background:#e3f5ea;color:#2f855a}.cab-st--payment,.cab-st--shipping,.cab-st--shipped{background:#fff3d9;color:#9a6700}.cab-st--cancelled{background:#fdecea;color:#c0392b}' +
    '.cab-empty,.cab-note{color:var(--teal,#6b7d7d);font-size:14px;margin:0 0 8px}' +
    '.cab-link{margin-top:14px;padding-top:14px;border-top:1px solid var(--teal-line,#dbe5e5)}' +
    '.cab-code{font-size:30px;letter-spacing:6px;font-weight:700;margin:8px 0}' +
    '.cab .btn{margin-top:4px}';
  document.head.appendChild(css);

  function api(body) {
    var opt = { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } };
    if (body) { opt.method = 'POST'; opt.body = JSON.stringify(body); }
    return fetch('cabinet.php', opt).then(function (r) { return r.json(); });
  }

  function ensureBox() {
    if (box && document.body.contains(box)) return box;
    var view = document.getElementById('profileView');
    if (!view) return null;
    box = document.createElement('div');
    box.className = 'cab';
    var logout = document.getElementById('logoutButton');
    if (logout && logout.parentNode === view) view.insertBefore(box, logout); else view.appendChild(box);
    return box;
  }

  function renderOrders(d) {
    var h = '<h3>Мои заказы</h3>';
    if (!d.orders.length) {
      h += '<p class="cab-empty">Заказов пока нет. <a href="/catalog">Перейти в каталог</a></p>';
    } else {
      h += d.orders.slice(0, 10).map(function (o) {
        var items = o.items.map(function (i) { return esc(i.name) + (i.qty > 1 ? ' × ' + i.qty : ''); }).join(', ');
        return '<div class="cab-order"><div class="cab-order__top"><span>№' + o.id + ' · ' + esc(o.date) + '</span>' +
          '<span class="cab-st cab-st--' + esc(o.status) + '">' + esc(o.label) + '</span></div>' +
          '<div class="cab-order__items">' + items + '</div>' +
          '<div>' + money(o.total) + ' · ' + esc(o.source) + '</div></div>';
      }).join('');
    }
    h += '<div class="cab-link" id="cabLink">' + linkHtml(d.linked, null) + '</div>';
    box.innerHTML = h;
  }

  function linkHtml(linked, code) {
    if (code) {
      return '<p class="cab-note">Отправьте этот код боту TreeLife в MAX (команда «🔗 Привязать сайт» в меню или сообщение <b>/link ' + esc(code) + '</b>). Код действует 15 минут.</p>' +
        '<div class="cab-code">' + esc(code) + '</div>';
    }
    if (linked) {
      return '<p class="cab-note">Бот MAX привязан: заказы из бота показываются здесь вместе с заказами сайта.</p>' +
        '<button type="button" class="btn btn-outline" id="cabUnlink">Отвязать бот</button>';
    }
    return '<p class="cab-note">Заказываете через бота в MAX? Привяжите его, и все заказы будут в одном месте.</p>' +
      '<button type="button" class="btn btn-outline" id="cabLinkBtn">Привязать бот MAX</button>';
  }

  function load() {
    var b = ensureBox();
    if (!b || busy) return;
    busy = true;
    b.innerHTML = '';   // не показываем чужие данные, если вошёл другой человек
    api().then(function (d) {
      busy = false;
      if (!d || !d.success) { b.innerHTML = ''; return; }
      renderOrders(d);
    }).catch(function () { busy = false; });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.id) return;
    var link = document.getElementById('cabLink');
    if (t.id === 'cabLinkBtn') {
      t.disabled = true;
      api({ action: 'link_code' }).then(function (d) {
        if (d && d.success && link) link.innerHTML = linkHtml(false, d.code);
        else t.disabled = false;
      }).catch(function () { t.disabled = false; });
    } else if (t.id === 'cabUnlink') {
      if (!confirm('Отвязать бот MAX от аккаунта? Заказы из бота перестанут показываться на сайте.')) return;
      api({ action: 'unlink' }).then(function () { load(); });
    }
  });

  window.tlCabinet = { refresh: load };
  load();
})();
