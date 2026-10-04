import {string_cut} from "@/Functions/String";

export default {
    methods: {
        string_cut_mixin: function(string, limit) {
            return string_cut(string, limit);
        }
    }
};
