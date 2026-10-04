window._ = require('lodash');
/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */


window.axios = require('axios');

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

window.numAbbr = require('number-abbreviate');

axios.interceptors.response.use(r => r, e => {
    if (e.response && e.response.status == 423) {
      Vue.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.');
    }
    return Promise.reject(e)
})
