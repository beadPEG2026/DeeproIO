<?php
namespace App\Services\Content;

use App\Models\Page\Page;
use App\Services\Operations\History;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\ValidationException;

/** Public editorial configuration. No fee, wallet, identity or trading switches. */
final class SitePresentation {
    public function defaults(string $scope):array {
        if($scope==='support')return ['chat_enabled'=>true,'chat_url'=>'https://tawk.to/chat/69f0c33fe1b7ea1c36a4417d/1jna7lcr1','email'=>'','availability'=>'scheduled','hours'=>['en'=>'','zh-cn'=>'','zh-tw'=>''],'languages'=>'','order'=>['chat','tickets','email','help']];
        if($scope==='help')return ['items'=>require __DIR__.'/DefaultFaq.php','rules_intro'=>['en'=>'','zh-cn'=>'','zh-tw'=>'']];
        return ['api_public'=>false,'groups'=>[
            ['title'=>['en'=>'Trade','zh-cn'=>'交易','zh-tw'=>'交易'],'links'=>[$this->link('Markets','市场','/markets'),$this->link('Stock Tokens','币股','/stocks'),$this->link('UMI Ecosystem','UMI 生态','/umi-ecosystem')]],
            ['title'=>['en'=>'Help Center','zh-cn'=>'帮助中心','zh-tw'=>'幫助中心'],'links'=>[$this->link('Support Center','客服中心','/support'),$this->link('Help Center','帮助中心','/faq'),$this->link('Fees','手续费','/fees'),$this->link('Trading Rules','交易规则','/trading-rules'),$this->link('Download App','下载客户端','/download'),$this->link('API Documentation','API 文档','/docs/api')]],
            ['title'=>['en'=>'Legal','zh-cn'=>'法律信息','zh-tw'=>'法律資訊'],'links'=>[$this->link('About','关于','/about'),$this->link('Terms of Use','使用条款','/terms'),$this->link('Privacy Policy','隐私政策','/privacy-gdpr'),$this->link('Disclosure Statement','披露声明','/disclosure')]],
        ]];
    }
    private function link(string $en,string $zh,string $path):array {return ['label'=>['en'=>$en,'zh-cn'=>$zh,'zh-tw'=>''],'path'=>$path,'visible'=>true];}
    private function key(string $scope):string {abort_unless(in_array($scope,['navigation','support','help'],true),404);return 'site.presentation.'.$scope;}
    public function state(string $scope):array {return json_decode(DB::table('settings')->where('key',$this->key($scope))->value('value')??'{}',true)?:[];}
    public function get(string $scope):array {return $this->state($scope)['published']??$this->defaults($scope);}
    public function revision(string $scope):string {return hash('sha256',json_encode($this->state($scope)));}
    public function admin(string $scope):array { $state=$this->state($scope);return ['published'=>$this->get($scope),'draft'=>$state['draft']??null,'revision'=>$this->revision($scope),'history'=>History::for('site_presentation',$scope,20),'targets'=>$this->targets()];}
    public function targets():array {return array_values(array_unique(array_merge(['/markets','/stocks','/umi-ecosystem','/support','/faq','/articles','/fees','/trading-rules','/docs/api','/download','/download/android','/download/ios'],Page::where('status',true)->pluck('slug')->map(fn($s)=>'/'.$s)->all())));}
    private function localizedRules(string $key,int $max):array {return [$key=>'required|array:en,zh-cn,zh-tw',$key.'.en'=>'required|string|max:'.$max,$key.'.zh-cn'=>'nullable|string|max:'.$max,$key.'.zh-tw'=>'nullable|string|max:'.$max];}
    public function validate(string $scope,array $data):array {
        $rules=[];
        if($scope==='navigation'){
            $rules=['api_public'=>'required|boolean','groups'=>'required|array|min:1|max:6','groups.*'=>'array:title,links','groups.*.links'=>'present|array|max:15','groups.*.links.*'=>'array:label,path,visible','groups.*.links.*.path'=>'required|string|max:120','groups.*.links.*.visible'=>'required|boolean']+$this->localizedRules('groups.*.title',50)+$this->localizedRules('groups.*.links.*.label',70);
        }elseif($scope==='support'){
            $rules=['chat_enabled'=>'required|boolean','chat_url'=>['nullable','string','max:250','regex:~^https://tawk\.to/chat/[a-zA-Z0-9]+/[a-zA-Z0-9]+$~D'],'email'=>'nullable|email:rfc|max:200','availability'=>'required|in:scheduled,offline','hours'=>'required|array:en,zh-cn,zh-tw','hours.*'=>'nullable|string|max:200','languages'=>'nullable|string|max:100','order'=>'required|array|size:4','order.*'=>'required|in:chat,tickets,email,help|distinct'];
        }elseif($scope==='help'){
            $rules=['items'=>'present|array|max:60','items.*'=>'array:question,answer,category,visible','items.*.category'=>'required|in:account,assets,trading,umi','items.*.visible'=>'required|boolean','rules_intro'=>'required|array:en,zh-cn,zh-tw','rules_intro.*'=>'nullable|string|max:3000']+$this->localizedRules('items.*.question',200)+$this->localizedRules('items.*.answer',3000);
        }else abort(404);
        $clean=Validator::make($data,$rules)->validate();
        if($scope==='navigation')foreach($clean['groups'] as $g)foreach($g['links'] as $l){
            if(!preg_match('~^/[a-z0-9/-]+$~D',$l['path'])||($l['visible']&&!in_array($l['path'],$this->targets(),true)))throw ValidationException::withMessages(['settings'=>__('Choose a published page or a supported destination.')]);
        }
        if($scope==='support'&&$clean['chat_enabled']&&empty($clean['chat_url']))throw ValidationException::withMessages(['settings.chat_url'=>__('Chat URL is required when enabled.')]);
        return $clean;
    }
    public function save(string $scope,array $data,string $mode,string $revision,int $actor,string $reason):void {
        DB::transaction(function()use($scope,$data,$mode,$revision,$actor,$reason){
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))',[$this->key($scope)]);
            if(!hash_equals($this->revision($scope),$revision))throw ValidationException::withMessages(['revision'=>__('Configuration changed. Reload before saving.')]);
            $data=$this->validate($scope,$data);$state=$this->state($scope);$before=$mode==='publish'?$this->get($scope):($state['draft']??null);
            $state[$mode==='publish'?'published':'draft']=$data;if($mode==='publish')unset($state['draft']);
            DB::table('settings')->updateOrInsert(['key'=>$this->key($scope)],['value'=>json_encode($state,JSON_THROW_ON_ERROR)]);
            History::append('site_presentation',$scope,$mode,['before'=>$before,'after'=>$data],$actor,$reason);
        });
    }
    public function navigation():array {
        $nav=$this->get('navigation');$targets=$this->targets();
        $configuredPaths=array_column(array_merge(...array_column($nav['groups'],'links')),'path');
        $defaultPaths=array_column(array_merge(...array_column($this->defaults('navigation')['groups'],'links')),'path');
        // Only known navigation destinations are exposed, never private draft titles/content.
        $knownPaths=array_values(array_unique(array_merge($configuredPaths,$defaultPaths)));
        $unpublished=Page::whereIn('slug',array_map(fn($path)=>ltrim($path,'/'),$knownPaths))
            ->where('status',false)->pluck('slug')->map(fn($slug)=>'/'.$slug)->all();
        $suppressed=$unpublished;
        if(!$nav['api_public'])$suppressed[]='/docs/api';
        foreach($nav['groups'] as &$g){
            foreach($g['links'] as $link)if(!$link['visible'])$suppressed[]=$link['path'];
            $g['links']=array_values(array_filter($g['links'],fn($l)=>$l['visible']&&in_array($l['path'],$targets,true)&&!in_array($l['path'],$unpublished,true)&&($l['path']!=='/docs/api'||$nav['api_public'])));
        }
        unset($g);
        $nav['suppressed_paths']=array_values(array_unique($suppressed));
        $nav['groups']=array_values(array_filter($nav['groups'],fn($g)=>count($g['links'])>0));return $nav;
    }
}
