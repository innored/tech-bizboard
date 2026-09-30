/* =============================================================
   Air Datepicker 초기화
   - [data-datepicker]        → 단일 날짜 (공휴일 표시)
   - [data-datepicker-range]  → 기간
   - [data-datepicker-month]  → 연-월
   holidays.bundle.js → window.KRHolidays
   https://lucy-dev.conbus.co.kr/api/holidays/holidays.bundle.js
   ============================================================= */
(function () {
  'use strict';
  if (typeof AirDatepicker === 'undefined') return;

  var ko = {
    days: ['일요일', '월요일', '화요일', '수요일', '목요일', '금요일', '토요일'],
    daysShort: ['일', '월', '화', '수', '목', '금', '토'],
    daysMin: ['일', '월', '화', '수', '목', '금', '토'],
    months: ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
    monthsShort: ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
    today: '오늘',
    clear: '초기화',
    dateFormat: 'yyyy-MM-dd',
    timeFormat: 'HH:mm',
    firstDay: 0
  };

  function options(extra) {
    return Object.assign({
      locale: ko,
      dateFormat: 'yyyy-MM-dd',
      autoClose: true,
      buttons: ['today', 'clear'],
      navTitles: {
        days: function (dp) { var d = dp.viewDate; return d.getFullYear() + '년 ' + (d.getMonth() + 1) + '월'; },
        months: function (dp) { return dp.viewDate.getFullYear() + '년'; }
      },
      position: 'bottom left',
      container: document.body,
      onRenderCell: function (data) {
        if (data.cellType !== 'day' || typeof window.KRHolidays === 'undefined') return;
        var name = window.KRHolidays.name(data.date);
        if (name) return { classes: '-holiday-', attrs: { title: name } };
      }
    }, extra || {});
  }

  function boot() {
    document.querySelectorAll('[data-datepicker]').forEach(function (el) {
      if (el._adp) return;
      el._adp = new AirDatepicker(el, options());
    });
    document.querySelectorAll('[data-datepicker-range]').forEach(function (el) {
      if (el._adp) return;
      el._adp = new AirDatepicker(el, options({
        range: true,
        multipleDatesSeparator: ' ~ ',
        autoClose: true
      }));
    });
    document.querySelectorAll('[data-datepicker-month]').forEach(function (el) {
      bindMonth(el);
    });
    document.querySelectorAll('[data-datepicker-month-range]').forEach(function (el) {
      bindMonthRange(el);
    });
  }

  function bindDay(el, extra) {
    if (!el || el._adp) return el && el._adp;
    el._adp = new AirDatepicker(el, options(extra || {}));
    return el._adp;
  }

  function bindMonth(el, extra) {
    if (!el || el._adp) return el && el._adp;
    el._adp = new AirDatepicker(el, options(Object.assign({
      view: 'months',
      minView: 'months',
      dateFormat: 'yyyy-MM',
      autoClose: true,
      buttons: [
        {
          content: '이번 달',
          onClick: function (dp) {
            dp.selectDate(new Date());
            dp.hide();
          }
        },
        'clear'
      ]
    }, extra || {})));
    return el._adp;
  }

  function bindMonthRange(el, extra) {
    if (!el || el._adp) return el && el._adp;
    el._adp = new AirDatepicker(el, options(Object.assign({
      view: 'months',
      minView: 'months',
      dateFormat: 'yyyy-MM',
      range: true,
      multipleDatesSeparator: ' ~ ',
      autoClose: true,
      buttons: ['clear']
    }, extra || {})));
    return el._adp;
  }

  window.TechBizBoardDatepicker = {
    init: boot,
    day: bindDay,
    month: bindMonth,
    monthRange: bindMonthRange
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
