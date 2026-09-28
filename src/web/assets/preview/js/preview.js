(function () {
  'use strict';

  var frames = document.querySelectorAll('[data-preview-frame]');
  var themeControl = document.querySelector('[data-control="theme"]');
  var densityControl = document.querySelector('[data-control="density"]');
  var layoutControl = document.querySelector('[data-control="layout"]');
  var widthControl = document.querySelector('[data-control="width"]');
  var hostControl = document.querySelector('[data-control="host"]');
  var chromeControl = document.querySelector('[data-control="chrome"]');
  var dirControl = document.querySelector('[data-control="dir"]');

  // `width`/`host`/`chrome`/`dir` each have a blank "Scenario default" state
  // (an unchecked box, for `dir`) - set the param for a real value, remove it
  // for blank/unchecked, so a scenario's own `previewDefaults` can take back
  // over once the control is reset.
  function setOrClear(url, name, value) {
    if (value) {
      url.searchParams.set(name, value);
    } else {
      url.searchParams.delete(name);
    }
  }

  function rekeyFrames() {
    frames.forEach(function (frame) {
      var url = new URL(frame.src);
      url.searchParams.set('theme', themeControl.value);
      url.searchParams.set('density', densityControl.value);
      setOrClear(url, 'width', widthControl.value);
      setOrClear(url, 'host', hostControl.value);
      setOrClear(url, 'chrome', chromeControl.value);
      setOrClear(url, 'dir', dirControl.checked ? 'rtl' : '');
      if (url.toString() !== frame.src) {
        frame.src = url.toString();
      }
    });
  }

  function autoHeight(frame) {
    try {
      var observer = new ResizeObserver(function (entries) {
        frame.style.height = Math.ceil(entries[0].contentRect.height) + 'px';
      });
      observer.observe(frame.contentDocument.body);
    } catch {
      // Cross-origin or not-yet-ready document - the frame keeps
      // whatever height it last had rather than erroring the shell.
    }
  }

  frames.forEach(function (frame) {
    frame.addEventListener('load', function () {
      autoHeight(frame);
    });
  });

  themeControl.addEventListener('change', rekeyFrames);
  densityControl.addEventListener('change', rekeyFrames);
  widthControl.addEventListener('change', rekeyFrames);
  hostControl.addEventListener('change', rekeyFrames);
  chromeControl.addEventListener('change', rekeyFrames);
  dirControl.addEventListener('change', rekeyFrames);

  layoutControl.addEventListener('change', function () {
    document.body.classList.toggle(
      'preview-layout--single',
      layoutControl.checked,
    );
  });

  document.querySelectorAll('[data-preview-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var frame = button
        .closest('.preview-frame')
        .querySelector('[data-preview-frame]');
      navigator.clipboard.writeText(frame.src).then(function () {
        var original = button.textContent;
        button.textContent = 'Copied';
        setTimeout(function () {
          button.textContent = original;
        }, 1200);
      });
    });
  });
})();
