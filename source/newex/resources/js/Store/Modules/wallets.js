import {
    WALLET_LIST, WALLET_UPDATE,
    DEPOSIT_LIST, DEPOSIT_STORE, DEPOSIT_UPDATE,
    FIAT_DEPOSIT_LIST, FIAT_WITHDRAWAL_LIST,
    WITHDRAWAL_LIST, WITHDRAWAL_STORE, WITHDRAWAL_UPDATE
} from "@/Store/Mutations/Wallet";

import Vue from "vue";
import {SET_USER} from '@/Store/Mutations/User';
import {walletBalanceReady} from '@/Functions/WalletBalance.mjs';

// Promises stay outside reactive state and are scoped to this store instance.
const pendingBalances = new WeakMap();
function selectOwner(state, owner) {
    owner = owner == null ? null : String(owner);
    if (state.balanceOwner === owner) return;
    state.balanceOwner = owner;
    state.items = [];
    state.balanceStatus = 'idle';
    state.balanceUpdatedAt = 0;
    state.balanceRequest++;
}

const state = {
    items: [],
    balanceStatus: 'idle',
    balanceUpdatedAt: 0,
    balanceRequest: 0,
    balanceOwner: null,
    deposits: [],
    withdrawals: [],
    fiatDeposits: [],
    fiatWithdrawals: []
};

const getters = {
    getWalletBalanceStatus: state => state.balanceStatus,
    getWalletBalanceUpdatedAt: state => state.balanceUpdatedAt,
    getWallets: (state) => {
        return state.items;
    },
    getWallet: (state) => (symbol) => {
        return state.items.find((wallet) => {
            return wallet.symbol === symbol;
        });
    },
    getDeposits: (state) => {
        return state.deposits;
    },
    getFiatDeposits: (state) => {
        return state.fiatDeposits;
    },
    getWithdrawals: (state) => {
        return state.withdrawals;
    },
    getFiatWithdrawals: (state) => {
        return state.withdrawals;
    },
};

const mutations = {
    [SET_USER](state, {user}) { selectOwner(state, user?.id); },
    walletBalanceOwner(state, owner) { selectOwner(state, owner); },
    walletBalanceSnapshot(state, snapshot) {
        if (!snapshot || String(snapshot.owner_id) !== state.balanceOwner
            || state.balanceStatus !== 'idle' || !Array.isArray(snapshot.wallets)) return;
        state.items = snapshot.wallets;
        state.balanceStatus = 'ready';
        state.balanceUpdatedAt = Date.now();
    },
    walletBalanceRequest(state, {id, status, updatedAt}) {
        if (id < state.balanceRequest) return;
        state.balanceRequest = id;
        state.balanceStatus = status;
        if (updatedAt) state.balanceUpdatedAt = updatedAt;
    },
    [WALLET_LIST](state, {wallets}) {
        state.items = wallets;
    },
    [WALLET_UPDATE](state, {wallet}) {
        const index = state.items.findIndex(item => item.symbol === wallet.symbol)
        if (index < 0) state.items.push(wallet);
        else Vue.set(state.items, index, wallet);
        // A push arriving during a snapshot makes that older snapshot unsafe.
        if (state.balanceStatus === 'loading') {
            state.balanceRequest++;
            state.balanceStatus = 'stale';
        }
    },
    [DEPOSIT_LIST](state, {deposits}) {
        state.deposits = deposits;
    },
    [FIAT_DEPOSIT_LIST](state, {deposits}) {
        state.fiatDeposits = deposits;
    },
    [DEPOSIT_STORE](state, {deposit}) {
        state.deposits.unshift(deposit)
    },
    [DEPOSIT_UPDATE](state, {deposit}) {
        const index = state.deposits.findIndex(item => item.id === deposit.id)
        Vue.set(state.deposits, index, deposit);
    },
    [WITHDRAWAL_LIST](state, {withdrawals}) {
        state.withdrawals = withdrawals;
    },
    [FIAT_WITHDRAWAL_LIST](state, {withdrawals}) {
        state.withdrawals = withdrawals;
    },
    [WITHDRAWAL_STORE](state, {withdrawal}) {
        state.withdrawals.unshift(withdrawal)
    },
    [WITHDRAWAL_UPDATE](state, {withdrawal}) {
        const index = state.withdrawals.findIndex(item => item.id === withdrawal.id)
        Vue.set(state.withdrawals, index, withdrawal);
    }
};

const actions = {

    fetchWallets({ state, commit, rootGetters }, input) {
        const options = typeof input === 'string' ? {route: input} : input;
        const owner = options.ownerId ?? rootGetters?.getUser?.id ?? state.balanceOwner;
        commit('walletBalanceOwner', owner);
        if (options.reuseRecent) {
            const pending = pendingBalances.get(state);
            if (pending?.id === state.balanceRequest) return pending.promise;
            if (walletBalanceReady(state.balanceStatus, state.balanceUpdatedAt)
                && Date.now() - state.balanceUpdatedAt < 15000) return Promise.resolve(true);
        }
        const id = state.balanceRequest + 1;
        commit('walletBalanceRequest', {id, status: 'loading'});
        const promise = axios.get(options.route, {params: {fresh: 1}, timeout: 15000}).then(response => {
            if (id !== state.balanceRequest) return false;
            if (!Array.isArray(response.data.data)) throw new Error('Invalid wallet response');
            commit(WALLET_LIST, {wallets: response.data.data});
            commit('walletBalanceRequest', {id, status: 'ready', updatedAt: Date.now()});
            return true;
        }).catch(() => {
            if (id === state.balanceRequest) commit('walletBalanceRequest', {id, status: 'error'});
            return false;
        }).finally(() => {
            if (pendingBalances.get(state)?.id === id) pendingBalances.delete(state);
        });
        pendingBalances.set(state, {id, promise});
        return promise;
    },
    fetchDeposits({ state, commit }, route) {
        axios.get(route).then(response => {
            commit(DEPOSIT_LIST, {
                deposits: response.data.data
            });
        }).catch(error => {

        });
    },
    fetchWithdrawals({ state, commit }, route) {
        axios.get(route).then(response => {
            commit(WITHDRAWAL_LIST, {
                withdrawals: response.data.data
            });
        }).catch(error => {

        });
    },
    fetchFiatDeposits({ state, commit }, route) {
        axios.get(route).then(response => {
            commit(FIAT_DEPOSIT_LIST, {
                deposits: response.data.data
            });
        }).catch(error => {

        });
    },
    fetchFiatWithdrawals({ state, commit }, route) {
        axios.get(route).then(response => {
            commit(FIAT_WITHDRAWAL_LIST, {
                withdrawals: response.data.data
            });
        }).catch(error => {

        });
    },
    updateWallet({ state, commit }, payload) {
        commit(WALLET_UPDATE, payload);
    },
    storeDeposit({ state, commit }, payload) {
        commit(DEPOSIT_STORE, {
            deposit: payload.deposit
        });
    },
    updateDeposit({ state, commit}, payload) {
        commit(DEPOSIT_UPDATE, {
            deposit: payload.deposit
        });
    },
    storeWithdrawal({ state, commit }, payload) {
        commit(WITHDRAWAL_STORE, {
            withdrawal: payload.withdrawal
        });
    },
    updateWithdrawal({ state, commit}, payload) {
        commit(WITHDRAWAL_UPDATE, {
            withdrawal: payload.withdrawal
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
