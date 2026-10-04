import english from './UmiEnglish.json';

// Use the current site locale; keep UMI business codes and historical source data unchanged.
export default {
    beforeCreate() {
        if (!this.$i18n) return;
        const locale = this.$i18n.locale;
        if (/^en(?:[-_]|$)/i.test(locale)) this.$i18n.mergeLocaleMessage(locale, english);
    },
};
