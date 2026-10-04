import Vue from 'vue';
import {sanitizePreferences, displayCurrency, displayValue, currencyLabel} from '@/Functions/DisplayPreferences.mjs';
const key = 'deepro.displayPreferences';
let saved = {};
try { saved = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (_) {}
const preferences = Vue.observable(sanitizePreferences(saved));
function apply() { document.documentElement.dataset.marketColors = preferences.colors; }
apply();
window.addEventListener('storage', event => {
    if (event.key !== key) return;
    try { Object.assign(preferences, sanitizePreferences(JSON.parse(event.newValue || '{}') || {})); apply(); } catch (_) {}
});
export default {
    data: () => ({displayPreferences: preferences}),
    computed: {
        displayCurrencies() { return this.$page.props.displayCurrencies || [{symbol:'USDT',name:'USDT',rate:1}]; },
        selectedDisplayCurrency() { return displayCurrency(this.displayCurrencies, preferences.currency); }
    },
    methods: {
        currencyLabel(code) {return currencyLabel(code,this.$i18n.locale)},
        displayAmount(value) { return displayValue(value, this.selectedDisplayCurrency, this.$i18n.locale); },
        saveDisplayPreferences(next = preferences) {
            const candidate = sanitizePreferences(next);
            // Chart frames read the persisted value when they receive this event.
            // Leave the active preference unchanged if the browser refuses storage.
            try { localStorage.setItem(key, JSON.stringify(candidate)); } catch (_) { return false; }
            Object.assign(preferences, candidate); apply();
            window.dispatchEvent(new CustomEvent('deepro:display-preferences', {detail:{...preferences}}));
            return true;
        }
    }
};
