(function () {
  'use strict';

  var messages = window.dendrilaScheduleAdmin || {};
  var defaults = {
    exceptionTitle: 'Date exception %d',
    rangeAdded: 'Time range added.',
    rangeRemoved: 'Time range removed.',
    dayClosed: 'No time ranges remain. This day is closed.',
    dateAdded: 'Date exception added. Choose a date.',
    dateRemoved: 'Date exception removed.'
  };

  function message(key) {
    return messages[key] || defaults[key] || '';
  }

  function revealControls(container) {
    container.querySelectorAll('[data-schedule-js]').forEach(function (control) {
      control.hidden = false;
    });
  }

  function updateGroup(group) {
    var closed = group.querySelector('[data-schedule-closed]');
    var ranges = group.querySelector('[data-time-ranges]');
    if (!closed || !ranges) return;
    ranges.hidden = closed.checked;
    ranges.querySelectorAll('input[type="time"]').forEach(function (input) {
      input.disabled = closed.checked;
      input.required = !closed.checked;
    });
    group.classList.toggle('is-closed', closed.checked);
  }

  function configureRanges(group, name, prefix) {
    group.querySelectorAll('[data-time-range]').forEach(function (range, index) {
      ['start', 'end'].forEach(function (part) {
        var input = range.querySelector('[data-range-' + part + ']');
        var label = range.querySelector('[data-range-label="' + part + '"]');
        var id = prefix + '-range-' + index + '-' + part;
        input.id = id;
        input.name = name + '[' + index + '][' + part + ']';
        label.htmlFor = id;
      });
    });
    revealControls(group);
    updateGroup(group);
  }

  function renumber(editor) {
    editor.querySelectorAll('[data-schedule-group="day"]').forEach(function (group) {
      var day = group.dataset.day;
      configureRanges(group, 'dendrila_schedule_profile[weekly][' + day + ']', 'dendrila-profile-day-' + day);
    });
    editor.querySelectorAll('[data-exceptions] > [data-schedule-group="exception"]').forEach(function (group, index) {
      var name = 'dendrila_schedule_profile[exceptions][' + index + ']';
      var prefix = 'dendrila-profile-exception-' + index;
      var date = group.querySelector('[data-exception-date]');
      var closed = group.querySelector('[data-schedule-closed]');
      group.querySelector('[data-exception-title]').textContent = message('exceptionTitle').replace('%d', String(index + 1));
      date.id = prefix + '-date';
      date.name = name + '[date]';
      date.required = true;
      group.querySelector('[data-exception-date-label]').htmlFor = date.id;
      closed.id = prefix + '-closed';
      closed.name = name + '[closed]';
      closed.closest('label').htmlFor = closed.id;
      group.querySelector('[data-closed-value]').name = closed.name;
      configureRanges(group, name + '[slots]', prefix);
    });
  }

  function announce(editor, key) {
    var status = editor.querySelector('[data-schedule-status]');
    if (status) status.textContent = message(key);
  }

  function newRange(editor) {
    var template = editor.querySelector('[data-range-template]');
    return template.content.cloneNode(true).firstElementChild;
  }

  function initialize(editor) {
    if (editor.dataset.scheduleInitialized === '1') return;
    editor.dataset.scheduleInitialized = '1';
    editor.classList.add('has-schedule-js');
    revealControls(editor);
    renumber(editor);

    editor.addEventListener('change', function (event) {
      if (!event.target.matches('[data-schedule-closed]')) return;
      var group = event.target.closest('[data-schedule-group]');
      updateGroup(group);
      if (!event.target.checked) {
        var first = group.querySelector('[data-range-start]');
        if (first) first.focus();
      }
    });

    editor.addEventListener('click', function (event) {
      var control = event.target.closest('button, [data-select-shortcode]');
      if (!control || !editor.contains(control)) return;
      if (control.matches('[data-select-shortcode]')) {
        control.select();
        return;
      }

      if (control.matches('[data-add-exception]')) {
        var template = editor.querySelector('[data-exception-template]');
        var exception = template.content.cloneNode(true).firstElementChild;
        editor.querySelector('[data-exceptions]').appendChild(exception);
        renumber(editor);
        exception.querySelector('[data-exception-date]').focus();
        announce(editor, 'dateAdded');
        return;
      }

      var group = control.closest('[data-schedule-group]');
      if (!group) return;
      if (control.matches('[data-remove-exception]')) {
        var next = group.nextElementSibling || group.previousElementSibling;
        group.remove();
        renumber(editor);
        var date = next && next.querySelector('[data-exception-date]');
        (date || editor.querySelector('[data-add-exception]')).focus();
        announce(editor, 'dateRemoved');
        return;
      }

      var container = group.querySelector('[data-time-ranges]');
      var closed = group.querySelector('[data-schedule-closed]');
      if (control.matches('[data-add-range]')) {
        var empty = Array.from(container.querySelectorAll('[data-time-range]')).find(function (range) {
          return !range.querySelector('[data-range-start]').value && !range.querySelector('[data-range-end]').value;
        });
        var range = empty || newRange(editor);
        if (!empty) container.appendChild(range);
        closed.checked = false;
        renumber(editor);
        range.querySelector('[data-range-start]').focus();
        announce(editor, 'rangeAdded');
      } else if (control.matches('[data-remove-range]')) {
        var removed = control.closest('[data-time-range]');
        var nextRange = removed.nextElementSibling || removed.previousElementSibling;
        removed.remove();
        if (!container.querySelector('[data-time-range]')) {
          container.appendChild(newRange(editor));
          closed.checked = true;
          renumber(editor);
          closed.focus();
          announce(editor, 'dayClosed');
        } else {
          renumber(editor);
          if (nextRange) nextRange.querySelector('[data-range-start]').focus();
          announce(editor, 'rangeRemoved');
        }
      }
    });
  }

  function start() {
    document.querySelectorAll('[data-dendrila-schedule]').forEach(initialize);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
