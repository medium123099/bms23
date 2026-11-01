(function () {
  "use strict";

  var root = document.getElementById("heroSlideshow");
  if (!root) return;

  var slides = Array.prototype.slice.call(root.querySelectorAll(".hero-slideshow__slide"));
  var dots   = Array.prototype.slice.call(root.querySelectorAll("[data-dot]"));
  var count  = document.getElementById("heroCountCurrent");
  var prevBtn = document.getElementById("heroPrev");
  var nextBtn = document.getElementById("heroNext");

  if (slides.length < 2) return;

  var current = 0;
  var DURATION = 6000;
  var timer = null;
  var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  function show(index) {
    index = (index + slides.length) % slides.length;
    slides[current].classList.remove("is-active");
    if (dots[current]) dots[current].classList.remove("is-active");

    current = index;

    slides[current].classList.add("is-active");
    if (dots[current]) dots[current].classList.add("is-active");
    if (count) count.textContent = String(current + 1).padStart(2, "0");
  }

  function next() { show(current + 1); }
  function prev() { show(current - 1); }

  function start() {
    if (reduceMotion) return;
    stop();
    timer = window.setInterval(next, DURATION);
  }
  function stop() {
    if (timer) { window.clearInterval(timer); timer = null; }
  }

  dots.forEach(function (dot, i) {
    dot.addEventListener("click", function () {
      show(i);
      start();
    });
  });

  if (nextBtn) nextBtn.addEventListener("click", function () { next(); start(); });
  if (prevBtn) prevBtn.addEventListener("click", function () { prev(); start(); });

  root.addEventListener("mouseenter", stop);
  root.addEventListener("mouseleave", start);

  document.addEventListener("visibilitychange", function () {
    if (document.hidden) stop(); else start();
  });

  // свайп на мобильных
  var touchStartX = null;
  root.addEventListener("touchstart", function (e) {
    touchStartX = e.changedTouches[0].clientX;
  }, { passive: true });
  root.addEventListener("touchend", function (e) {
    if (touchStartX === null) return;
    var dx = e.changedTouches[0].clientX - touchStartX;
    if (Math.abs(dx) > 40) { dx < 0 ? next() : prev(); start(); }
    touchStartX = null;
  }, { passive: true });

  start();
})();
