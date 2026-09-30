/* =============================================================
   LinkBuilder — 브랜드 팔레트 전환 (미리보기용)
   프리셋 칩 + 컬러피커(Primary → --brand-* 스케일 자동 생성)
   ============================================================= */
(function () {
  'use strict';

  var STORAGE_KEY = 'lb-palette';
  var DEFAULT_ID = 'blue';
  var CUSTOM_ID = 'custom';

  var PALETTES = [
    { id: 'blue',   label: '신뢰 블루',     dot: '#2563eb' },
    { id: 'navy',   label: '엔터프라이즈',  dot: '#1e40af' },
    { id: 'teal',   label: '프로페셔널',    dot: '#0d9488' },
    { id: 'sky',    label: '클린 스카이',   dot: '#0284c7' },
    { id: 'indigo', label: 'B2B 인디고',    dot: '#4f46e5' }
  ];

  var BRAND_STEPS = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];

  var LIGHTNESS = {
    50: 97, 100: 94, 200: 86, 300: 74, 400: 62, 500: 53,
    700: null, 800: null, 900: null, 950: null
  };

  var SAT_MULT = {
    50: 0.35, 100: 0.45, 200: 0.55, 300: 0.65, 400: 0.78, 500: 0.88,
    600: 1, 700: 1, 800: 0.98, 900: 0.95, 950: 0.92
  };

  var DARK_OFFSET = { 700: 7, 800: 14, 900: 21, 950: 28 };

  var state = { mode: 'preset', id: DEFAULT_ID, primary: '#2563eb' };

  /* ---------- 색상 유틸 ---------- */
  function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
  }

  function normalizeHex(hex) {
    if (!hex) return '';
    hex = String(hex).trim();
    if (hex.charAt(0) !== '#') hex = '#' + hex;
    if (/^#[0-9a-fA-F]{3}$/.test(hex)) {
      hex = '#' + hex.charAt(1) + hex.charAt(1) + hex.charAt(2) + hex.charAt(2) + hex.charAt(3) + hex.charAt(3);
    }
    return /^#[0-9a-fA-F]{6}$/.test(hex) ? hex.toUpperCase() : '';
  }

  function hexToRgb(hex) {
    hex = normalizeHex(hex);
    if (!hex) return null;
    return {
      r: parseInt(hex.slice(1, 3), 16),
      g: parseInt(hex.slice(3, 5), 16),
      b: parseInt(hex.slice(5, 7), 16)
    };
  }

  function rgbToHex(r, g, b) {
    function h(n) {
      var x = Math.round(clamp(n, 0, 255)).toString(16);
      return x.length === 1 ? '0' + x : x;
    }
    return ('#' + h(r) + h(g) + h(b)).toUpperCase();
  }

  function rgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b);
    var h = 0, s = 0, l = (max + min) / 2;
    if (max !== min) {
      var d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
      else if (max === g) h = (b - r) / d + 2;
      else h = (r - g) / d + 4;
      h /= 6;
    }
    return { h: h * 360, s: s * 100, l: l * 100 };
  }

  function hslToRgb(h, s, l) {
    h = ((h % 360) + 360) % 360;
    s = clamp(s, 0, 100) / 100;
    l = clamp(l, 0, 100) / 100;
    if (s === 0) {
      var g = Math.round(l * 255);
      return { r: g, g: g, b: g };
    }
    var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
    var p = 2 * l - q;
    function hue2rgb(t) {
      if (t < 0) t += 1;
      if (t > 1) t -= 1;
      if (t < 1 / 6) return p + (q - p) * 6 * t;
      if (t < 1 / 2) return q;
      if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
      return p;
    }
    return {
      r: Math.round(hue2rgb(h / 360 + 1 / 3) * 255),
      g: Math.round(hue2rgb(h / 360) * 255),
      b: Math.round(hue2rgb(h / 360 - 1 / 3) * 255)
    };
  }

  function hslToHex(h, s, l) {
    var rgb = hslToRgb(h, s, l);
    return rgbToHex(rgb.r, rgb.g, rgb.b);
  }

  function hexToHsl(hex) {
    var rgb = hexToRgb(hex);
    return rgb ? rgbToHsl(rgb.r, rgb.g, rgb.b) : null;
  }

  /** Primary(600) 기준 brand 11단계 스케일 생성 */
  function generateBrandScale(primaryHex) {
    primaryHex = normalizeHex(primaryHex);
    var hsl = hexToHsl(primaryHex);
    if (!hsl) return null;

    var scale = {};
    var h = hsl.h;
    var s = hsl.s;
    var l600 = hsl.l;

    BRAND_STEPS.forEach(function (step) {
      if (step === '600') {
        scale[step] = primaryHex;
        return;
      }
      var sl;
      if (LIGHTNESS[step] != null) {
        sl = LIGHTNESS[step];
      } else {
        sl = l600 - DARK_OFFSET[step];
      }
      sl = clamp(sl, 4, 98);
      var ss = clamp(s * SAT_MULT[step], 8, 100);
      scale[step] = hslToHex(h, ss, sl);
    });
    return scale;
  }

  function applyBrandScale(scale) {
    var root = document.documentElement;
    BRAND_STEPS.forEach(function (step) {
      root.style.setProperty('--brand-' + step, scale[step]);
    });
  }

  function clearBrandInlineStyles() {
    var root = document.documentElement;
    BRAND_STEPS.forEach(function (step) {
      root.style.removeProperty('--brand-' + step);
    });
  }

  function resolveId(id) {
    if (!id) return DEFAULT_ID;
    return PALETTES.some(function (p) { return p.id === id; }) ? id : DEFAULT_ID;
  }

  function saveState() {
    try {
      if (state.mode === 'custom') {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
          mode: 'custom',
          primary: state.primary,
          scale: generateBrandScale(state.primary)
        }));
      } else {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ mode: 'preset', id: state.id }));
      }
    } catch (e) { /* private mode 등 */ }
  }

  function loadState() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return { mode: 'preset', id: DEFAULT_ID };

      if (raw.charAt(0) === '{') {
        var data = JSON.parse(raw);
        if (data.mode === 'custom' && data.primary) {
          return { mode: 'custom', primary: normalizeHex(data.primary) || '#2563EB', scale: data.scale };
        }
        if (data.mode === 'preset') {
          return { mode: 'preset', id: resolveId(data.id) };
        }
      }
      return { mode: 'preset', id: resolveId(raw) };
    } catch (e) {
      return { mode: 'preset', id: DEFAULT_ID };
    }
  }

  function applyPreset(id) {
    id = resolveId(id);
    state = { mode: 'preset', id: id, primary: state.primary };

    clearBrandInlineStyles();
    var root = document.documentElement;
    if (id === DEFAULT_ID) root.removeAttribute('data-palette');
    else root.setAttribute('data-palette', id);

    saveState();
    syncUi();
    updateSwatchLabels();
  }

  function applyCustom(primaryHex) {
    primaryHex = normalizeHex(primaryHex);
    if (!primaryHex) return;

    var scale = generateBrandScale(primaryHex);
    if (!scale) return;

    state = { mode: 'custom', id: CUSTOM_ID, primary: primaryHex };

    var root = document.documentElement;
    root.removeAttribute('data-palette');
    applyBrandScale(scale);

    saveState();
    syncUi();
    updateSwatchLabels();
  }

  function syncUi() {
    var isCustom = state.mode === 'custom';

    document.querySelectorAll('[data-palette-btn]').forEach(function (btn) {
      var on = !isCustom && btn.getAttribute('data-palette-btn') === state.id;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    var customWrap = document.getElementById('palette-custom');
    if (customWrap) customWrap.classList.toggle('is-active', isCustom);

    var colorInput = document.getElementById('palette-color-input');
    var hexInput = document.getElementById('palette-hex-input');
    var primary = isCustom ? state.primary : hexFromComputed('--brand-600');
    if (colorInput && primary) colorInput.value = primary.toLowerCase();
    if (hexInput && primary) hexInput.value = primary;

    document.querySelectorAll('.js-palette-active-name').forEach(function (el) {
      if (isCustom) {
        el.textContent = 'Custom · ' + state.primary;
      } else {
        var p = PALETTES.filter(function (x) { return x.id === state.id; })[0];
        el.textContent = p ? p.label : state.id;
      }
    });
  }

  function hexFromComputed(prop) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(prop).trim();
    if (!v) return '';
    if (v.charAt(0) === '#') return v.toUpperCase();
    var m = v.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/);
    if (!m) return v;
    return rgbToHex(Number(m[1]), Number(m[2]), Number(m[3]));
  }

  function updateSwatchLabels() {
    var primaryEl = document.getElementById('brand-primary-token');
    if (primaryEl) {
      primaryEl.textContent = '--brand-600 (' + hexFromComputed('--brand-600') + ')';
    }
    BRAND_STEPS.forEach(function (step) {
      var el = document.getElementById('brand-hex-' + step);
      if (el) el.textContent = hexFromComputed('--brand-' + step);
    });
  }

  function buildPresetButtons(container) {
    if (!container) return;
    container.setAttribute('role', 'group');
    container.setAttribute('aria-label', '프리셋 팔레트');

    PALETTES.forEach(function (p) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'palette-btn';
      btn.setAttribute('data-palette-btn', p.id);
      btn.setAttribute('aria-pressed', 'false');
      btn.setAttribute('title', p.label);
      btn.innerHTML =
        '<span class="palette-dot" style="background:' + p.dot + '"></span>' +
        '<span class="palette-name">' + p.label + '</span>';
      btn.addEventListener('click', function () { applyPreset(p.id); });
      container.appendChild(btn);
    });
  }

  function bindCustomPicker() {
    var colorInput = document.getElementById('palette-color-input');
    var hexInput = document.getElementById('palette-hex-input');
    if (!colorInput || !hexInput) return;

    colorInput.addEventListener('input', function () {
      applyCustom(colorInput.value);
    });

    hexInput.addEventListener('change', function () {
      var hex = normalizeHex(hexInput.value);
      if (!hex) {
        hexInput.value = state.mode === 'custom' ? state.primary : hexFromComputed('--brand-600');
        return;
      }
      applyCustom(hex);
    });

    hexInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') hexInput.blur();
    });
  }

  function restore(saved) {
    if (saved.mode === 'custom') {
      state = { mode: 'custom', id: CUSTOM_ID, primary: saved.primary };
      document.documentElement.removeAttribute('data-palette');
      if (saved.scale) applyBrandScale(saved.scale);
      else applyCustom(saved.primary);
      return;
    }
    state = { mode: 'preset', id: saved.id, primary: '#2563EB' };
    applyPreset(saved.id);
  }

  function init() {
    restore(loadState());
    buildPresetButtons(document.getElementById('palette-switcher'));
    bindCustomPicker();
    syncUi();
    updateSwatchLabels();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.LinkBuilderPalette = {
    apply: applyPreset,
    applyCustom: applyCustom,
    generateScale: generateBrandScale,
    list: PALETTES
  };
})();
