<?php
namespace App\Services\Content;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\ValidationException;
use App\Services\Operations\History;
final class HomeBanners {
    private const KEY='home.banners';
    public function get(): array {
        $raw=DB::table('settings')->where('key',self::KEY)->value('value');
        return $raw ? (json_decode($raw,true) ?: []) : [
            ['title'=>'数字资产，从容掌握','subtitle'=>'关注市场，管理资产','image'=>'','link'=>'/markets','tone'=>'gold','enabled'=>true,'start'=>null,'end'=>null],
            ['title'=>'探索 UMI 生态','subtitle'=>'生态产品与账户，一处查看','image'=>'','link'=>'/umi-ecosystem/portfolio','tone'=>'mint','enabled'=>true,'start'=>null,'end'=>null],
        ];
    }
    public function published(): array {
        return array_values(array_map(function ($banner) {
            // Existing published banners follow the new entry without rewriting CMS history.
            $banner['link'] = preg_replace('~^/umi-ecosystem/?(?=[?#]|$)~', '/umi-ecosystem/portfolio', $banner['link'] ?? '');
            return $banner;
        }, array_filter($this->get(),fn($b)=>!empty($b['enabled']) && (empty($b['start']) || now()->gte($b['start'])) && (empty($b['end']) || now()->lt($b['end'])))));
    }
    public function validate(array $input): array {
        $v=Validator::make(['banners'=>$input],[
            'banners'=>'present|array|max:20', 'banners.*'=>'array:title,subtitle,image,link,tone,enabled,start,end',
            'banners.*.title'=>'required|string|max:60','banners.*.subtitle'=>'nullable|string|max:120',
            'banners.*.image'=>'nullable|string|max:1000','banners.*.link'=>'nullable|string|max:500',
            'banners.*.tone'=>'required|in:gold,mint,blue','banners.*.enabled'=>'required|boolean',
            'banners.*.start'=>'nullable|date','banners.*.end'=>'nullable|date',
        ])->validate()['banners'];
        foreach($v as $i=>$b){
            foreach(['image','link'] as $field) {
                $url=$b[$field]??'';
                // Navigation and artwork stay on this site. Never accept javascript, //host or backslashes.
                if($url!=='' && (!preg_match('~^/(?!/)[A-Za-z0-9_/?&=.%#,+:@-]*$~D',str_replace('~','%7E',$url)) || str_contains($url,'\\')))
                    throw ValidationException::withMessages(["banners.$i.$field"=>'请填写本站路径，例如 /markets 或 /images/banner.jpg']);
            }
            if(!empty($b['start']) && !empty($b['end']) && strtotime($b['end'])<=strtotime($b['start']))
                throw ValidationException::withMessages(["banners.$i.end"=>'结束时间必须晚于开始时间']);
        }
        return $v;
    }
    public function save(array $banners,int $actor,string $reason): void {
        DB::transaction(function()use($banners,$actor,$reason){
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('home.banners'))");
            $before=$this->get();
            DB::table('settings')->updateOrInsert(['key'=>self::KEY],['value'=>json_encode($banners,JSON_THROW_ON_ERROR)]);
            History::append('home_banners','global','update',['before'=>$before,'after'=>$banners],$actor,$reason);
        });
    }
}
