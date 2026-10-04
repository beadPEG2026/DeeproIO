import Vue from 'vue';
import axios from 'axios';
import VueI18n from 'vue-i18n';
import VueToast from 'vue-toast-notification';
import 'vue-toast-notification/dist/theme-sugar.css';
import PortalVue from 'portal-vue';
import {createInertiaApp, Link, Head, router} from '@inertiajs/vue2';
import route from 'ziggy';
import {Ziggy} from './ziggy';
import DeeproBrand from './Components/DeeproBrand.vue';
import Login from './Pages/Auth/Login.vue';
import Register from './Pages/Auth/Register.vue';
import LoginAdmin from './Pages/Auth/LoginAdmin.vue';
import ForgotPassword from './Pages/Auth/ForgotPassword.vue';
import ResetPassword from './Pages/Auth/ResetPassword.vue';
import TwoFactorChallenge from './Pages/Auth/TwoFactorChallenge.vue';

window.axios = axios;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;
Vue.use(VueI18n); Vue.use(VueToast); Vue.use(PortalVue);
Vue.component('Link', Link); Vue.component('Head', Head); Vue.component('deepro-brand', DeeproBrand);
Vue.mixin({methods:{route:(name, params, absolute, config=Ziggy)=>route(name,params,absolute,config)}});
window.VueWorker = new Vue();
Object.defineProperty(Vue.prototype, '$worker', {get:()=>window.VueWorker});
const locale = window.LANGUAGE;
const i18n = new VueI18n({locale, silentTranslationWarn:true, messages:{[locale]:JSON.parse(window.TRANSLATIONS)}});
const pages = {'Auth/Login':Login,'Auth/Register':Register,'Auth/LoginAdmin':LoginAdmin,
    'Auth/ForgotPassword':ForgotPassword,'Auth/ResetPassword':ResetPassword,'Auth/TwoFactorChallenge':TwoFactorChallenge};
// Crossing into the exchange loads its own entry and plugins. Never resolve a
// trading/admin page with the authentication-only component registry.
const authPaths = ['/login','/register','/forgot-password','/reset-password/','/two-factor-challenge','/exchange-control-panel/admin-login'];
router.on('before', event => {
    const visit=event.detail.visit, path=new URL(visit.url,location.origin).pathname;
    if (visit.method === 'get' && !authPaths.some(p=>p.endsWith('/') ? path.startsWith(p) : path===p)) {
        event.preventDefault(); location.assign(visit.url);
    }
});
createInertiaApp({
    title:title=>title ? `${title} · Deepro` : 'Deepro',
    resolve:name=>pages[name],
    setup({el,App,props,plugin}){ Vue.use(plugin); window.Vue=new Vue({i18n,render:h=>h(App,props)}).$mount(el); }
});
