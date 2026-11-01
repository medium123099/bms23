/* account.js — модалка личного кабинета, работает с реальным бэкендом
   (login.php / register.php / logout.php / check_auth.php / upload_avatar.php) */
(function () {
  'use strict';

  function $(id) { return document.getElementById(id); }

  var modal = $('accountModal');
  var openBtn = $('accountOpen');
  if (!modal || !openBtn) { return; }

  var closeBtn = $('accountClose');
  var views = { login: $('loginView'), register: $('registerView'), profile: $('profileView') };

  var currentUser = null;

  /* ---------- телефон: маска +7, только цифры ---------- */
  function nationalDigits(raw) {
    raw = String(raw || '');
    var d = raw.replace(/\D/g, '');
    if (/^\s*\+7/.test(raw) || (d.length > 10 && (d[0] === '7' || d[0] === '8'))) d = d.slice(1);
    return d.slice(0, 10);
  }
  function formatPhone(d) {
    var s = '+7 ';
    if (d.length) s = '+7 ' + d.slice(0, 3);
    if (d.length > 3) s += ' ' + d.slice(3, 6);
    if (d.length > 6) s += '-' + d.slice(6, 8);
    if (d.length > 8) s += '-' + d.slice(8, 10);
    return s;
  }
  /* сервер хранит телефон как 79XXXXXXXXX (без +) — для отправки достаточно 10 цифр, сервер сам добавит "7" */
  function digitsForServer(raw) {
    return nationalDigits(raw);
  }
  function formatServerPhone(serverPhone) {
    var d = String(serverPhone || '').replace(/\D/g, '');
    if (d.length === 11 && d[0] === '7') d = d.slice(1);
    return formatPhone(d);
  }
  function attachPhoneMask(input) {
    if (!input) return;
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('autocomplete', 'tel-national');
    function toEnd() { var n = input.value.length; try { input.setSelectionRange(n, n); } catch (e) {} }
    function apply() {
      input.value = formatPhone(nationalDigits(input.value));
      toEnd();
    }
    input.addEventListener('keydown', function (e) {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.key && e.key.length === 1 && !/\d/.test(e.key)) e.preventDefault();
    });
    input.addEventListener('input', apply);
    input.addEventListener('focus', function () {
      if (!input.value) input.value = '+7 ';
      setTimeout(toEnd, 0);
    });
    input.addEventListener('click', toEnd);
    input.addEventListener('blur', function () {
      if (!nationalDigits(input.value)) input.value = '';
    });
  }
  attachPhoneMask($('loginPhone'));
  attachPhoneMask($('registerPhone'));

  /* ---------- показ/скрытие ---------- */
  function toggle(el, on) {
    if (!el) return;
    el.hidden = !on;
    el.style.display = on ? '' : 'none';
  }

  function showView(name) {
    Object.keys(views).forEach(function (k) { toggle(views[k], k === name); });
    clearErrors();
  }

  function openModal() {
    if (currentUser) { renderProfile(); showView('profile'); } else { showView('login'); }
    modal.classList.add('is-open', 'open', 'active');
    modal.setAttribute('aria-hidden', 'false');
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    modal.classList.remove('is-open', 'open', 'active');
    modal.setAttribute('aria-hidden', 'true');
    modal.style.display = 'none';
    modal.style.visibility = '';
    modal.style.opacity = '';
    modal.style.pointerEvents = '';
    document.body.style.overflow = '';
  }

  /* ---------- ошибки ---------- */
  function setText(id, text) {
    var el = $(id);
    if (el) el.textContent = text || '';
  }
  function clearErrors() {
    ['loginPhoneError', 'loginPasswordError', 'loginFormError',
     'registerNameError', 'registerPhoneError', 'registerPasswordError', 'registerFormError',
     'avatarError', 'avatarSuccess'].forEach(function (id) { setText(id, ''); });
    Array.prototype.forEach.call(modal.querySelectorAll('.input-error'), function (i) {
      i.classList.remove('input-error');
    });
  }
  function markError(inputId, errId, msg) {
    var input = $(inputId);
    if (input) input.classList.add('input-error');
    setText(errId, msg);
  }
  /* сервер отдаёт {field, message} — раскладываем по тем же элементам, что и клиентская валидация */
  var FIELD_MAP = {
    login: { phone: ['loginPhone', 'loginPhoneError'], password: ['loginPassword', 'loginPasswordError'], general: [null, 'loginFormError'] },
    register: { name: ['registerName', 'registerNameError'], phone: ['registerPhone', 'registerPhoneError'], password: ['registerPassword', 'registerPasswordError'], general: [null, 'registerFormError'] }
  };
  function showServerError(form, data) {
    var map = FIELD_MAP[form];
    var target = (map && map[data.field]) || map.general;
    if (target[0]) markError(target[0], target[1], data.message);
    else setText(target[1], data.message);
  }

  /* ---------- аватар ---------- */
  function applyAvatar(src) {
    var hImg = $('headerAvatar'), hIcon = $('headerDefaultIcon');
    var pImg = $('profileAvatar'), pPh = $('profileAvatarPlaceholder');
    if (src) {
      if (hImg) { hImg.src = src; hImg.style.display = 'block'; }
      if (hIcon) hIcon.style.display = 'none';
      if (pImg) { pImg.src = src; pImg.hidden = false; pImg.style.display = 'block'; }
      if (pPh) pPh.style.display = 'none';
    } else {
      if (hImg) { hImg.removeAttribute('src'); hImg.style.display = 'none'; }
      if (hIcon) hIcon.style.display = '';
      if (pImg) { pImg.removeAttribute('src'); pImg.hidden = true; pImg.style.display = 'none'; }
      if (pPh) pPh.style.display = '';
    }
  }

  function ensureAdminLink(show) {
    var link = $('adminLink');
    if (show && !link) {
      var logout = $('logoutButton');
      if (!logout) return;
      link = document.createElement('a');
      link.id = 'adminLink';
      link.href = '/admin';
      link.className = 'btn btn-outline';
      link.style.cssText = 'display:block;text-align:center;margin-bottom:10px;';
      link.textContent = 'Открыть админ-панель';
      logout.parentNode.insertBefore(link, logout);
    }
    if (link) link.style.display = show ? '' : 'none';
  }

  function renderProfile() {
    if (!currentUser) return;
    setText('profileName', currentUser.name || 'Пользователь');
    setText('profilePhone', formatServerPhone(currentUser.phone));
    applyAvatar(currentUser.avatar || '');
    ensureAdminLink(currentUser.role === 'admin');
    loadCabinet();
  }

  /* «Мои заказы» и привязка бота MAX: отдельный файл, подгружается при первом открытии профиля */
  function loadCabinet() {
    if (window.tlCabinet) { window.tlCabinet.refresh(); return; }
    if (document.getElementById('cabinetScript')) return;
    var sc = document.createElement('script');
    sc.id = 'cabinetScript';
    sc.src = 'js/cabinet.js';
    document.body.appendChild(sc);
  }

  function processImage(file, cb) {
    var reader = new FileReader();
    reader.onerror = function () { cb(null); };
    reader.onload = function () {
      var img = new Image();
      img.onerror = function () { cb(null); };
      img.onload = function () {
        var size = 256;
        var side = Math.min(img.width, img.height);
        var sx = (img.width - side) / 2, sy = (img.height - side) / 2;
        var canvas = document.createElement('canvas');
        canvas.width = size; canvas.height = size;
        canvas.getContext('2d').drawImage(img, sx, sy, side, side, 0, 0, size, size);
        canvas.toBlob(function (blob) { cb(blob); }, 'image/jpeg', 0.85);
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  }

  function afterAuth(user) {
    currentUser = user;
    if (user.role === 'admin') { window.location.href = '/admin'; return; }
    renderProfile();
    showView('profile');
  }

  /* ---------- запросы к серверу ---------- */
  function post(url, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); });
  }

  function checkAuth() {
    return fetch('check_auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.success && data.auth) {
          currentUser = data.user;
          applyAvatar(currentUser.avatar || '');
        } else {
          currentUser = null;
          applyAvatar('');
        }
      })
      .catch(function () { currentUser = null; applyAvatar(''); });
  }

  /* ---------- обработчики ---------- */
  openBtn.addEventListener('click', function (e) {
    e.preventDefault();
    openModal();
  });
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('mousedown', function (e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  var showRegister = $('showRegister'), showLogin = $('showLogin');
  if (showRegister) showRegister.addEventListener('click', function () { showView('register'); });
  if (showLogin) showLogin.addEventListener('click', function () { showView('login'); });

  var loginForm = $('loginForm');
  if (loginForm) loginForm.addEventListener('submit', function (e) {
    e.preventDefault();
    clearErrors();
    var phoneDigits = digitsForServer($('loginPhone').value);
    var pass = $('loginPassword').value;
    var remember = $('remember') && $('remember').checked;
    var ok = true;
    if (phoneDigits.length !== 10) { markError('loginPhone', 'loginPhoneError', 'Введите 10 цифр номера после +7'); ok = false; }
    if (!pass) { markError('loginPassword', 'loginPasswordError', 'Введите пароль'); ok = false; }
    if (!ok) return;

    var submitBtn = loginForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    var fd = new FormData();
    fd.append('phone', phoneDigits);
    fd.append('password', pass);
    if (remember) fd.append('remember', '1');

    post('login.php', fd).then(function (data) {
      if (submitBtn) submitBtn.disabled = false;
      if (!data.success) { showServerError('login', data); return; }
      loginForm.reset();
      afterAuth(data.user);
    }).catch(function () {
      if (submitBtn) submitBtn.disabled = false;
      setText('loginFormError', 'Не удалось связаться с сервером');
    });
  });

  var registerForm = $('registerForm');
  if (registerForm) registerForm.addEventListener('submit', function (e) {
    e.preventDefault();
    clearErrors();
    var name = $('registerName').value.trim();
    var phoneDigits = digitsForServer($('registerPhone').value);
    var pass = $('registerPassword').value;
    var ok = true;
    if (name.length < 2) { markError('registerName', 'registerNameError', 'Введите имя'); ok = false; }
    if (phoneDigits.length !== 10) { markError('registerPhone', 'registerPhoneError', 'Введите 10 цифр номера после +7'); ok = false; }
    if (pass.length < 6) { markError('registerPassword', 'registerPasswordError', 'Минимум 6 символов'); ok = false; }
    if (!ok) return;

    var submitBtn = registerForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    var fd = new FormData();
    fd.append('name', name);
    fd.append('phone', phoneDigits);
    fd.append('password', pass);

    post('register.php', fd).then(function (data) {
      if (submitBtn) submitBtn.disabled = false;
      if (!data.success) { showServerError('register', data); return; }
      registerForm.reset();
      afterAuth(data.user);
    }).catch(function () {
      if (submitBtn) submitBtn.disabled = false;
      setText('registerFormError', 'Не удалось связаться с сервером');
    });
  });

  var avatarInput = $('avatarInput');
  if (avatarInput) avatarInput.addEventListener('change', function () {
    setText('avatarError', ''); setText('avatarSuccess', '');
    var file = avatarInput.files && avatarInput.files[0];
    if (!file) return;
    if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
      setText('avatarError', 'Подойдут только JPG, PNG или WebP');
      avatarInput.value = ''; return;
    }
    if (file.size > 5 * 1024 * 1024) {
      setText('avatarError', 'Файл больше 5 МБ');
      avatarInput.value = ''; return;
    }
    processImage(file, function (blob) {
      avatarInput.value = '';
      if (!blob) { setText('avatarError', 'Не удалось прочитать изображение'); return; }

      var fd = new FormData();
      fd.append('avatar', blob, 'avatar.jpg');

      post('upload_avatar.php', fd).then(function (data) {
        if (!data.success) { setText('avatarError', data.message || 'Не удалось сохранить аватар'); return; }
        if (currentUser) currentUser.avatar = data.avatar;
        applyAvatar(data.avatar_url || data.avatar);
        setText('avatarSuccess', 'Аватар обновлён');
      }).catch(function () {
        setText('avatarError', 'Не удалось связаться с сервером');
      });
    });
  });

  var logout = $('logoutButton');
  if (logout) logout.addEventListener('click', function () {
    post('logout.php', new FormData()).then(function () {
      currentUser = null;
      applyAvatar('');
      ensureAdminLink(false);
      showView('login');
    });
  });

  /* ---------- старт: узнаём, авторизован ли пользователь ---------- */
  /* сразу показываем иконку (не ждём ответа сервера) и откатываемся на неё, если файл аватарки не загрузился */
  applyAvatar('');
  [$('headerAvatar'), $('profileAvatar')].forEach(function (img) {
    if (img) img.addEventListener('error', function () { applyAvatar(''); });
  });

  closeModal();
  checkAuth();
})();
