import Vue from 'vue'
import {publicMarketRequest} from '@/Functions/PublicMarketRequests';
import {marketSnapshotTime, isOlderSnapshot, pickQuote} from '@/Functions/MarketSnapshot.mjs';

import {MARKET_LIST, MARKET_TRADE_LIST, MARKET_UPDATE, MARKET_UPDATE_STATS, MARKET_TRADE_STORE, OPTIONS_TRADE_LIST} from "@/Store/Mutations/Market";

const state = {
    loading: false,
    tickerLoadFailed: false,
    items: [],
    orders: [],
    trades: [],
    options: [],
    latestStats: {},
    latestStatsUpdatedAt: {}
};

const getters = {
    getMarketsLoadFailed: state => state.tickerLoadFailed,
    getMarkets: (state) => {
        return state.items;
    },
    getMarket: (state) => (name) => {
        return state.items.find((market) => {
            return market.name === name;
        });
    },
    getMarketTrades: (state) => (market) => {
        return state.trades[market] || [];
    },
    getOptionsTrades: (state) => (market) => {
        return state.options[market] || [];
    },
};

// Keep transport ordering separate from the time received by this browser.
function mergeSnapshot(state, market) {
    const current = state.latestStats[market.name];
    if (current && isOlderSnapshot(market, state.latestStatsUpdatedAt[market.name])) {
        return Object.assign({}, market, current);
    }
    const quote = Object.assign({}, current || {}, pickQuote(market));
    const time = marketSnapshotTime(market);
    if (time) quote.updated_at = new Date(time).toISOString();
    Vue.set(state.latestStats, market.name, quote);
    Vue.set(state.latestStatsUpdatedAt, market.name, time);
    return Object.assign({}, market, quote);
}

const mutations = {
    marketTickerLoadFailed(state, failed) { state.tickerLoadFailed = failed; },
    [MARKET_LIST](state, {markets}) {
        state.items = markets.map(market => mergeSnapshot(state, market));
    },
    [MARKET_UPDATE](state, {market}) {
        const index = state.items.findIndex(item => item.name === market.name);
        const merged = mergeSnapshot(state, market);
        if (index > -1) Vue.set(state.items, index, merged);
        else state.items.push(merged);
    },
    [MARKET_UPDATE_STATS](state, {market}) {
        if (!market || !market.name || isOlderSnapshot(market, state.latestStatsUpdatedAt[market.name])) return;
        const index = state.items.findIndex(item => item.name === market.name);
        const fallback = typeof window !== 'undefined' && window.globalMarket && window.globalMarket.name === market.name
            ? window.globalMarket : {name: market.name};
        const current = index > -1 ? state.items[index] : fallback;
        const prevlast = current.last;
        const merged = mergeSnapshot(state, Object.assign({}, current, market, {prevlast}));
        if (index > -1) Vue.set(state.items, index, merged);
        else state.items.push(merged);
        VueWorker.$emit('updateMarketWorker', {name: market.name, last: merged.last, prevlast});
    },
    [MARKET_TRADE_LIST](state, {trades, market}) {
        Vue.set(state.trades, market, trades);
    },
    [OPTIONS_TRADE_LIST](state, {options, market}) {
        Vue.set(state.options, market, options);
    },
    [MARKET_TRADE_STORE](state, {trade, market}) {

        if(!state.trades[market]) {
            Vue.set(state.trades, market, []);
        }

        state.trades[market].unshift(trade);
    },
};

const actions = {
    fetchMarkets({ state, commit }, route) {
        return publicMarketRequest(route).then(res => {
            commit(MARKET_LIST, {markets: res.data.data});
            commit('marketTickerLoadFailed', false);
            return true;
        }).catch(() => {
            // Preserve the last snapshot; callers can retry without an unhandled rejection.
            commit('marketTickerLoadFailed', true);
            return false;
        });
    },
    updateMarket({ state, commit }, payload) {
        commit(MARKET_UPDATE, payload);
    },
    updateMarketStats({ state, commit }, payload) {
        commit(MARKET_UPDATE_STATS, payload);
    },
    updateMarketTrade({ state, commit }, payload) {
        commit(MARKET_TRADE_STORE, {
            trade: payload.trade,
            market: payload.market.name
        });

        if (payload.realtime && payload.trade && payload.trade.price) {
            const marketName = payload.market.name;
            const tradePrice = payload.trade.price;

            commit(MARKET_UPDATE_STATS, {
                market: {
                    name: marketName,
                    last: tradePrice,
                    // A trade is not a rolling 24-hour ticker; preserve its statistics.
                    updated_at: payload.trade.created_at || payload.trade.timestamp || null
                }
            });
        }
    },
    fetchMarketTrades({ state, commit }, { market, route }) {
        axios.get(route, {
            params: {
                'market' : market,
            }
        }).then(res => {
            commit(MARKET_TRADE_LIST, {
                trades: res.data.data,
                market: market,
            });
        })
    },
    fetchOptionsTrades({ state, commit }, { market, route }) {
        axios.get(route, {
            params: {
                'market' : market,
            }
        }).then(res => {
            commit(OPTIONS_TRADE_LIST, {
                options: res.data.data,
                market: market,
            });
        })
    },
    setMarketTrades({ state, commit }, { market, trades }) {
        commit(MARKET_TRADE_LIST, {
            trades: trades,
            market: market,
        });
    },
};

export default {
    namespace: true,
    state,
    getters,
    actions,
    mutations
}
