<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Services\Market\{MarketPresentation,GlobalMarketOverview};
use App\Services\Operations\History;
use Illuminate\Http\Request;
final class MarketPresentationController extends Controller {
    public function show(MarketPresentation $settings) {
        return response()->json(['settings'=>$settings->get(),'health'=>app(GlobalMarketOverview::class)->status(),'history'=>History::for('market_display','global',20)])->header('Cache-Control','private, no-store');
    }
    public function update(Request $request,MarketPresentation $settings) {
        abort_unless(\App\Support\AdminAccess::allows($request->user()),403);
        $request->merge(['reason'=>trim((string)$request->input('reason',''))]);
        $request->validate(['settings'=>'required|array','reason'=>'required|string|min:5|max:500']);
        $data=$settings->validate($request->input('settings'));
        $settings->save($data,$request->user()->id,trim($request->input('reason')));
        return response()->json(['settings'=>$data]);
    }
}
