<script>
import Template from "{Template}/Web/Pages/Article/Faq.template";
import AppLayout from "@/Layouts/AppLayout";import {contentText} from '@/Functions/ContentText.mjs';
export default Template({components:{AppLayout},props:{items:{type:Array,default:()=>[]}},data:()=>({category:'all'}),computed:{filtered(){return this.items.filter(i=>this.category==='all'||i.category===this.category)}},methods:{text(v){return this.$t(contentText(v,this.$i18n.locale))}}});
</script>
