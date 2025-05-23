import _ from "lodash";
window._ = _;

import jQuery from "jquery";
import * as bootstrap from "bootstrap";
import * as Popper from "popper.js";
// import "~bootstrap/dist/js/bootstrap.bundle.min.js"; // dropdown doesn't work
import "@fortawesome/fontawesome-free/css/all.min.css";

import "~hideshowpassword/hideShowPassword.js";
import "~password-strength-meter/password.js";
import "~selectize/dist/js/selectize.min.js";
import "~selectize/dist/css/selectize.bootstrap5.css";
import "~jquery-mask/dist/jquery.mask.min.js";
import DataTable from "~datatables/js/dataTables.bootstrap5.min.js";
import "~datatables/css/dataTables.bootstrap5.min.css";
import "~jquery-bootstrap5-toggle/bootstrap5-toggle.jquery.js";
import "~bootstrap5-toggle/css/bootstrap5-toggle.min.css";
import "~lightweight-charts/dist/lightweight-charts.standalone.production.js";

window.$ = jQuery;
window.jQuery = window.$ = jQuery;
// window.Popper = Popper; // jquery-tempus-dominus/jQuery-provider doesn't work

window.DataTable = DataTable;

import { TempusDominus } from "~tempus-dominus/dist/js/tempus-dominus.js";
import "~jquery-tempus-dominus/jQuery-provider.js";
import "~tempus-dominus/dist/css/tempus-dominus.css";

window.TempusDominus = TempusDominus;

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */
import axios from "axios";
window.axios = axios;
window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
window.axios.defaults.withCredentials = true;

const token = document.head.querySelector('meta[name="csrf-token"]');

if (token) {
  window.axios.defaults.headers.common["X-CSRF-TOKEN"] = token.content;
} else {
  console.error(
    "CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token",
  );
}

import Alpine from "alpinejs";
window.Alpine = Alpine;
Alpine.start();

function defineJQueryPlugin(plugin) {
  const name = plugin.NAME;
  const JQUERY_NO_CONFLICT = $.fn[name];
  $.fn[name] = plugin.jQueryInterface;
  $.fn[name].Constructor = plugin;
  $.fn[name].noConflict = () => {
    $.fn[name] = JQUERY_NO_CONFLICT;
    return plugin.jQueryInterface;
  };
}

defineJQueryPlugin(bootstrap.Tooltip);

$.fn.extend({
  toggleText: function (a, b) {
    return this.text(this.text() == b ? a : b);
  },

  /**
   * Remove element classes with wildcard matching. Optionally add classes:
   *     $( '#foo' ).alterClass( 'foo-* bar-*', 'foobar' )
   *
   */
  alterClass: function (removals, additions) {
    var self = this;

    if (removals.indexOf("*") === -1) {
      // Use native jQuery methods if there is no wildcard matching
      self.removeClass(removals);
      return !additions ? self : self.addClass(additions);
    }

    var patt = new RegExp(
      "\\s" +
        removals.replace(/\*/g, "[A-Za-z0-9-_]+").split(" ").join("\\s|\\s") +
        "\\s",
      "g",
    );

    self.each(function (i, it) {
      var cn = " " + it.className + " ";
      while (patt.test(cn)) {
        cn = cn.replace(patt, " ");
      }
      it.className = $.trim(cn);
    });

    return !additions ? self : self.addClass(additions);
  }, // end alterClass
}); // end $.fn.extend

/*
// Not needed
// import Echo from "laravel-echo";
window.Echo = new Echo({
    broadcaster: "pusher",
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? "mt1",
    wsHost: import.meta.env.VITE_PUSHER_HOST
        ? import.meta.env.VITE_PUSHER_HOST
        : `ws-${import.meta.env.VITE_PUSHER_APP_CLUSTER}.pusher.com`,
    wsPort: import.meta.env.VITE_PUSHER_PORT ?? 80,
    wssPort: import.meta.env.VITE_PUSHER_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? "https") === "https",
    enabledTransports: ["ws", "wss"],
});

// import Pusher from "pusher-js";
// window.Pusher = Pusher;
*/
