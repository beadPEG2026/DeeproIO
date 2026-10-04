<!DOCTYPE HTML>
<html>
<head>
    <title>TradingView Chart CEX Exchange</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=5.0">
    <meta http-equiv="X-UA-Compatible" content="IE=Edge">
    <link rel="stylesheet" href="/chart-latest/charting_library/css/main.css">
    <script type="text/javascript" src="/chart-latest/charting_library/charting_library.standalone.js"></script>
    @if(is_file(public_path('chart-latest/datafeeds/udf/dist/polyfills.js')))
    <script type="text/javascript" src="/chart-latest/datafeeds/udf/dist/polyfills.js"></script>
    @endif
    <script type="text/javascript" src="/chart-latest/datafeeds/udf/dist/bundle.js"></script>
    <script src="/js/deepro-chart-feed.js?v={{ filemtime(public_path('js/deepro-chart-feed.js')) }}"></script>
    <script src="/js/deepro-chart-controls.js?v={{ filemtime(public_path('js/deepro-chart-controls.js')) }}"></script>
    <script src="/js/deepro-chart-layout.js?v={{ filemtime(public_path('js/deepro-chart-layout.js')) }}"></script>
    <script type="text/javascript">

        function getParameterByName(name) {
            name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
            var regex = new RegExp("[\\?&]" + name + "=([^&#]*)"),
                results = regex.exec(location.search);
            return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
        }

        function initOnReady() {

            let chartColor = '';

            @if($theme == "dark")
                chartColor = {
                background: '#202630',
                gridColor: "#2b323c",
                crossHair: "#1d2234",
                textColor: "#8B8D98",
                lineColor: '#1d2234',
                candleUp: '#229E6B',
                candleDown: '#EE2844',
                theme: 'dark',
            }
            @else
                chartColor = {
                background: '#ffffff',
                gridColor: "#eff2f6",
                crossHair: "#eff2f6",
                textColor: "#1d2234",
                lineColor: '#eff2f6',
                candleUp: '#229E6B',
                candleDown: '#EE2844',
                theme: 'light',
            }
            @endif

            var externalControls = getParameterByName('controls') === 'external';
            var compactChart = externalControls && getParameterByName('compact') === '1';
            var widget = window.tvWidget = new TradingView.widget({
                fullscreen: true,
                symbol: '{{ $symbol }}',
                timezone: @json(($chartBootstrap['symbol']['listed_exchange'] ?? '') === 'HKEX' ? 'Asia/Hong_Kong' : config('app.timezone')),
                interval: '{{ $resolution ?? "1D" }}',
                container: "tv_chart_container",
                toolbar_bg: chartColor.background,
                datafeed: createDeeproChartFeed(@json($feedUrl ?? route('chart.candles')), @json($chartBootstrap)),
                library_path: "/chart-latest/charting_library/",
                locale: ({'zh-cn':'zh', 'zh-tw':'zh_TW'}[getParameterByName('lang') || '{{ app()->getLocale() }}'] || getParameterByName('lang') || '{{ app()->getLocale() }}'),
                enabled_features: ["seconds_resolution", ],
                disabled_features: (externalControls ? ['header_widget'] : []).concat(["create_volume_indicator_by_default", 'use_localstorage_for_settings', "timeframes_toolbar",
                    "show_logo_on_all_charts",
                    "caption_buttons_text_if_possible", "header_settings",
                    "left_toolbar","header_compare", "compare_symbol",
                    "header_widget_dom_node", "header_saveload", "header_undo_redo",
                    "header_interval_dialog_button", "show_interval_dialog_on_key_press",
                    "header_symbol_search"]),
                charts_storage_url: 'https://saveload.tradingview.com',
                charts_storage_api_version: "1.1",
                client_id: 'tradingview.com',
                user_id: 'public_user_id',
                allow_symbol_change: false,
                theme: chartColor.theme,
                overrides: {'paneProperties.backgroundType': 'solid', 'paneProperties.background': chartColor.background, 'paneProperties.vertGridProperties.color': chartColor.gridColor, 'paneProperties.horzGridProperties.color': chartColor.gridColor},
            });

            window.tvWidget.onChartReady(function() {
                attachDeeproDisplayColors(widget);
                attachDeeproChartLayout(widget, compactChart, @json('/css/deepro-chart-compact.css?v='.filemtime(public_path('css/deepro-chart-compact.css'))));
                var controlsAttached = false;
                var ready = async function () {
                    if (controlsAttached) return;
                    controlsAttached = true;
                    parent.postMessage({type: 'deepro-chart-ready'}, location.origin);
                    await attachDeeproChartControls(widget, @json($chartBootstrap));
                };
                if (widget.activeChart().dataReady(ready)) ready();

                @if($theme == "dark")
                window.tvWidget.addCustomCSSFile('/chart-latest/charting_library/css/dark_custom.css')
                @else
                window.tvWidget.addCustomCSSFile('/chart-latest/charting_library/css/custom.css')
                @endif
            })
        };

        window.addEventListener('DOMContentLoaded', initOnReady, false);
    </script>
<script src="/js/deepro-display-colors.js?v={{ filemtime(public_path('js/deepro-display-colors.js')) }}"></script>
</head>

<body>
<div id="tv_chart_container"></div>
</body>

</html>
