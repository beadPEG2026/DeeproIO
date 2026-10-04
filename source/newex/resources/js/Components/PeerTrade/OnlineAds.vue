<script>
import Template from '{Template}/Web/Components/PeerTrade/OnlineAds.template'
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'
import PaginationAjax from '@/Jetstream/PaginationAjax'
import OfferModal from '@/Components/PeerTrade/OfferModal'

export default Template({
    components: {
        JetConfirmationModal,
        JetSecondaryButton,
        JetDangerButton,
        PaginationAjax,
        OfferModal
    },
    props: {
        user: Object,
    },
    data() {
        return {
            buyAds: {},
            sellAds: {},
        }
    },
    mounted() {
        this.getAds();
    },

    methods: {
        openOffer(ad) {

            if(!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            this.activeOffer = ad;

            this.$worker.$emit("openOffer", {
                'activeOffer': this.activeOffer,
                'showAdOffer': true,
            });
        },
        getAds() {
            axios.get(this.route('p2p.api.getAds'), {
                params: {
                    user: this.user.id,
                    type: 'buy'
                }
            }).then((response) => {
                this.sellAds = response.data.ads;
            })

            axios.get(this.route('p2p.api.getAds'), {
                params: {
                    user: this.user.id,
                    type: 'sell'
                }
            }).then((response) => {
                this.buyAds = response.data.ads;
            })
        },
        showLimitedMethods(ads, first = true) {

            if(first) {
                return ads.slice(0,3);
            }

            return ads.slice(3);
        },
        showTooltipMethods(methods) {
            let list = [];
            methods.forEach((item) => {
                list.push(item.name);
            });

            return list.join(', ');
        }
    }
})
</script>
