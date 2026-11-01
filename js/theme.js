/* TreeLife Stimulation — переключение тёмной темы
   Приоритет: явный выбор пользователя (localStorage) > экономия трафика/энергии
   устройства (Data Saver) > системная тема (prefers-color-scheme) > светлая.
   Мгновенное применение темы до отрисовки страницы делает отдельный
   маленький инлайн-скрипт в <head> каждой страницы — этот файл только
   довешивает переключатель и следит за live-изменениями системной темы. */
(function () {
  "use strict";

  var STORAGE_KEY = "tl-theme";

  function getSystemPrefersDark() {
    try {
      return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
    } catch (e) {
      return false;
    }
  }

  function getSaveDataOn() {
    try {
      var c = navigator.connection || navigator.webkitConnection || navigator.mozConnection;
      return !!(c && c.saveData);
    } catch (e) {
      return false;
    }
  }

  function computeAutoTheme() {
    return (getSystemPrefersDark() || getSaveDataOn()) ? "dark" : "light";
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute("data-theme", theme);
    var btn = document.getElementById("themeToggle");
    if (btn) {
      btn.setAttribute("aria-pressed", theme === "dark" ? "true" : "false");
      btn.setAttribute(
        "aria-label",
        theme === "dark" ? "Включить светлую тему" : "Включить тёмную тему"
      );
    }
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute("content", theme === "dark" ? "#0c1e1e" : "#ffffff");
  }

  function getSavedTheme() {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch (e) {
      return null;
    }
  }

  function saveTheme(theme) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (e) {}
  }

  // на момент выполнения этого файла тема уже выставлена инлайн-скриптом
  // в <head> (чтобы не было "мигания"); здесь просто синхронизируем UI кнопки
  var current = document.documentElement.getAttribute("data-theme") || computeAutoTheme();
  applyTheme(current);

  document.addEventListener("DOMContentLoaded", function () {
    var btn = document.getElementById("themeToggle");
    if (btn) {
      applyTheme(document.documentElement.getAttribute("data-theme") || current);
      btn.addEventListener("click", function () {
        var next = document.documentElement.getAttribute("data-theme") === "dark" ? "light" : "dark";
        applyTheme(next);
        saveTheme(next);
      });
    }
  });

  // если пользователь не выбирал тему вручную — следим за изменением
  // системной темы/режима энергосбережения "вживую"
  try {
    if (window.matchMedia) {
      window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", function () {
        if (!getSavedTheme()) applyTheme(computeAutoTheme());
      });
    }
    var conn = navigator.connection || navigator.webkitConnection || navigator.mozConnection;
    if (conn && conn.addEventListener) {
      conn.addEventListener("change", function () {
        if (!getSavedTheme()) applyTheme(computeAutoTheme());
      });
    }
  } catch (e) {}
})();
