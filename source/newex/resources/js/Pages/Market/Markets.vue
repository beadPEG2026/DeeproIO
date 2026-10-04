<script>
import Template from '{Template}/Web/Pages/Market/Markets.template';
import AppLayout from '@/Layouts/AppLayout';
import MarketChannel from '@/Store/Channels/Public/Market/MarketChannel';
import MarketOverview from '@/Components/MarketOverview';
export default Template({
    components: {AppLayout, MarketChannel, MarketOverview},
    props: {initialSort: {type:String, default:null}},
    data() { return {product: ['futures','options'].includes(this.initialSort) ? this.initialSort : 'spot'}; },
    watch: {initialSort(value) { this.product=['futures','options'].includes(value)?value:'spot'; }},
});
</script>
