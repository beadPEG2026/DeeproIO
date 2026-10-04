<?php

namespace App\Http\Controllers\Fortify;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class TwoFactorQrCodeController extends Controller
{
    public function show(Request $request, TwoFactorAuthenticationProvider $provider)
    {
        $pending = session('two_factor_pending');
        $encryptedSecret = $pending['secret'] ?? $request->user()->two_factor_secret;

        if (is_null($encryptedSecret)) {
            return response()->json([
                'message' => 'Two factor authentication setup has not been started.',
            ], 422);
        }

        $url = $provider->qrCodeUrl(
            config('app.name'),
            $request->user()->{Fortify::username()},
            Fortify::currentEncrypter()->decrypt($encryptedSecret)
        );

        return response()->json([
            'svg' => $this->qrCodeSvg($url),
            'url' => $url,
        ]);
    }

    protected function qrCodeSvg(string $url): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
                new SvgImageBackEnd
            )
        ))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
