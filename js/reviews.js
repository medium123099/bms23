/* reviews.js — отзывы на главной: список опубликованных из базы + форма (уходит на модерацию).
   Нужен контейнер <div id="reviewsRoot"></div> и файл reviews.php на сервере. */
(function () {
  'use strict';

  var root = document.getElementById('reviewsRoot');
  if (!root) return;

  var list = null;       // null — ещё грузится
  var rating = 0;
  var prefillName = '';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function stars(n) { var s = ''; for (var i = 1; i <= 5; i++) s += i <= n ? '★' : '☆'; return s; }

  var css = document.createElement('style');
  css.textContent =
    '.rv-form{background:#fff;border:1px solid var(--teal-line,#dbe5e5);border-radius:18px;padding:26px;max-width:600px;margin:36px auto 0}' +
    '.rv-form h3{margin:0 0 6px}.rv-form>p{margin:0 0 6px;color:var(--teal,#6b7d7d);font-size:14px}' +
    '.rv-form label{display:block;font-weight:500;margin:14px 0 6px;font-size:14px}' +
    '.rv-form input[type=text],.rv-form textarea{width:100%;box-sizing:border-box;padding:12px 14px;border:1.5px solid var(--teal-line,#c9d6d6);border-radius:12px;font:inherit}' +
    '.rv-form input:focus,.rv-form textarea:focus{outline:none;border-color:var(--teal,#14a3a1);box-shadow:0 0 0 3px rgba(20,163,161,.15)}' +
    '.rv-form .input-error{border-color:#c0392b!important}' +
    '.rv-form textarea{min-height:110px;resize:vertical}' +
    '.rv-rate{display:flex;gap:2px}' +
    '.rv-rate button{background:none;border:0;font-size:32px;line-height:1;cursor:pointer;color:#c9d6d6;padding:0 2px}' +
    '.rv-rate button.on{color:#e0a800}' +
    '.rv-err{color:#c0392b;font-size:13px;min-height:16px;margin-top:4px}' +
    '.rv-ok{color:#2f855a;margin-top:12px}' +
    '.rv-hp{position:absolute;left:-9999px;height:0;overflow:hidden}' +
    '.rv-empty{text-align:center;color:var(--teal,#6b7d7d)}';
  document.head.appendChild(css);

  function render() {
    var html = '';
    if (list === null) {
      html += '<p class="rv-empty">Загружаем отзывы…</p>';
    } else if (list.length) {
      html += '<div class="reviews-grid">' + list.map(function (r) {
        return '<article class="review-card">' +
          '<div class="review-card__stars" aria-label="Оценка ' + r.rating + ' из 5">' + stars(r.rating) + '</div>' +
          '<p>' + esc(r.text) + '</p>' +
          '<div class="review-card__author">' + esc(r.name) + '</div>' +
          '</article>';
      }).join('') + '</div>';
    } else {
      html += '<p class="rv-empty">Пока нет опубликованных отзывов — будьте первым.</p>';
    }

    html +=
      '<form class="rv-form" id="rvForm" novalidate>' +
        '<h3>Оставить отзыв</h3>' +
        '<p>Отзыв появится на сайте после проверки.</p>' +
        '<label for="rvName">Имя</label>' +
        '<input type="text" id="rvName" maxlength="60" value="' + esc(prefillName) + '" placeholder="Как вас зовут">' +
        '<div class="rv-err" id="rvNameErr"></div>' +
        '<label>Оценка</label>' +
        '<div class="rv-rate" id="rvRate">' +
          [1, 2, 3, 4, 5].map(function (n) { return '<button type="button" data-n="' + n + '" aria-label="' + n + ' из 5">★</button>'; }).join('') +
        '</div>' +
        '<div class="rv-err" id="rvRateErr"></div>' +
        '<label for="rvText">Отзыв</label>' +
        '<textarea id="rvText" maxlength="1000" placeholder="Расскажите о вашем опыте"></textarea>' +
        '<div class="rv-err" id="rvTextErr"></div>' +
        '<input type="text" class="rv-hp" id="rvWebsite" tabindex="-1" autocomplete="off" aria-hidden="true">' +
        '<button type="submit" class="btn btn-primary" style="margin-top:10px">Отправить</button>' +
        '<div class="rv-ok" id="rvOk" hidden>Спасибо! Отзыв появится на сайте после проверки.</div>' +
      '</form>';

    root.innerHTML = html;
    rating = 0;
    bind();
  }

  function q(id) { return root.querySelector(id); }

  function paintRate() {
    Array.prototype.forEach.call(root.querySelectorAll('#rvRate button'), function (b) {
      b.classList.toggle('on', Number(b.getAttribute('data-n')) <= rating);
    });
  }

  function bind() {
    q('#rvRate').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      rating = Number(b.getAttribute('data-n')); paintRate();
    });

    q('#rvForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var nameEl = q('#rvName'), textEl = q('#rvText');
      var name = nameEl.value.trim(), text = textEl.value.trim(), ok = true;

      ['#rvNameErr', '#rvRateErr', '#rvTextErr'].forEach(function (s) { q(s).textContent = ''; });
      nameEl.classList.remove('input-error'); textEl.classList.remove('input-error');

      if (name.length < 2) { nameEl.classList.add('input-error'); q('#rvNameErr').textContent = 'Введите имя'; ok = false; }
      if (!rating) { q('#rvRateErr').textContent = 'Поставьте оценку'; ok = false; }
      if (text.length < 10) { textEl.classList.add('input-error'); q('#rvTextErr').textContent = 'Напишите хотя бы 10 символов'; ok = false; }
      if (!ok) return;

      var btn = e.target.querySelector('button[type="submit"]'); btn.disabled = true;

      var fd = new FormData();
      fd.append('name', name); fd.append('rating', rating); fd.append('text', text);
      fd.append('website', q('#rvWebsite').value);

      fetch('reviews.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          btn.disabled = false;
          if (!d.success) {
            var t = d.field === 'name' ? '#rvNameErr' : d.field === 'rating' ? '#rvRateErr' : '#rvTextErr';
            q(t).textContent = d.message || 'Не удалось отправить отзыв';
            return;
          }
          textEl.value = ''; rating = 0; paintRate();
          q('#rvOk').hidden = false;
        })
        .catch(function () { btn.disabled = false; q('#rvTextErr').textContent = 'Не удалось связаться с сервером'; });
    });
  }

  render();

  fetch('reviews.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { list = d.success ? d.reviews : []; render(); })
    .catch(function () { list = []; render(); });

  // подставляем имя вошедшего пользователя
  fetch('check_auth.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.auth && d.user) {
        prefillName = d.user.name || '';
        var n = document.getElementById('rvName');
        if (n && !n.value) n.value = prefillName;
      }
    }).catch(function () {});
})();
