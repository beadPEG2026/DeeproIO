<script>
import {firstStakingPeriod, publishedDownloadUrl} from '@/Functions/UserDisplay.mjs';
import Template from '{Template}/Web/Pages/Home/Home.template'
import AppLayout from '@/Layouts/AppLayout'
import MarketChannel from "@/Store/Channels/Public/Market/MarketChannel";
import MarketOverview from '@/Components/MarketOverview'
import QRCode from 'qrcode'
import HomeDashboard from '@/Components/HomeDashboard.vue'

export default Template({
    components: {
        HomeDashboard,
        AppLayout,
        MarketChannel,
        MarketOverview
    },

    data() {
        return {
            numAbbr: '',
            hoverDownloadQr: '',
            activeDownloadQr: '',
            canInstallPwa: false,
            deferredPrompt: null,
            showPopupAnnouncement: false,
            popupBodyOverflow: null,
        }
    },

    props: {
        articles: Object,
        banners: { type: Array, default: () => [] },
        popupArticle: {
            type: Object,
            default: null,
        },
        stakings: Object,
        quantifies: Object,
        launchpads: Object,
        marketCount: Number,

        androidDownloadUrl: {
            type: String,
            default: '',
        },

        iosDownloadUrl: {
            type: String,
            default: '',
        },
    },

    methods: {
        firstStakingPeriod,
        closePopupAnnouncement() {
            this.showPopupAnnouncement = false;
            this.unlockPopupBody();
        },

        handlePopupEscape(event) {
            if (event.key === 'Escape' && this.showPopupAnnouncement) this.closePopupAnnouncement();
        },

        openPopupAnnouncementIfNeeded() {
            if (!this.popupArticle || this.popupArticle.id === undefined) return;
            // A popup is shown on every homepage entry. Closing it only
            // dismisses the current page view; revisiting the homepage
            // mounts the page again and opens the popup anew.
            this.showPopupAnnouncement = true;
            this.lockPopupBody();
        },

        lockPopupBody() {
            if (typeof document === 'undefined' || !document.body || this.popupBodyOverflow !== null) {
                return;
            }

            this.popupBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        },

        unlockPopupBody() {
            if (typeof document === 'undefined' || !document.body || this.popupBodyOverflow === null) {
                return;
            }

            document.body.style.overflow = this.popupBodyOverflow;
            this.popupBodyOverflow = null;
        },

        setStaking(staking, stakingType = 0) {
            this.$inertia.visit(this.route('staking', {
                staking: staking.id,
                staking_type: stakingType
            }));
        },

        setLaunchpad(launchpad) {
            this.$inertia.visit(this.route('launchpad', launchpad.id));
        },

        setArticle(article) {
            this.$inertia.visit(this.route('article', article.id));
        },

        formatNumber(volume, digits) {
            return this.numAbbr ? this.numAbbr(volume, 2) : volume;
        },

        showDownloadQr(type) {
            if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
            this.hoverDownloadQr = type;

            this.$nextTick(() => {
                this.renderAppDownloadQrCodes();
            });
        },

        hideDownloadQr() {
            this.hoverDownloadQr = '';
        },

        toggleDownloadQr(type) {
            this.hoverDownloadQr = '';
            this.activeDownloadQr = this.activeDownloadQr === type ? '' : type;

            this.$nextTick(() => {
                this.renderAppDownloadQrCodes();
            });
        },

        downloadQrIsVisible(type) {
            return this.hoverDownloadQr === type || this.activeDownloadQr === type;
        },

        renderAppDownloadQrCodes() {
            this.$nextTick(() => {
                this.renderQrCode('android', this.androidDownloadUrlValue);
                this.renderQrCode('ios', this.iosDownloadUrlValue);
            });
        },

        renderQrCode(type, url) {
            if (!url) {
                return;
            }

            const refName = type === 'android' ? 'androidDownloadQr' : 'iosDownloadQr';
            const canvas = this.$refs[refName];

            if (!canvas) {
                return;
            }

            QRCode.toCanvas(canvas, url, {
                width: 128,
                margin: 1,
                errorCorrectionLevel: 'M',
                color: {
                    dark: '#111827',
                    light: '#ffffff',
                },
            }).catch(() => {
                // QR 生成失败时不影响首页展示
            });
        },

        openDownloadUrl(url) {
            if (!url) {
                return;
            }

            window.open(url, '_blank');
        },

        copyDownloadUrl(url) {
            if (!url) {
                return;
            }

            if (this.$copyText) {
                this.$copyText(url).then(() => {
                    this.$toast.open(this.$t('Text was copied to the clipboard'));
                }).catch(() => {
                    this.$toast.error(this.$t('Copy failed'));
                });

                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(() => {
                    this.$toast.open(this.$t('Text was copied to the clipboard'));
                }).catch(() => {
                    this.$toast.error(this.$t('Copy failed'));
                });

                return;
            }

            const textarea = document.createElement('textarea');
            textarea.value = url;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy');
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            } catch (e) {
                this.$toast.error(this.$t('Copy failed'));
            }

            document.body.removeChild(textarea);
        }
    },

    computed: {
        missingWealthSymbols() { const existing = (this.stakings?.data || []).map(item => item.currency_symbol); return ['BTC','ETH','BNB'].filter(symbol => !existing.includes(symbol)); },
        androidDownloadUrlValue() {
            return publishedDownloadUrl(this.androidDownloadUrl);
        },

        iosDownloadUrlValue() {
            return publishedDownloadUrl(this.iosDownloadUrl);
        },

        hasAppDownloadLinks() {
            return !!this.androidDownloadUrlValue || !!this.iosDownloadUrlValue;
        },

        marketCountValue() {
            if (this.marketCount !== undefined && this.marketCount !== null) {
                return this.marketCount;
            }

            if (this.$page && this.$page.props && this.$page.props.marketCount !== undefined) {
                return this.$page.props.marketCount;
            }

            return 0;
        },

    },

    watch: {
        androidDownloadUrlValue() {
            this.renderAppDownloadQrCodes();
        },

        iosDownloadUrlValue() {
            this.renderAppDownloadQrCodes();
        },

        popupArticle(newArticle, oldArticle) {
            if (!newArticle || newArticle.id === undefined) {
                this.showPopupAnnouncement = false;
                this.unlockPopupBody();
                return;
            }

            if (!oldArticle || oldArticle.id !== newArticle.id) {
                this.openPopupAnnouncementIfNeeded();
            }
        },
    },

    mounted() {
        window.addEventListener('keydown', this.handlePopupEscape);
        this.openPopupAnnouncementIfNeeded();
        this.numAbbr = require('number-abbreviate');

        if (!this.$page.props.alt && window.TyperSetup) {
            window.TyperSetup();
        }

        this.canInstallPwa = !!window.canInstallPwa;
        this.deferredPrompt = window.deferredPrompt;

        this.renderAppDownloadQrCodes();
    },

    updated() {
        this.renderAppDownloadQrCodes();
    },

    beforeDestroy() {
        window.removeEventListener('keydown', this.handlePopupEscape);
        this.unlockPopupBody();
    }
});
</script>

<style>
.cex-home__popup-overlay {
    position: fixed;
    inset: 0;
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0, 0, 0, .68);
}

.cex-home__popup-card {
    position: relative;
    display: flex;
    flex-direction: column;
    width: min(620px, 100%);
    max-height: min(90vh, 760px);
    max-height: min(90dvh, 760px);
    overflow: hidden;
    padding: 0;
    border-radius: 16px;
    background: var(--card-bg, #161b26);
    color: var(--text-color, #fff);
    box-shadow: 0 24px 80px rgba(0, 0, 0, .4);
}

.cex-home__popup-scroll {
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    padding: 30px 30px 4px;
}

.cex-home__popup-content {
    min-width: 0;
}

.cex-home__popup-close {
    position: absolute;
    top: 8px;
    right: 10px;
    z-index: 2;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 50%;
    background: rgba(0, 0, 0, .24);
    color: inherit;
    font-size: 30px;
    line-height: 1;
    cursor: pointer;
}

.cex-home__popup-close:hover,
.cex-home__popup-close:focus-visible {
    background: rgba(0, 0, 0, .42);
}

.cex-home__popup-image {
    display: block;
    width: 100%;
    max-height: 260px;
    object-fit: cover;
    margin: -4px 0 20px;
    border-radius: 10px;
}

.cex-home__popup-card h2 {
    margin: 0 48px 16px 0;
    overflow-wrap: anywhere;
    font-size: clamp(1.25rem, 3vw, 1.65rem);
    line-height: 1.3;
}

.cex-home__popup-body {
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
    line-height: 1.7;
}

.cex-home__popup-body img,
.cex-home__popup-body video,
.cex-home__popup-body iframe {
    display: block;
    max-width: 100% !important;
    height: auto;
}

.cex-home__popup-body table {
    display: block;
    max-width: 100%;
    overflow-x: auto;
}

.cex-home__popup-body pre {
    max-width: 100%;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

.cex-home__popup-body a { color: #7ea2ff; text-decoration: underline; }

.cex-home__popup-footer {
    flex: 0 0 auto;
    padding: 16px 30px 26px;
    background: var(--card-bg, #161b26);
}

.cex-home__popup-action {
    display: block;
    width: min(220px, 100%);
    min-height: 44px;
    margin: 0 auto;
    padding: 10px 28px;
    border: 0;
    border-radius: 8px;
    background: #3861fb;
    color: #fff;
    cursor: pointer;
}

@media (max-width: 640px) {
    .cex-home__popup-overlay {
        align-items: flex-end;
        padding: max(12px, env(safe-area-inset-top))
            max(12px, env(safe-area-inset-right))
            max(12px, env(safe-area-inset-bottom))
            max(12px, env(safe-area-inset-left));
    }

    .cex-home__popup-card {
        width: 100%;
        max-height: calc(100vh - 24px);
        max-height: calc(100dvh - 24px);
        border-radius: 14px;
    }

    .cex-home__popup-scroll {
        padding: 22px 16px 2px;
    }

    .cex-home__popup-close {
        top: 7px;
        right: 7px;
    }

    .cex-home__popup-image {
        max-height: 34vh;
        margin: -2px 0 16px;
        border-radius: 9px;
    }

    .cex-home__popup-card h2 {
        margin-right: 42px;
        margin-bottom: 12px;
        font-size: 1.25rem;
    }

    .cex-home__popup-body {
        font-size: .9375rem;
        line-height: 1.65;
    }

    .cex-home__popup-footer {
        padding: 12px 16px max(16px, env(safe-area-inset-bottom));
    }

    .cex-home__popup-action {
        width: 100%;
    }
}

.cex-home__app-download-simple {
    max-width: 1180px;
    margin: 88px auto 0;
    padding: 0 16px;
}

.cex-home__app-download-simple-inner {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 46px;
}

.cex-home__app-download-simple-copy {
    flex: 1;
    max-width: 620px;
}

.cex-home__app-download-simple-copy h2 {
    margin: 0;
    font-size: 1.5rem;
    line-height: 1.28;
    letter-spacing: -0.03em;
    color: var(--text-color, #b9bac0);
}

.cex-home__app-download-simple-copy p {
    max-width: 620px;
    margin: 14px 0 0;
    font-size: .95rem;
    line-height: 1.85;
    color: var(--second-text-color, #6b7280);
}

.cex-home__app-download-simple-actions {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    flex-shrink: 0;
    padding-top: 4px;
}

.cex-home__app-download-simple-item {
    position: relative;
}

.cex-home__app-download-simple-btn {
    width: 230px;
    height: 58px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 12px;
    background: #ffffff;
    color: #111827;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0 18px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.16s ease;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
}

.cex-home__app-download-simple-btn:hover {
    transform: translateY(-1px);
    border-color: rgba(95, 84, 170, 0.42);
    box-shadow: 0 14px 30px rgba(95, 84, 170, 0.13);
}

.cex-home__app-download-simple-btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #5f54aa;
}

.cex-home__app-download-simple-btn span:nth-child(2) {
    flex: 1;
    text-align: left;
}

.cex-home__app-download-simple-btn-qr {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #5f54aa;
    opacity: 0.85;
}

.cex-home__app-download-simple-popover {
    position: absolute;
    left: 0;
    top: calc(100% + 12px);
    z-index: 20;
    width: 230px;
    border-radius: 14px;
    padding: 16px 16px 14px;
    background: var(--rows-color, #f5f6fb);
    border: 1px solid var(--border-line-color, rgba(148, 163, 184, 0.22));
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.16);
}

.cex-home__app-download-simple-popover::before {
    content: '';
    position: absolute;
    top: -7px;
    left: 28px;
    width: 14px;
    height: 14px;
    transform: rotate(45deg);
    background: var(--rows-color, #f5f6fb);
    border-left: 1px solid var(--border-line-color, rgba(148, 163, 184, 0.22));
    border-top: 1px solid var(--border-line-color, rgba(148, 163, 184, 0.22));
}

.cex-home__app-download-simple-qr-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.cex-home__app-download-simple-qr-card canvas {
    width: 126px !important;
    height: 126px !important;
    display: block;
    background: #ffffff;
    padding: 4px;
    border-radius: 4px;
}

.cex-home__app-download-simple-qr-title {
    margin-top: 10px;
    font-size: 13px;
    color: var(--text-color, #b9bac0);
}

.cex-home__app-download-simple-qr-text {
    margin-top: 3px;
    font-size: 12px;
    color: var(--second-text-color, #6b7280);
}

.cex-home__app-download-simple-link-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    margin-top: 12px;
}

.cex-home__app-download-simple-link-actions button {
    border: 0;
    background: transparent;
    padding: 0;
    color: #5f54aa;
    font-size: 12px;
    cursor: pointer;
}

.cex-home__app-download-simple-link-actions button:hover {
    text-decoration: underline;
}

/* Company certificates */
.cex-home__company {
    max-width: 1180px;
    margin: 88px auto 0;
    padding: 0 16px;
}

.cex-home__certificate-v2 {
    width: 100%;
}

.cex-home__certificate-v2-heading {
    max-width: 760px;
    margin: 0 0 26px;
    text-align: left;
}

.cex-home__certificate-v2-heading h3 {
    margin: 0;
    font-size: 1.5rem;
    line-height: 1.28;
    letter-spacing: -0.03em;
    color: var(--text-color, #b9bac0);
}

.cex-home__certificate-v2-heading p {
    margin: 10px 0 0;
    font-size: .95rem;
    line-height: 1.75;
    color: var(--second-text-color, #6b7280);
}

.cex-home__certificate-v2-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    align-items: stretch;
}

.cex-home__certificate-v2-card {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-radius: 16px;
    background: var(--rows-color, #11131f);
    border: 1px solid var(--border-line-color, rgba(148, 163, 184, 0.18));
    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.12);
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.cex-home__certificate-v2-card:hover {
    transform: translateY(-2px);
    border-color: rgba(95, 84, 170, 0.34);
    box-shadow: 0 18px 42px rgba(0, 0, 0, 0.18);
}

.cex-home__certificate-v2-cover {
    position: relative;
    min-height: 142px;
    padding: 18px;
    display: flex;
    align-items: center;
    gap: 16px;
    background: var(--rows-color, #11131f);
    border-bottom: 1px solid var(--border-line-color, rgba(148, 163, 184, 0.16));
}

.cex-home__certificate-v2-ribbon {
    position: absolute;
    right: -34px;
    top: 18px;
    width: 120px;
    height: 26px;
    transform: rotate(45deg);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    background: #5f54aa;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .02em;
}

.cex-home__certificate-v2-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #5f54aa;
    background: rgba(95, 84, 170, 0.08);
    border: 1px solid rgba(95, 84, 170, 0.16);
    flex: 0 0 auto;
}

.cex-home__certificate-v2-cover-text {
    min-width: 0;
    padding-right: 32px;
}

.cex-home__certificate-v2-cover h4 {
    margin: 0;
    font-size: 16px;
    line-height: 1.35;
    font-weight: 700;
    color: var(--text-color, #b9bac0);
}

.cex-home__certificate-v2-cover p {
    margin: 6px 0 0;
    font-size: 12px;
    line-height: 1.55;
    color: var(--second-text-color, #6b7280);
}

.cex-home__certificate-v2-body {
    display: flex;
    flex-direction: column;
    flex: 1;
    padding: 18px;
}

.cex-home__certificate-v2-content {
    flex: 1;
}

.cex-home__certificate-v2-content h4 {
    margin: 0;
    font-size: 16px;
    line-height: 1.35;
    font-weight: 700;
    color: var(--text-color, #b9bac0);
}

.cex-home__certificate-v2-content p {
    margin: 8px 0 0;
    font-size: 13px;
    line-height: 1.7;
    color: var(--second-text-color, #6b7280);
}

.cex-home__certificate-v2-meta {
    display: grid;
    gap: 8px;
    margin-top: 14px;
}

.cex-home__certificate-v2-meta div {
    min-height: 42px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0 12px;
    border-radius: 10px;
    background: rgba(148, 163, 184, 0.06);
    border: 1px solid rgba(148, 163, 184, 0.10);
}

.cex-home__certificate-v2-meta span {
    font-size: 12px;
    line-height: 1.35;
    color: var(--second-text-color, #6b7280);
}

.cex-home__certificate-v2-meta strong {
    max-width: 58%;
    text-align: right;
    font-size: 12px;
    line-height: 1.35;
    font-weight: 700;
    color: var(--text-color, #b9bac0);
    word-break: break-word;
}

.cex-home__certificate-v2-btn {
    width: 100%;
    min-height: 44px;
    margin-top: 16px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 16px;
    text-align: center;
    text-decoration: none;
    color: #ffffff;
    background: #5f54aa;
    border: 1px solid rgba(95, 84, 170, 0.42);
    font-size: 13px;
    font-weight: 700;
    transition: transform 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
}

.cex-home__certificate-v2-btn:hover {
    transform: translateY(-1px);
    border-color: rgba(95, 84, 170, 0.62);
    box-shadow: 0 14px 30px rgba(95, 84, 170, 0.18);
}

.cex-home__certificate-v2-actions {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    margin-top: 16px;
}

.cex-home__certificate-v2-actions .cex-home__certificate-v2-btn {
    margin-top: 0;
}

.cex-home__certificate-v2-btn--ghost {
    color: var(--text-color, #b9bac0);
    background: transparent;
    border-color: rgba(148, 163, 184, 0.18);
    box-shadow: none;
}

.cex-home__certificate-v2-btn--ghost:hover {
    border-color: rgba(95, 84, 170, 0.42);
}

.light .cex-home__certificate-v2-card,
.light .cex-home__certificate-v2-cover {
    background: #ffffff;
}

.light .cex-home__certificate-v2-card {
    border-color: rgba(15, 23, 42, 0.08);
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.06);
}

.light .cex-home__certificate-v2-cover {
    border-bottom-color: rgba(15, 23, 42, 0.08);
}

.light .cex-home__certificate-v2-meta div {
    background: #f8fafc;
    border-color: rgba(15, 23, 42, 0.08);
}

.light .cex-home__certificate-v2-heading h3,
.light .cex-home__certificate-v2-cover h4,
.light .cex-home__certificate-v2-content h4,
.light .cex-home__certificate-v2-meta strong {
    color: #111827;
}

.light .cex-home__certificate-v2-heading p,
.light .cex-home__certificate-v2-cover p,
.light .cex-home__certificate-v2-content p,
.light .cex-home__certificate-v2-meta span {
    color: #64748b;
}

.light .cex-home__certificate-v2-btn--ghost {
    color: #374151;
}

@media (max-width: 1080px) {
    .cex-home__certificate-v2-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 980px) {
    .cex-home__app-download-simple-inner {
        flex-direction: column;
        gap: 24px;
    }

    .cex-home__app-download-simple-actions {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .cex-home__app-download-simple {
        margin-top: 64px;
    }

    .cex-home__app-download-simple-copy h2 {
        font-size: 26px;
        line-height: 1.35;
    }

    .cex-home__app-download-simple-copy p {
        font-size: 14px;
        margin-top: 12px;
    }

    .cex-home__app-download-simple-actions {
        flex-direction: column;
        gap: 12px;
    }

    .cex-home__app-download-simple-item,
    .cex-home__app-download-simple-btn,
    .cex-home__app-download-simple-popover {
        width: 100%;
    }

    .cex-home__app-download-simple-popover {
        position: static;
        margin-top: 10px;
    }

    .cex-home__app-download-simple-popover::before {
        display: none;
    }

    .cex-home__company {
        margin-top: 64px;
        padding: 0 16px;
    }

    .cex-home__certificate-v2-heading {
        margin-bottom: 22px;
    }

    .cex-home__certificate-v2-heading h3 {
        font-size: 26px;
        line-height: 1.35;
    }

    .cex-home__certificate-v2-heading p {
        font-size: 14px;
        margin-top: 10px;
    }

    .cex-home__certificate-v2-grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }

    .cex-home__certificate-v2-cover {
        min-height: auto;
        padding: 18px;
    }

    .cex-home__certificate-v2-body {
        padding: 18px;
    }

    .cex-home__certificate-v2-meta strong {
        max-width: 62%;
    }
}

@media (max-width: 420px) {
    .cex-home__certificate-v2-cover {
        align-items: flex-start;
    }

    .cex-home__certificate-v2-icon {
        width: 48px;
        height: 48px;
    }

    .cex-home__certificate-v2-cover h4 {
        font-size: 15px;
    }

    .cex-home__certificate-v2-meta div {
        display: block;
        padding: 10px 12px;
    }

    .cex-home__certificate-v2-meta strong {
        display: block;
        max-width: 100%;
        margin-top: 4px;
        text-align: left;
    }
}
</style>
