<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Http\Middleware\LanguageDetector;

class BrowserLanguageTest extends TestCase
{
    public function test_browser_language_and_explicit_preferences(): void
    {
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        \App\Models\Language\Language::firstOrCreate(['slug'=>'zh-cn'],['name'=>'简体中文','status'=>true,'is_default'=>false]);
        foreach ([
            ['zh-CN,zh;q=0.9,en;q=0.8',null,null,'zh-cn'],
            ['zh-Hant-HK',null,null,'zh-tw'],
            ['zh-SG',null,null,'zh-cn'],
            ['en-US,en;q=0.9',null,null,'en'],
            ['zh-CN','en',null,'en'],
            ['en-US','en','zh-tw','zh-tw'],
            ['zh-CN','unsupported',null,'zh-cn'],
        ] as [$browser,$cookie,$userLocale,$expected]) {
            $request=Request::create('/','GET',[],['user_language'=>$cookie],[],['HTTP_ACCEPT_LANGUAGE'=>$browser]);
            $user=$userLocale?(object)['language'=>(object)['slug'=>$userLocale,'status'=>true]]:null;
            $request->setUserResolver(fn()=>$user);
            app(LanguageDetector::class)->handle($request,fn() => response('ok'));
            $this->assertSame($expected,app()->getLocale(),json_encode([$browser,$cookie,$userLocale]));
            $this->assertSame($expected,$request->attributes->get('current_language'));
        }
    }
}
