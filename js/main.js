/* ==========================================================
   TreeLife Stimulation — общий JS
   Мобильное меню + карусель каталога + корзина на localStorage
   (пока без бэкенда). Когда подключим PHP-сессии, cartAdd/cartCount
   можно переключить на fetch-запросы к /api/cart.php без изменения
   разметки — атрибуты data-add-to-cart остаются те же.
   ========================================================== */

(function () {
  'use strict';

  var CART_KEY = 'treelife_cart';

  /* ---------- Мобильное меню ---------- */
  var navToggle = document.getElementById('navToggle');
  var mainNav = document.getElementById('mainNav');

  if (navToggle && mainNav) {
    navToggle.addEventListener('click', function () {
      var isOpen = mainNav.classList.toggle('is-open');
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }

  /* ---------- Карусель каталога ---------- */
  function initCarousel(root) {
    var track = root.querySelector('[data-carousel-track]');
    var dotsWrap = root.parentElement.querySelector('[data-carousel-dots]');
    if (!track) return;

    var cards = Array.prototype.slice.call(track.children);
    if (cards.length === 0) return;

    /* Точки-индикаторы (видны на мобильных, где карточки листаются свайпом) */
    var dots = [];
    if (dotsWrap) {
      dotsWrap.innerHTML = '';
      cards.forEach(function (_, i) {
        var dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'carousel__dot';
        dot.setAttribute('aria-label', 'Показать аппарат ' + (i + 1));
        dot.addEventListener('click', function () {
          cards[i].scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
        });
        dotsWrap.appendChild(dot);
        dots.push(dot);
      });
    }

    function updateState() {
      if (dots.length) {
        var closestIndex = 0;
        var closestDistance = Infinity;
        cards.forEach(function (card, i) {
          var distance = Math.abs(card.offsetLeft - track.scrollLeft);
          if (distance < closestDistance) {
            closestDistance = distance;
            closestIndex = i;
          }
        });
        dots.forEach(function (dot, i) {
          dot.classList.toggle('is-active', i === closestIndex);
        });
      }
    }

    var ticking = false;
    track.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        updateState();
        ticking = false;
      });
    });

    window.addEventListener('resize', updateState);
    updateState();
  }

  function initAllCarousels() {
    document.querySelectorAll('[data-carousel]').forEach(initCarousel);
  }

  /* ---------- Корзина ---------- */
  function readCart() {
    try {
      var raw = localStorage.getItem(CART_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch (e) {
      return [];
    }
  }

  function writeCart(items) {
    try {
      localStorage.setItem(CART_KEY, JSON.stringify(items));
    } catch (e) {
      /* localStorage недоступен (приватный режим и т.п.) — молча игнорируем */
    }
  }

  function cartCountTotal(items) {
    return items.reduce(function (sum, item) { return sum + item.qty; }, 0);
  }

  function updateCartBadge() {
    var el = document.getElementById('cartCount');
    if (!el) return;
    el.textContent = cartCountTotal(readCart());
  }

  function addToCart(id, name, price) {
    var items = readCart();
    var existing = items.filter(function (i) { return i.id === id; })[0];

    if (existing) {
      existing.qty += 1;
    } else {
      items.push({ id: id, name: name, price: price, qty: 1 });
    }

    writeCart(items);
    updateCartBadge();

    /* Цель Метрики + ecommerce-событие для Директа (умные стратегии, ретаргетинг) */
    try {
      if (window.ym) { ym(92555538, 'reachGoal', 'add_to_cart', { id: id, name: name, price: price }); }
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push({
        'ecommerce': {
          'currencyCode': 'RUB',
          'add': {
            'products': [{ 'id': id, 'name': name, 'price': price, 'quantity': 1 }]
          }
        }
      });
    } catch (e) { /* не мешаем добавлению в корзину, если что-то пошло не так */ }
  }

  function removeFromCart(id) {
    var items = readCart().filter(function (i) { return i.id !== id; });
    writeCart(items);
    updateCartBadge();
  }

  function setQty(id, qty) {
    var items = readCart();
    var item = items.filter(function (i) { return i.id === id; })[0];
    if (!item) return;
    item.qty = Math.max(1, qty);
    writeCart(items);
    updateCartBadge();
  }

  /* Кнопки "В корзину" на любой странице */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-add-to-cart]');
    if (!btn) return;

    addToCart(
      btn.getAttribute('data-id'),
      btn.getAttribute('data-name'),
      parseFloat(btn.getAttribute('data-price'))
    );

    var original = btn.textContent;
    btn.textContent = 'Добавлено';
    btn.disabled = true;
    setTimeout(function () {
      btn.textContent = original;
      btn.disabled = false;
    }, 900);
  });

  /* Отрисовка страницы корзины, если на ней есть #cartPage */
  function renderCartPage() {
    var root = document.getElementById('cartPage');
    if (!root) return;

    var items = readCart();

    if (items.length === 0) {
      root.innerHTML = '<p>Корзина пуста. <a href="/catalog">Перейти в каталог →</a></p>';
      return;
    }

    var rows = items.map(function (item) {
      return (
        '<div class="cart-row" data-row="' + item.id + '">' +
          '<div class="cart-row__name">' + item.name + '</div>' +
          '<input type="number" min="1" value="' + item.qty + '" class="cart-row__qty" data-qty="' + item.id + '">' +
          '<div class="cart-row__price">' + (item.price * item.qty).toLocaleString('ru-RU') + ' ₽</div>' +
          '<button class="cart-row__remove" data-remove="' + item.id + '" aria-label="Удалить">✕</button>' +
        '</div>'
      );
    }).join('');

    var total = items.reduce(function (sum, i) { return sum + i.price * i.qty; }, 0);

    root.innerHTML = rows +
      '<div class="cart-total">Итого: ' + total.toLocaleString('ru-RU') + ' ₽</div>';

    root.querySelectorAll('[data-remove]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        removeFromCart(btn.getAttribute('data-remove'));
        renderCartPage();
      });
    });

    root.querySelectorAll('[data-qty]').forEach(function (input) {
      input.addEventListener('change', function () {
        setQty(input.getAttribute('data-qty'), parseInt(input.value, 10) || 1);
        renderCartPage();
      });
    });
  }

  /* ---------- Шапка: эффект при прокрутке ---------- */
  function initHeaderScroll() {
    var header = document.querySelector('.site-header');
    if (!header) return;

    var ticking = false;

    function update() {
      var y = window.scrollY;
      if (y > 30) header.classList.add('is-scrolled');
      else if (y < 8) header.classList.remove('is-scrolled');
      ticking = false;
    }

    window.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(update);
    }, { passive: true });

    update();
  }

  /* ---------- Появление блоков при скролле ---------- */
  function initScrollReveal() {
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* группы элементов, которые "открываются" по очереди */
    var groupSelectors = [
      '.hero__eyebrow, .hero__text > h1, .hero__description, .hero__actions, .hero__stats',
      '.section__head > *',
      '.product-card',
      '.course-card',
      '.info-card',
      '.trust-item',
      '.review, .review-card, #reviewsRoot',
      '.cat-hero__grid > *, .contact-grid > *, .pd-grid > *, .other-grid > *'
    ];

    var targets = [];

    groupSelectors.forEach(function (sel) {
      var groups = {};
      document.querySelectorAll(sel).forEach(function (el, i) {
        if (el.closest('.js-reveal')) return; // не дублируем вложенные совпадения
        var parentKey = sel; // элементы одного селектора — одна группа задержек
        groups[parentKey] = groups[parentKey] || 0;
        var order = groups[parentKey]++;
        el.classList.add('js-reveal');
        if (el.tagName === 'IMG' || el.classList.contains('hero__figure') || el.classList.contains('product-card__media')) {
          el.classList.add('js-reveal--media');
        }
        el.style.setProperty('--reveal-delay', Math.min(order * 90, 360) + 'ms');
        targets.push(el);
      });
    });

    /* фото в карточках — "оптический" эффект (blur + лёгкий zoom img) */
    document.querySelectorAll('.product-card__media').forEach(function (el) {
      if (!el.classList.contains('js-reveal')) {
        el.classList.add('js-reveal', 'js-reveal--media');
        targets.push(el);
      }
    });

    /* hero-фото уже наклонено и реагирует на курсор (tilt) — трогаем
       только прозрачность/резкость, чтобы не перебивать transform */
    var heroFigure = document.querySelector('.hero__figure');
    if (heroFigure && !heroFigure.classList.contains('js-reveal--fade-only')) {
      heroFigure.classList.add('js-reveal--fade-only');
      targets.push(heroFigure);
    }

    if (reduceMotion || !('IntersectionObserver' in window)) {
      targets.forEach(function (el) {
        el.classList.remove('js-reveal', 'js-reveal--media', 'js-reveal--fade-only');
        el.style.removeProperty('--reveal-delay');
      });
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var el = entry.target;
          el.classList.add('is-inview');
          observer.unobserve(el);

          /* после завершения появления снимаем reveal-классы, чтобы они
             больше не спорили с обычными :hover-эффектами карточек —
             transform/opacity возвращаются под контроль исходных стилей */
          window.setTimeout(function () {
            el.classList.remove('js-reveal', 'js-reveal--media', 'js-reveal--fade-only');
            el.style.removeProperty('--reveal-delay');
          }, 1000);
        }
      });
    }, { threshold: .16, rootMargin: '0px 0px -8% 0px' });

    targets.forEach(function (el) { observer.observe(el); });

    /* карточки отзывов подгружаются динамически — досматриваем их появление */
    var reviewsRoot = document.getElementById('reviewsRoot');
    if (reviewsRoot && 'MutationObserver' in window) {
      var mo = new MutationObserver(function () {
        reviewsRoot.classList.add('is-inview');
        window.setTimeout(function () {
          reviewsRoot.classList.remove('js-reveal', 'js-reveal--media', 'js-reveal--fade-only');
        }, 1000);
        mo.disconnect();
      });
      mo.observe(reviewsRoot, { childList: true });
    }
  }

  /* ---------- Лёгкий тилт hero-фото по курсору (десктоп) ---------- */
  function initHeroTilt() {
    var figure = document.querySelector('.hero__figure');
    if (!figure) return;
    if (!window.matchMedia || !window.matchMedia('(pointer: fine)').matches) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    figure.addEventListener('mousemove', function (e) {
      var rect = figure.getBoundingClientRect();
      var px = (e.clientX - rect.left) / rect.width - .5;
      var py = (e.clientY - rect.top) / rect.height - .5;
      figure.style.setProperty('--tilt-y', (px * 6).toFixed(2) + 'deg');
      figure.style.setProperty('--tilt-x', (py * -6).toFixed(2) + 'deg');
    });

    figure.addEventListener('mouseleave', function () {
      figure.style.setProperty('--tilt-y', '0deg');
      figure.style.setProperty('--tilt-x', '0deg');
    });
  }

  /* ---------- Цели Метрики: клики по WhatsApp/Telegram ---------- */
  function initContactGoals() {
    if (!window.ym) return;
    document.addEventListener('click', function (e) {
      var wa = e.target.closest('.fc-btn--wa');
      var tg = e.target.closest('.fc-btn--tg');
      var tel = e.target.closest('a[href^="tel:"]');
      if (wa) ym(92555538, 'reachGoal', 'click_whatsapp');
      if (tg) ym(92555538, 'reachGoal', 'click_telegram');
      if (tel) ym(92555538, 'reachGoal', 'click_phone');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    updateCartBadge();
    renderCartPage();
    initAllCarousels();
    initHeaderScroll();
    initScrollReveal();
    initHeroTilt();
    initContactGoals();
  });
})();