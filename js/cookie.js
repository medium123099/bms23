/* cookies.js — баннер согласия на cookie. Подключить на всех страницах:
   <script src="js/cookies.js" defer></script> */
(function () {
  'use strict';

  var NAME = 'cookie_consent';

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }
  function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + days * 86400000);
    document.cookie = name + '=' + encodeURIComponent(value) +
      '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax; Secure';
  }

  if (getCookie(NAME)) return;

  var css = document.createElement('style');
  css.textContent =
    '.ck-banner{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;max-width:760px;margin:0 auto;' +
    'background:#fff;border:1px solid #dbe5e5;border-radius:14px;box-shadow:0 10px 30px rgba(0,60,60,.18);' +
    'padding:16px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;font-size:14px;line-height:1.5;color:#123}' +
    '.ck-banner p{margin:0;flex:1 1 280px}' +
    '.ck-banner a{color:#0e9a9a}' +
    '.ck-actions{display:flex;gap:8px;flex-wrap:wrap}' +
    '.ck-btn{border:1px solid #0e9a9a;background:#0e9a9a;color:#fff;border-radius:999px;padding:9px 18px;font:inherit;cursor:pointer}' +
    '.ck-btn--ghost{background:#fff;color:#0e9a9a}';
  document.head.appendChild(css);

  var box = document.createElement('div');
  box.className = 'ck-banner';
  box.setAttribute('role', 'dialog');
  box.setAttribute('aria-label', 'Использование cookie');
  box.innerHTML =
    '<p>Мы используем cookie, чтобы работал вход в аккаунт и корзина, а также для улучшения сайта. ' +
    'Продолжая пользоваться сайтом, вы соглашаетесь с их использованием.</p>' +
    '<div class="ck-actions">' +
      '<button type="button" class="ck-btn ck-btn--ghost" data-ck="necessary">Только необходимые</button>' +
      '<button type="button" class="ck-btn" data-ck="all">Принять все</button>' +
    '</div>';

  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ck]');
    if (!b) return;
    setCookie(NAME, b.getAttribute('data-ck'), 365);
    box.remove();
  });

  function show() { document.body.appendChild(box); }
  if (document.body) show(); else document.addEventListener('DOMContentLoaded', show);
})();