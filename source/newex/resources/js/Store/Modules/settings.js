import {PROFILE_STATE_UPDATE} from "@/Store/Mutations/Settings";

const state = {
    profileShow: false,
};

const getters = {
    getProfileState: (state) => {
        return state.profileShow;
    },
};

const mutations = {
    [PROFILE_STATE_UPDATE](state, value) {
        state.profileShow = value;
    },
};

const actions = {
    setProfileState({ state, commit }, profileState) {
        commit(PROFILE_STATE_UPDATE, profileState);
    },
};

export default {
    namespace: true,
    state,
    getters,
    actions,
    mutations
}
