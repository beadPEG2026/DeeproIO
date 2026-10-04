<?php
namespace App\Services\Content;
use App\Models\Page\Page;
use App\Services\Operations\History;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class PagePublication {
    public const FIXED=['about','terms','privacy-gdpr','disclosure'];
    private function key(int $id):string{return 'site.page.draft.'.$id;}
    public function draft(int $id):?array{return json_decode(DB::table('settings')->where('key',$this->key($id))->value('value')??'null',true);}
    private function snapshot(Page $page):array{return $page->only($page->getFillable());}
    public function revision(Page $page):string{return hash('sha256',json_encode([$this->snapshot($page),$this->draft($page->id)]));}
    public function editor(Page $page):array{return ['published'=>$this->snapshot($page),'draft'=>$this->draft($page->id),'revision'=>$this->revision($page),'fixed'=>in_array($page->slug,self::FIXED,true),'history'=>History::for('page_publication',$page->id,20)];}
    public function save(?Page $page,array $data,string $mode,?string $revision,int $actor,string $reason):Page {
        return DB::transaction(function()use($page,$data,$mode,$revision,$actor,$reason){
            $before=null;
            if($page){$page=Page::whereKey($page->id)->lockForUpdate()->firstOrFail();$before=$this->snapshot($page);
                if(!hash_equals($this->revision($page),(string)$revision))throw ValidationException::withMessages(['revision'=>__('Configuration changed. Reload before saving.')]);
                if(in_array($page->slug,self::FIXED,true)&&$data['slug']!==$page->slug)throw ValidationException::withMessages(['slug'=>__('This page has a fixed public address.')]);
            }else{$page=Page::create(array_merge($data,['status'=>false]));}
            if($mode==='draft')DB::table('settings')->updateOrInsert(['key'=>$this->key($page->id)],['value'=>json_encode($data,JSON_THROW_ON_ERROR)]);
            else{$page->update($data);DB::table('settings')->where('key',$this->key($page->id))->delete();}
            History::append('page_publication',$page->id,$mode,['before'=>$before,'after'=>$data],$actor,$reason);
            return $page;
        });
    }
    public function delete(Page $page,int $actor):void {
        DB::transaction(function()use($page,$actor){$page=Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            if(in_array($page->slug,self::FIXED,true))throw ValidationException::withMessages(['page'=>__('Fixed pages can be hidden but not deleted.')]);
            History::append('page_publication',$page->id,'delete',['before'=>$this->snapshot($page)],$actor,'Deleted from page management');
            DB::table('settings')->where('key',$this->key($page->id))->delete();$page->delete();
        });
    }
}
