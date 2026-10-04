<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Services\Content\SitePresentation;
use Illuminate\Http\Request;
final class SitePresentationController extends Controller {
    public function show(string $scope,SitePresentation $settings){return response()->json($settings->admin($scope))->header('Cache-Control','private, no-store');}
    public function update(Request $request,string $scope,SitePresentation $settings){
        $input=$request->validate(['settings'=>'required|array','mode'=>'required|in:draft,publish','revision'=>'required|string|size:64','reason'=>'required|string|min:5|max:500']);
        $settings->save($scope,$input['settings'],$input['mode'],$input['revision'],$request->user()->id,$input['reason']);
        return response()->json($settings->admin($scope))->header('Cache-Control','private, no-store');
    }
}
