<script>
import {loadWatchlist,saveWatchlist} from "@/Functions/MarketDiscovery.mjs";
export default {
    data() {
        return {
            klineChangeWatcherTimer: null,
            lastKnownKlineChangedAt: 0,
        };
    },
    methods: {
        loadFavorites() {
            try {window.marketFavorites=loadWatchlist(localStorage)} catch (_) {window.marketFavorites=window.marketFavorites||[]}
        },
        favoriteToggle(market) {
            this.loadFavorites();
            const before=window.marketFavorites;
            const values=before.includes(market.name)?before.filter(n=>n!==market.name):[...before,market.name];
            window.marketFavorites=values;
            try {saveWatchlist(localStorage,values);window.dispatchEvent(new Event('deepro:watchlist'))} catch (_) {}
            market.favorite=values.includes(market.name);
            this.$store.dispatch('updateMarket',{market});
        },
        getFavorites() {
            return window.marketFavorites;
        },
        isFavorite(market) {

            if(!market || !window.marketFavorites) return false;

            return window.marketFavorites.includes(market);
        },
        setMarket(market, futures = false, options = false, chart = '') {

            if (futures) return this.$inertia.visit(this.route('futures-market', market.name));
            if (options) return this.$inertia.visit(this.route('options-market', market.name));
            return this.$inertia.visit(this.route('market', {market: market.name, chart: chart || undefined}));
        },

        marketChangeWatcher(market) {

            let className = null;

            if(market.last > market.prevlast) {
                className = 'color-buy';
            } else if(market.last < market.prevlast) {
                className = 'color-sell';
            }

            if(className && this.$refs[market.name]) {

                if(this.$refs[market.name][0]) {
                    this.toogleMarketClasslist(this.$refs[market.name][0], className);
                } else {
                    this.toogleMarketClasslist(this.$refs[market.name], className);
                }
            }
        },
        toogleMarketClasslist(element, classname) {
            if(!element.classList) return;

            element.classList.add(classname);
            setTimeout(() => {
                element.classList.remove(classname);
            }, 500);
        },
        startKlineChangeWatcher() {
            this.stopKlineChangeWatcher();

            const marketId = this.market && this.market.data && this.market.data.id
                ? this.market.data.id
                : (this.$page && this.$page.props ? this.$page.props.marketId : null);

            if (!marketId || typeof axios === 'undefined') {
                return;
            }

            const pageServerTime = this.$page && this.$page.props
                ? this.$page.props.serverTime
                : null;
            const initialTimestamp = pageServerTime
                ? Date.parse(pageServerTime)
                : Date.now();

            this.lastKnownKlineChangedAt = Number.isFinite(initialTimestamp)
                ? initialTimestamp
                : Date.now();

            const generation = this.klineWatcherGeneration;
            let failures = 0;
            const schedule = (delay) => {
                if (generation === this.klineWatcherGeneration) {
                    this.klineChangeWatcherTimer = setTimeout(checkKlineChange, delay);
                }
            };
            const checkKlineChange = async () => {
                if (generation !== this.klineWatcherGeneration) return;
                if (typeof document !== 'undefined' && document.hidden) { schedule(3000); return; }
                this.klineWatcherInFlight = true;
                try {
                    const response = await axios.get(`/markets/${marketId}/kline-delete-time`, {timeout: 8000});
                    if (generation !== this.klineWatcherGeneration) return;
                    const data = response && response.data ? response.data : {};
                    if (data.market && data.market.name) {
                        this.$store.dispatch('updateMarketStats', {market: data.market});
                    }
                    failures = 0;
                } catch (_) { failures = Math.min(failures + 1, 4); }
                finally { if (generation === this.klineWatcherGeneration) this.klineWatcherInFlight = false; }
                // One request in flight; hidden pages pause and failures back off.
                schedule(Math.min(30000, 3000 * Math.pow(2, failures)));
            };
            this.klineWatcherVisibilityHandler = () => {
                if (!document.hidden) {
                    if (!this.klineWatcherInFlight) {
                        clearTimeout(this.klineChangeWatcherTimer);
                        schedule(100);
                    }
                }
            };
            document.addEventListener('visibilitychange', this.klineWatcherVisibilityHandler);
            schedule(100);
        },
        stopKlineChangeWatcher() {
            this.klineWatcherGeneration = (this.klineWatcherGeneration || 0) + 1;
            clearTimeout(this.klineChangeWatcherTimer);
            this.klineChangeWatcherTimer = null;
            if (this.klineWatcherVisibilityHandler) {
                document.removeEventListener('visibilitychange', this.klineWatcherVisibilityHandler);
                this.klineWatcherVisibilityHandler = null;
            }
        }

    }
};
</script>
