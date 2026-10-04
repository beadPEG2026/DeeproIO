import {resolvePage, warmNavigation} from './Functions/PageResolver';
import { legacyText } from '@/Functions/LegacyTranslation';
import DeeproBrand from './Components/DeeproBrand.vue';
import UmiIcon from './Components/UmiIcon.vue';
import { loadLanguage } from "./Functions/Language";

require('./bootstrap');

// Import modules...
import Vue from 'vue';
import DeeproUi from './Config/DeeproUi';
import CurrencyAvatar from './Components/CurrencyAvatar.vue';
Vue.component('currency-avatar', CurrencyAvatar);
import ThemedSelect from './Components/ThemedSelect.vue';
import NetworkIcon from './Components/NetworkIcon.vue';
Vue.component('themed-select', ThemedSelect);
Vue.component('network-icon', NetworkIcon);
Vue.prototype.$uiFeatures = DeeproUi;
Vue.component('umi-icon', UmiIcon);
Vue.component('deepro-brand', DeeproBrand);
import VueTailwind from 'vue-tailwind'
import VueClipboard from 'vue-clipboard2'
import {copyText} from '@/Functions/Clipboard.mjs';
import VueToast from 'vue-toast-notification'
import VueI18n from 'vue-i18n'

import { createInertiaApp, router } from '@inertiajs/vue2'

import PortalVue from 'portal-vue';
import VueFileAgent from 'vue-file-agent';
import VueFileAgentStyles from 'vue-file-agent/dist/vue-file-agent.css';
import VueCookies from 'vue-cookies'
import vuescroll from 'vuescroll';

import LoadScript from 'vue-plugin-load-script';
import {vueComponentSettings} from "./components";
import ProgressBar from 'vue-simple-progress'
import HeaderCaption from '@/Components/HeaderCaption'
import { Link, Head } from '@inertiajs/vue2'
import VTooltip from 'v-tooltip'

require('./icons');
// Register components

// Ziggy start here
import route from 'ziggy';
import moment from 'moment-mini-ts'
import { Ziggy } from './ziggy';

Vue.mixin({
    methods: {
        route: (name, params, absolute, config = Ziggy) => route(name, params, absolute, config),
    },
});
// ziggy end here

//Vue.use(InertiaPlugin);

Vue.use(PortalVue);
Vue.use(VueToast);
Vue.use(VTooltip)
Vue.use(VueFileAgent);
Vue.use(VueCookies);
Vue.use(VueClipboard);
Vue.prototype.$copyText = copyText;
Vue.use(VueI18n);
Vue.use(VTooltip);
Vue.use(LoadScript);

Vue.component('progress-bar', ProgressBar)
Vue.component('header-caption', HeaderCaption)
Vue.component('Link', Link)
Vue.component('Head', Head)

Vue.use(vuescroll, {
    ops: {
        vuescroll: {},
        scrollPanel: {},
        rail: {
            'opacity': 1,
        },
        bar: {
            'keepShow': true,
            'opacity': 0.2
        }
    },
});

Vue.use(VueTailwind, vueComponentSettings)

const app = document.getElementById('app');

import store from './Store'

import MathMixin from '@/Mixins/Math/MathMixin';

let defaultLanguage = document.querySelector('meta[name="site-language"]').getAttribute('content');
let messages = [];

messages[defaultLanguage] = JSON.parse(window.TRANSLATIONS);

let i18n = new VueI18n({
    locale: defaultLanguage,
    silentTranslationWarn: true,
    messages: messages
})

window.VueWorker = new Vue();

Object.defineProperties(Vue.prototype, {
    $worker: { get: () => { return VueWorker } }
})

window.moment = moment;
window.numeral = require('numeral');

const App = createInertiaApp({
    title: title => !title ? legacyText("Deepro") : /Deepro/i.test(title) ? title : `${title} · Deepro`,
    resolve: resolvePage,
    setup({ el, App, props, plugin }) {
        Vue.use(plugin)

        window.Vue = new Vue({
            i18n: i18n,
            mixins: [MathMixin],
            store,
            render: h => h(App, props),
        }).$mount(el)
    },
});

warmNavigation();

// Global Inertia error handler - shows toast instead of modal error page
router.on('exception', (event) => {
    event.preventDefault();

    // Show user-friendly error toast
    if (window.Vue && window.Vue.$toast) {
        window.Vue.$toast.error(i18n.t('A system error occurred. Please try again later.'), {
            duration: 5000,
            position: 'bottom-right',
        });
    }

    console.error('Inertia request exception');
});

// Handle invalid responses (500 errors, etc.)
router.on('invalid', (event) => {
    event.preventDefault();

    if (window.Vue && window.Vue.$toast) {
        window.Vue.$toast.error(i18n.t('A system error occurred. Please try again later.'), {
            duration: 5000,
            position: 'bottom-right',
        });
    }

    console.error('Inertia invalid response', {status: Number(event.detail.response && event.detail.response.status) || 0});
});

// Handle error responses (4xx, 5xx)
router.on('error', (event) => {
    // Don't prevent default for validation errors (422) - those should be handled by the form
    const errors = event.detail.errors;

    // Check if it's a server error (not validation)
    if (errors && typeof errors === 'object' && Object.keys(errors).length === 0) {
        if (window.Vue && window.Vue.$toast) {
            window.Vue.$toast.error(i18n.t('An error occurred while processing your request.'), {
                duration: 5000,
                position: 'bottom-right',
            });
        }
    }
});
