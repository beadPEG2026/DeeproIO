<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Services\Content\HomeBanners;
use App\Services\Operations\History;
use Illuminate\Http\Request;
use Inertia\Inertia;
final class HomeBannersController extends Controller {
    public function index(HomeBanners $banners) {
        return Inertia::render('Admin/HomeBanners/Index',['banners'=>$banners->get(),'history'=>History::for('home_banners','global',20)]);
    }
    public function update(Request $request,HomeBanners $banners) {
        $data=$request->validate(['banners'=>'present|array','reason'=>'required|string|min:5|max:500']);
        $banners->save($banners->validate($data['banners']),$request->user()->id,$data['reason']);
        return redirect()->route('admin.home-banners');
    }
}
