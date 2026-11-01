/* callback.js — формы «Перезвоните мне» (страницы /contacts и /courses).
   Разметка: <form class="cb-form" data-callback-form data-topic="Обучение"> … </form> */
(function () {
  'use strict';

  function digits(raw) {
    raw = String(raw || '');
    var d = raw.replace(/\D/g, '');
    if (/^\s*\+7/.test(raw) || (d.length > 10 && (d[0] === '7' || d[0] === '8'))) d = d.slice(1);
    return d.slice(0, 10);
  }
  function fmt(d) {
    var s = '+7 ' + d.slice(0, 3);
    if (d.length > 3) s += ' ' + d.slice(3, 6);
    if (d.length > 6) s += '-' + d.slice(6, 8);
    if (d.length > 8) s += '-' + d.slice(8, 10);
    return s;
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-callback-form]'), function (form) {
    var name = form.querySelector('[name="name"]');
    var phone = form.querySelector('[name="phone"]');
    var msg = form.querySelector('[name="message"]');
    var err = form.querySelector('.cb-error');
    var ok = form.querySelector('.cb-ok');
    var btn = form.querySelector('button[type="submit"]');

    phone.addEventListener('keydown', function (e) {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.key && e.key.length === 1 && !/\d/.test(e.key)) e.preventDefault();
    });
    phone.addEventListener('input', function () { phone.value = fmt(digits(phone.value)); });
    phone.addEventListener('focus', function () { if (!phone.value) phone.value = '+7 '; });
    phone.addEventListener('blur', function () { if (!digits(phone.value)) phone.value = ''; });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      err.textContent = ''; ok.hidden = true;
      name.classList.remove('input-error'); phone.classList.remove('input-error');

      var n = name.value.trim(), d = digits(phone.value), bad = false;
      if (n.length < 2) { name.classList.add('input-error'); bad = true; }
      if (d.length !== 10) { phone.classList.add('input-error'); bad = true; }
      if (bad) { err.textContent = 'Укажите имя и номер телефона (10 цифр после +7)'; return; }

      btn.disabled = true;
      var fd = new FormData();
      fd.append('name', n);
      fd.append('phone', d);
      fd.append('message', msg ? msg.value.trim() : '');
      fd.append('topic', form.getAttribute('data-topic') || '');
      fd.append('website', (form.querySelector('.cb-hp') || {}).value || '');

      fetch('contact.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (!data.success) { err.textContent = data.message || 'Не удалось отправить заявку'; return; }
          if (window.ym) { ym(92555538, 'reachGoal', 'callback_sent', { topic: form.getAttribute('data-topic') || '' }); }
          form.reset(); ok.hidden = false;
        })
        .catch(function () { btn.disabled = false; err.textContent = 'Не удалось связаться с сервером'; });
    });
  });
})();
