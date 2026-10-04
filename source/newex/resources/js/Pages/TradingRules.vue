<script>
import {contentText} from '@/Functions/ContentText.mjs';
import Template from '{Template}/Web/Pages/TradingRules.template'
import AppLayout from '@/Layouts/AppLayout'

export default Template({
  components: { AppLayout },
  methods:{intro(){return contentText(this.introduction,this.$i18n.locale)}},
  props: {
    introduction:Object,
    markets: Array,
    makerFee: Number,
    takerFee: Number,
  },
})
</script>
