<script>
import { themeContent } from "@/Functions/ThemeContent";
import Template from '{Template}/Web/Pages/Page/PageShort.template'
import AppLayout from '@/Layouts/AppLiteLayout'
import TextInput from "@/Jetstream/TextInput";
import TButton from "@/Jetstream/Button";

export default Template({
    components: {
        TButton,
        TextInput,
        AppLayout,
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
