import {math_formatter} from "@/Functions/Math";

export default {
    methods: {
        math_formatter_mixin: function(value, decimals) {
            return math_formatter(value, decimals);
        }
    }
};
