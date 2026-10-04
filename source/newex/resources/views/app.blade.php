<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="site-language" content="{{ app()->getLocale() }}">
    @php
        $adminAssets = str_starts_with($page['component'] ?? '', 'Admin/')
            || ($page['component'] ?? '') === 'Umi/V2Console'
            || (auth()->user()?->hasRole('admin') ?? false);
        $authPage = in_array($page['component'] ?? '', ['Auth/Login','Auth/Register','Auth/LoginAdmin','Auth/ForgotPassword','Auth/ResetPassword','Auth/TwoFactorChallenge']);
        $fallbackAssetHash = config('app.user_assets_hash');

        $adminAppCssHash = file_exists(public_path('alternative/css/app.css'))
            ? filemtime(public_path('alternative/css/app.css'))
            : $fallbackAssetHash;

        $adminSiteCssHash = file_exists(public_path('alternative/css/protected-file-3HnJAidsKJ1.css'))
            ? filemtime(public_path('alternative/css/protected-file-3HnJAidsKJ1.css'))
            : $fallbackAssetHash;

        $adminJsHash = file_exists(public_path('alternative/js/protected-file-3HnJAidsKJ1.js'))
            ? filemtime(public_path('alternative/js/protected-file-3HnJAidsKJ1.js'))
            : $fallbackAssetHash;

        $frontendAppCssHash = file_exists(public_path('frontend/css/app.css'))
            ? filemtime(public_path('frontend/css/app.css'))
            : $fallbackAssetHash;

        $frontendSiteCssHash = file_exists(public_path('frontend/css/site.css'))
            ? filemtime(public_path('frontend/css/site.css'))
            : $fallbackAssetHash;

        $frontendJsHash = file_exists(public_path('frontend/js/app.js'))
            ? filemtime(public_path('frontend/js/app.js'))
            : $fallbackAssetHash;

        $typeJsHash = file_exists(public_path('js/type.js'))
            ? filemtime(public_path('js/type.js'))
            : $fallbackAssetHash;
    @endphp
    @if(!$authPage && !$adminAssets)
    <link rel="preload" as="script" href="{{ url('frontend/js/app.js?v=' . $frontendJsHash) }}" fetchpriority="high">
    @foreach(\App\Support\InitialPageAssets::scripts($page['component'] ?? '', public_path()) as $pageScript)
    <link rel="preload" as="script" href="{{ $pageScript }}" fetchpriority="high">
    @endforeach
    <link rel="preload" as="script" href="{{ url('js/type.js?v=' . $typeJsHash) }}">
    @endif
    @if(request()->is('market/*', 'futures-market/*'))
    <link rel="preload" as="script" href="/chart-latest/charting_library/charting_library.standalone.js">
    <link rel="preload" as="script" href="/chart-latest/datafeeds/udf/dist/bundle.js">
    @foreach(glob(public_path('chart-latest/charting_library/bundles/library.*.js')) as $chartBundle)
    <link rel="preload" as="script" fetchpriority="low" href="/chart-latest/charting_library/bundles/{{ basename($chartBundle) }}">
    @endforeach
    @endif
    <!-- Styles -->
    
<link rel="manifest" href="/manifest.webmanifest?v=deepro-20260917">
<link rel="icon" type="image/png" href="/images/deepro-icon-small.png">
<meta name="application-name" content="Deepro">
<meta name="apple-mobile-web-app-title" content="Deepro">
<meta name="theme-color" content="#eff8ef">
<link rel="apple-touch-icon" href="/images/deepro-icon-small.png">

    @if(!$authPage)
    @if($adminAssets)
        <link rel="stylesheet" href="{{ url('alternative/css/app.css?v=' . $adminAppCssHash) }}">
        <link rel="stylesheet" href="{{ url('alternative/css/protected-file-3HnJAidsKJ1.css?v=' . $adminSiteCssHash) }}">
    @else

        <link rel="stylesheet" href="{{ url('frontend/css/app.css?v=' . $frontendAppCssHash) }}">
        <link rel="stylesheet" href="{{ url('frontend/css/site.css?v=' . $frontendSiteCssHash) }}">
    @endif
    @else
        <link rel="stylesheet" href="/frontend/css/app.css?v={{ $frontendAppCssHash }}">
    @endif

    @if(!$authPage)
    <link rel="stylesheet" href="{{ url('css/deepro-site-bundle.css?v=' . filemtime(public_path('css/deepro-site-bundle.css'))) }}">
    @else
    <link rel="stylesheet" href="{{ url('css/deepro-auth.css?v=' . filemtime(public_path('css/deepro-auth.css'))) }}">
    @endif
    <!-- Scripts -->
    <script>
        @php
            $path = resource_path("/lang/". app()->getLocale() .".json");
            $translations = preg_replace( "/\r|\n/", "", file_get_contents($path));
        @endphp
        window.LANGUAGE = "{{ app()->getLocale() }}";
        window.TRANSLATIONS = {!! json_encode($translations); !!}
    </script>

    @if(config('app.analytics'))
    <!-- Global site tag (gtag.js) - Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('app.analytics') }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());

        gtag('config', '{{ config('app.analytics') }}');
    </script>
    @endif
<script>window.addEventListener('error',function(e){var i=e.target;if(i&&i.tagName==='IMG'&&!i.dataset.deeproFallback){i.dataset.deeproFallback='1';i.src='/images/currency-placeholder.svg';}},true);</script>
</head>
@if(request()->get('lite'))
<style>
    #body {
        background: #1d2234 !important;
    }

    #body header.mobile-body {
        display: none;
    }
    #body footer.mobile-body {
        display: none;
    }

    #body main .mobile-body .components-title {
        display: none;
    }

    #body main .mobile-body .components-title {
        display: none;
    }

    #body main .mobile-body .tabs-components .tabs-left {
        overflow-x: scroll;
        width: 100%;
    }

    #body main .mobile-body .tabs-components {
        margin: 0;
        border-bottom: 0;
    }

    #body main .mobile-body .tabs-components .tabs-nav {
        flex-direction: row;
    }

    #body main .mobile-body .tabs-components .tabs-nav .tabs-nav__item {
        white-space: nowrap;
    }

    #body main .mobile-body .tabs-components .tabs-nav .mobapp-hide {
        display: none;
    }

    #body main .mobile-body .form-container {
        margin: 0.5rem auto !important;
    }

    #body main .mobile-body .form-components__block .btn-red {
        margin-left: 0;
        margin-top: 15px;
    }

    #body main .mobile-body .form-components__item-radio {
        flex-direction: column;
    }

    #body main .mobile-body .form-components__item-radio .input-radio {
        width: 100%;
        margin-top: 10px;
    }

</style>
@endif
@if($mobileApp)
<body id="body" class="dark" style="background-color:#1d2234;">
@else
<body id="body" class="{{ $themeMode }}">
@endif
@inertia
</body>

@if($authPage)
<script src="{{ mix('js/auth.js', 'auth') }}" defer></script>
@else
@if($adminAssets)
    <script src="{{ url('js/type.js?v=' . $typeJsHash) }}" defer></script>
    <script src="{{ url('alternative/js/protected-file-3HnJAidsKJ1.js?v=' . $adminJsHash) }}" defer></script>
@else

<script src="{{ url('js/type.js?v=' . $typeJsHash) }}" defer></script>
<script src="{{ url('frontend/js/app.js?v=' . $frontendJsHash) }}" defer></script>

@endif
@endif
</html>
