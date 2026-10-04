<script>
import { themeContent } from "@/Functions/ThemeContent";
import Template from '{Template}/Web/Pages/Page/Page.template'
import AppLayout from '@/Layouts/AppLayout'
import TextInput from "@/Jetstream/TextInput";
import TButton from "@/Jetstream/Button";
import TopMenu from '@/Components/PeerTrade/TopMenu'

export default Template({
    components: {
        TButton,
        TextInput,
        AppLayout,
        TopMenu
    },
    props: {
        page: Object,
    },
    computed:{
        cleanMessage() {
            return themeContent(this.page.content);
        }
    }
})
</script>
