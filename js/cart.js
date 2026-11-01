/* cart.js — страница корзины (список, количество, оформление заказа).
   Использует тот же localStorage-ключ, что и main.js. */
(function () {
  'use strict';

  var KEY = 'treelife_cart';
  var root = document.getElementById('cartRoot');
  if (!root) return;

  var PRODUCTS = {
    'treelife-lite':   { img: 'assets/foto2.webp', cat: 'Аппарат для лица' },
    'treelife-beauty': { img: 'assets/foto1.webp', cat: 'Лицо и тело' },
    'treelife-vacuum': { img: 'assets/foto3.webp',  cat: 'Тело и лицо' }
  };

  var FIELDS = [
    { k: 'last_name',   id: 'ordLast',  label: 'Фамилия',  req: true,  max: 60,  ph: 'Иванова', ac: 'family-name' },
    { k: 'first_name',  id: 'ordFirst', label: 'Имя',      req: true,  max: 60,  ph: 'Мария', ac: 'given-name' },
    { k: 'middle_name', id: 'ordMid',   label: 'Отчество', req: false, max: 60,  ph: 'Сергеевна', ac: 'additional-name' },
    { k: 'phone',       id: 'ordPhone', label: 'Телефон',  req: true,  tel: true },
    { k: 'email',       id: 'ordEmail', label: 'Email',    req: true,  max: 100, ph: 'name@example.ru', ac: 'email', type: 'email' },
    { k: 'cdek',        id: 'ordCdek',  label: 'Адрес и пункт СДЭК', req: true, max: 250, ph: 'Город, улица или код пункта выдачи', ac: 'street-address' },
    { k: 'agent',       id: 'ordAgent', label: 'Агент',    req: false, max: 80,  ph: 'Код или имя, если вас направили' }
  ];
  var draft = { last_name: '', first_name: '', middle_name: '', phone: '', email: '', cdek: '', agent: '', comment: '' };
  var done = null;   // номер оформленного заказа

  function read() {
    try { var v = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; }
    catch (e) { return []; }
  }
  function write(items) {
    try { localStorage.setItem(KEY, JSON.stringify(items)); } catch (e) {}
    var el = document.getElementById('cartCount');
    if (el) el.textContent = items.reduce(function (s, i) { return s + i.qty; }, 0);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return Number(n).toLocaleString('ru-RU') + ' ₽'; }
  function plural(n, a, b, c) {
    var m = n % 100, k = n % 10;
    if (m > 10 && m < 15) return c;
    return k === 1 ? a : (k >= 2 && k <= 4 ? b : c);
  }

  /* ---------- телефон ---------- */
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

  /* ---------- иконки ---------- */
  var ICON_CART = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2.4l2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.2a1.5 1.5 0 0 0 1.5-1.1L20.5 8H6.2"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/></svg>';
  var ICON_OK   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  var ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4.5h6V7M6.5 7l.8 12.5h9.4L17.5 7M10 11v5M14 11v5"/></svg>';

  /* ---------- отрисовка ---------- */
  function saveDraft() {
    FIELDS.forEach(function (f) { var el = document.getElementById(f.id); if (el) draft[f.k] = el.value; });
    var c = document.getElementById('ordComment');
    if (c) draft.comment = c.value;
  }

  function render() {
    saveDraft();
    var items = read();

    if (done) {
      root.innerHTML =
        '<div class="cart-state cart-state--ok">' +
          '<div class="cart-state__icon">' + ICON_OK + '</div>' +
          '<h2>Заказ №' + esc(done) + ' принят</h2>' +
          '<p>Спасибо! Мы позвоним вам в ближайшее время, чтобы подтвердить заказ, уточнить доставку и оплату.</p>' +
          '<a href="/catalog" class="btn btn-primary">Вернуться в каталог</a>' +
        '</div>';
      return;
    }

    if (!items.length) {
      root.innerHTML =
        '<div class="cart-state">' +
          '<div class="cart-state__icon">' + ICON_CART + '</div>' +
          '<h2>Корзина пуста</h2>' +
          '<p>Добавьте аппарат из каталога — он появится здесь.</p>' +
          '<a href="/catalog" class="btn btn-primary">Перейти в каталог</a>' +
        '</div>';
      return;
    }

    var count = items.reduce(function (s, i) { return s + i.qty; }, 0);
    var total = items.reduce(function (s, i) { return s + i.price * i.qty; }, 0);

    var rows = items.map(function (it) {
      var meta = PRODUCTS[it.id] || {};
      var img = meta.img ? '<img src="' + esc(meta.img) + '" alt="' + esc(it.name) + '" onerror="this.remove()">' : '';
      return (
        '<article class="cart-item" data-id="' + esc(it.id) + '">' +
          '<div class="cart-item__img">' + img + '</div>' +
          '<div>' +
            '<div class="cart-item__cat">' + esc(meta.cat || 'Аппарат TreeLife') + '</div>' +
            '<div class="cart-item__name"><a href="/' + encodeURIComponent(it.id) + '">' + esc(it.name) + '</a></div>' +
            '<div class="cart-item__unit">' + money(it.price) + ' за шт.</div>' +
          '</div>' +
          '<div class="cart-item__side">' +
            '<div class="qty">' +
              '<button type="button" data-dec aria-label="Меньше">−</button>' +
              '<input type="number" min="1" max="99" value="' + it.qty + '" data-qty aria-label="Количество">' +
              '<button type="button" data-inc aria-label="Больше">+</button>' +
            '</div>' +
            '<div class="cart-item__sum">' + money(it.price * it.qty) + '</div>' +
            '<button type="button" class="cart-remove" data-remove>' + ICON_TRASH + '<span>Удалить</span></button>' +
          '</div>' +
        '</article>'
      );
    }).join('');

    root.innerHTML =
      '<div class="cart-layout">' +
        '<div>' +
          '<div class="cart-items">' + rows + '</div>' +
          '<div class="cart-tools" style="margin-top:16px">' +
            '<a href="/catalog">← Продолжить покупки</a>' +
            '<button type="button" data-clear>Очистить корзину</button>' +
          '</div>' +
        '</div>' +

        '<aside class="cart-summary">' +
          '<h2>Ваш заказ</h2>' +
          '<div class="sum-row"><span>' + count + ' ' + plural(count, 'товар', 'товара', 'товаров') + '</span><span>' + money(total) + '</span></div>' +
          '<div class="sum-row"><span>Доставка</span><span>Уточним по телефону</span></div>' +
          '<div class="sum-row sum-row--total"><span>Итого</span><strong>' + money(total) + '</strong></div>' +
          '<p class="sum-note">Окончательную стоимость подтвердит менеджер.</p>' +

          '<form class="order-form" id="orderForm" novalidate>' +
            fieldsHtml() +
            '<label for="ordComment">Комментарий <span style="color:var(--teal);font-weight:400">(необязательно)</span></label>' +
            '<textarea id="ordComment" maxlength="500" placeholder="Например: интересует обучение онлайн">' + esc(draft.comment) + '</textarea>' +
            '<input type="text" class="hp" id="ordWebsite" tabindex="-1" autocomplete="off" aria-hidden="true">' +
            '<div class="form-err" id="ordFormErr"></div>' +
            '<button type="submit" class="btn btn-primary">Оформить заказ</button>' +
            '<p class="order-agree">Нажимая кнопку, вы соглашаетесь с <a href="/privacy">политикой конфиденциальности</a></p>' +
          '</form>' +

          '<ul class="cart-perks">' +
            '<li>Обучение работе на аппарате — очно или онлайн</li>' +
            '<li>Перезвоним и ответим на все вопросы</li>' +
          '</ul>' +
        '</aside>' +
      '</div>';

    bindForm();
  }

  function fieldsHtml() {
    return FIELDS.map(function (f) {
      var opt = f.req ? '' : ' <span style="color:var(--teal);font-weight:400">(необязательно)</span>';
      var input = f.tel
        ? '<input type="tel" id="' + f.id + '" placeholder="+7 928 330-02-06" autocomplete="tel-national" inputmode="numeric" value="' + esc(draft[f.k]) + '">'
        : '<input type="' + (f.type || 'text') + '" id="' + f.id + '" maxlength="' + f.max + '" placeholder="' + esc(f.ph) + '" autocomplete="' + (f.ac || 'off') + '" value="' + esc(draft[f.k]) + '">';
      return '<label for="' + f.id + '">' + f.label + opt + '</label>' + input + '<div class="field-err" id="' + f.id + 'Err"></div>';
    }).join('');
  }

  function bindForm() {
    var phone = document.getElementById('ordPhone');
    if (phone) {
      phone.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.key && e.key.length === 1 && !/\d/.test(e.key)) e.preventDefault();
      });
      phone.addEventListener('input', function () { phone.value = fmt(digits(phone.value)); });
      phone.addEventListener('focus', function () { if (!phone.value) phone.value = '+7 '; });
      phone.addEventListener('blur', function () { if (!digits(phone.value)) phone.value = ''; });
    }
    var form = document.getElementById('orderForm');
    if (form) form.addEventListener('submit', submit);
  }

  function setErr(inputId, errId, msg) {
    var i = document.getElementById(inputId), e = document.getElementById(errId);
    if (i) i.classList.toggle('input-error', !!msg);
    if (e) e.textContent = msg || '';
  }

  function submit(e) {
    e.preventDefault();
    saveDraft();
    var d = digits(draft.phone), ok = true;
    FIELDS.forEach(function (f) { setErr(f.id, f.id + 'Err', ''); });
    document.getElementById('ordFormErr').textContent = '';

    function bad(f, msg) { setErr(f.id, f.id + 'Err', msg); ok = false; }
    FIELDS.forEach(function (f) {
      var v = String(draft[f.k] || '').trim();
      if (f.tel) { if (d.length !== 10) bad(f, 'Введите 10 цифр номера после +7'); return; }
      if (f.req && !v) { bad(f, 'Заполните поле'); return; }
      if (f.k === 'email' && v && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) bad(f, 'Проверьте email');
      if ((f.k === 'last_name' || f.k === 'first_name') && v.length < 2) bad(f, 'Минимум 2 буквы');
      if (f.k === 'cdek' && v && v.length < 5) bad(f, 'Укажите адрес подробнее');
    });
    if (!ok) return;

    var btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true; btn.textContent = 'Отправляем…';

    var fd = new FormData();
    FIELDS.forEach(function (f) {
      var v = f.tel ? d : String(draft[f.k] || '').trim();
      if (v !== '') fd.append(f.k, v);   // пустые графы не отправляем
    });
    if (draft.comment.trim() !== '') fd.append('comment', draft.comment.trim());
    fd.append('website', document.getElementById('ordWebsite').value);
    fd.append('items', JSON.stringify(read().map(function (i) { return { id: i.id, qty: i.qty }; })));

    fetch('order.php', { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          btn.disabled = false; btn.textContent = 'Оформить заказ';
          var bf = FIELDS.filter(function (f) { return f.k === data.field; })[0];
          if (bf) setErr(bf.id, bf.id + 'Err', data.message);
          else document.getElementById('ordFormErr').textContent = data.message || 'Не удалось оформить заказ';
          return;
        }
        var orderedItems = read();
        var orderTotal = orderedItems.reduce(function (s, i) { return s + i.price * i.qty; }, 0);

        /* цель в Метрике + данные для отчёта по электронной торговле */
        if (window.ym) { ym(92555538, 'reachGoal', 'order_sent', { order_id: data.order_id, total: orderTotal }); }
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
          'ecommerce': {
            'purchase': {
              'actionField': { 'id': String(data.order_id), 'revenue': orderTotal },
              'products': orderedItems.map(function (i) {
                return { 'id': i.id, 'name': i.name, 'price': i.price, 'quantity': i.qty };
              })
            }
          }
        });

        write([]);
        done = data.order_id;
        window.scrollTo({ top: 0, behavior: 'smooth' });
        render();
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Оформить заказ';
        document.getElementById('ordFormErr').textContent = 'Не удалось связаться с сервером';
      });
  }

  /* ---------- действия над списком ---------- */
  function changeQty(id, qty) {
    var items = read();
    items.forEach(function (i) { if (i.id === id) i.qty = Math.min(99, Math.max(1, qty || 1)); });
    write(items); render();
  }

  root.addEventListener('click', function (e) {
    var row = e.target.closest('.cart-item');
    if (row) {
      var id = row.getAttribute('data-id');
      var cur = (read().filter(function (i) { return i.id === id; })[0] || {}).qty || 1;
      if (e.target.closest('[data-inc]')) return changeQty(id, cur + 1);
      if (e.target.closest('[data-dec]')) return changeQty(id, cur - 1);
      if (e.target.closest('[data-remove]')) {
        write(read().filter(function (i) { return i.id !== id; }));
        return render();
      }
    }
    if (e.target.closest('[data-clear]')) { write([]); render(); }
  });

  root.addEventListener('change', function (e) {
    var inp = e.target.closest('[data-qty]');
    if (!inp) return;
    changeQty(inp.closest('.cart-item').getAttribute('data-id'), parseInt(inp.value, 10));
  });

  /* ---------- подставляем имя и телефон вошедшего пользователя ---------- */
  fetch('check_auth.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.auth && data.user) {
        if (!draft.first_name) draft.first_name = data.user.name || '';
        if (!draft.phone) {
          var p = String(data.user.phone || '').replace(/\D/g, '');
          if (p.length === 11 && p[0] === '7') p = p.slice(1);
          if (p.length === 10) draft.phone = fmt(p);
        }
        var n = document.getElementById('ordFirst'), p2 = document.getElementById('ordPhone');
        if (n && !n.value) n.value = draft.first_name;
        if (p2 && !p2.value) p2.value = draft.phone;
      }
    })
    .catch(function () {});

  write(read());   // синхронизируем счётчик в шапке
  window.tlCartRender = render;   // prices.js перерисовывает корзину, если цены в каталоге изменились
  render();
})();
