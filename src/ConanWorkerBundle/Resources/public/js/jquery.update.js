(function ($) {
  var xhr,
      timeout,
      enabled = false,
      synced = false,
      speed = 1000;

  function update() {
    halt();

    xhr = $.ajax({
      url: window.location.href
    });

    xhr.success(function (data) {
      $('[data-update]').each(function (i) {
        var oldHtml = $(this).html(),
            newHtml = $('[data-update]', data).eq(i).html(),
            oldCount = $('[data-update]').length,
            newCount = $('[data-update]', data).length,
            color = $(this).css('backgroundColor'),
            callback = $(this).attr('data-callback');

        // detect out of sync and reload (but prevent infinite reloading)
        if (oldCount != newCount) {
          if (synced) {
            document.location = document.location;
            synced = false;
          }

          return;
        }

        synced = true; // set state to synced

        if (oldHtml != newHtml) {
          $(this).html(newHtml);

          if ($(this).attr('data-highlight') != 'false') {
            $(this)
                .css({backgroundColor: 'rgb(250,242,201)'})
                .animate({backgroundColor: color}, 'slow');
          }
        }

        if (callback) {
          var fn = window[callback];
          if (typeof fn === 'function') {
            fn($(this));
          }
        }
      });

      schedule();
    });
  }

  function schedule() {
    timeout = setTimeout(update, speed);
  }

  function halt() {
    if (xhr) xhr.abort();
    if (timeout) clearTimeout(timeout);
  }

  $(document).ready(function() {
    $('[data-update]').each(function() {
      if ($(this).attr('data-update') < speed) {
        speed = $(this).attr('data-update');
      }

      enabled = true;
    });

    if (enabled) {
      schedule();
    }
  });

  // page visibility API:

  var hidden, state, visibilityChange;
  if (typeof document.hidden !== "undefined") {
    hidden = "hidden";
    visibilityChange = "visibilitychange";
    state = "visibilityState";
  } else if (typeof document.mozHidden !== "undefined") {
    hidden = "mozHidden";
    visibilityChange = "mozvisibilitychange";
    state = "mozVisibilityState";
  } else if (typeof document.msHidden !== "undefined") {
    hidden = "msHidden";
    visibilityChange = "msvisibilitychange";
    state = "msVisibilityState";
  } else if (typeof document.webkitHidden !== "undefined") {
    hidden = "webkitHidden";
    visibilityChange = "webkitvisibilitychange";
    state = "webkitVisibilityState";
  }

  document.addEventListener(visibilityChange, function() {
    if (document[state] == hidden) {
      halt();
    } else {
      if (enabled) {
        update();
        schedule();
      }
    }
  }, false);
})(jQuery);
