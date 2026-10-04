<script>
import Template from '{Template}/Web/Components/PeerTrade/Chat.template'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import mapValues from 'lodash/mapValues'
import {string_cut} from "@/Functions/String";
import TextUserInput from "@/Jetstream/TextUserInput";
import SelectUserInput from "@/Jetstream/SelectUserInput";
import ReportsTab from "@/Components/Reports/ReportsTab";
import SvgIcon from "@/Components/Svg/SvgIcon";
import LoadingButton from "@/Jetstream/LoadingButton";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetButton from '@/Jetstream/Button'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetCheckbox from '@/Jetstream/Checkbox'
import throttle from 'lodash/throttle'
import 'viewerjs/dist/viewer.css'
import { directive as viewer } from "v-viewer"

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        Welcome,
        Pagination,
        SearchFilter,
        TextUserInput,
        SelectUserInput,
        ReportsTab,
        SvgIcon,
        LoadingButton,
        JetDialogModal,
        JetSecondaryButton,
        JetCheckbox,
        JetButton,
    },
    props: {
        order: Object,
    },
    data() {
        return {
            viewerOptions: {
                toolbar: false,
                url: 'data-source',
            },
            uploading: false,
            fileRecords: [],
            filesUploaded: false,
            uploadUrl: this.route('user-file-upload-document'),
            fetchInterval: null,
            chatMessages: [],
            message: null,
            receiving: false,
            methodSending: false,
            chatActive: false,
            scrollOps: {
                vuescroll: {},
                scrollPanel: {
                    initialScrollY: '100%'
                },
                rail: {},
                bar: {}
            },
            sending: false,
            userTyping: false,
        }
    },
    mounted() {
        this.getMessages();

        this.fetchInterval = setInterval(() => {
            this.getMessages();
        }, 2000);

        this.$worker.$on('startTypingEvent', (data) => {

            if(this.order.id == data.order) {

                this.userTyping = true;

                setTimeout(() => {
                    this.userTyping = false;
                }, 2000);
            }

        });
    },
    beforeDestroy() {
        clearInterval(this.fetchInterval);
    },
    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {

            })
        },
        reset() {
            this.form = mapValues(this.form, () => null)
        },
        textAreaAdjust() {
            const { message } = this.$refs;
            message.style.height = "0px";
            message.style.height = (message.scrollHeight)+"px";
        },
        onSelect: function(fileRecords){
            this.upload(fileRecords);
        },
        onUploadError: function(fileRecords){

            this.uploading = false;
        },
        upload: function(fileRecords){

            this.uploading = true;

            let self = this;

            this.uploadHeaders = {
                'ORDER-ID': this.order.id,
                'X-XSRF-TOKEN' : $cookies.get('XSRF-TOKEN'),
            };

            setTimeout(() => {
                this.$refs.fileUploadForm.upload(this.uploadUrl, this.uploadHeaders, fileRecords).then(function(){
                    self.uploading = false;
                }).catch(error => {
                    this.$toast.error(this.$t("An error occurred while uploading a file."));
                });
            }, 1000);

        },
        getMessages() {
            axios.get(this.route('p2p.api.getOrderMessage'), {
                params: {
                    order_id: this.order.id
                }
            }).then((response) => {
                response.data.messages && response.data.messages.map((value, key) => {

                    let index = this.chatMessages.findIndex(m => m.id === value.id);

                    if(index === -1) {
                        this.chatMessages.push(value);
                        setTimeout(() => {
                            this.$refs["chatScroll"].scrollTo(
                                {
                                    y: "100%"
                                },
                                1
                            );
                        }, 500)
                    }
                });
            });
        },
        showChat() {
            this.chatActive = true;
        },
        closeChat() {
            this.chatActive = false;
        },
        addMessage() {
            axios.post(this.route('p2p.api.postOrderMessage'), {order_id: this.order.id, message: this.message}).then((response) => {
                this.getMessages();
                this.message = null;
            }).catch(error => {

            });
        },
        typing: throttle(function() {
            axios.post(this.route('p2p.api.chatMessage'), {order_id: this.order.id, 'type' : 'typing'}).then((response) => {

            }).catch(error => {

            });
        }, 5000),
        showImage() {
            this.$viewerApi({
                images: this.images,
            })
        },
    },
    directives: {
        viewer: viewer({
            debug: true,
        }),
    },
})

</script>
