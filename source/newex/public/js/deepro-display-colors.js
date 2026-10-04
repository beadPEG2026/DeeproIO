/* Chart presentation only: source, candles, price mapping and orders stay unchanged. */
window.attachDeeproDisplayColors = function (widget) {
    function apply() {
        var redUp = false;
        try { redUp = JSON.parse(localStorage.getItem('deepro.displayPreferences') || '{}').colors === 'red-up'; } catch (_) {}
        var up = redUp ? '#e60012' : '#008a32', down = redUp ? '#008a32' : '#e60012';
        var changes = {};
        ['candleStyle', 'hollowCandleStyle', 'haStyle', 'barStyle'].forEach(function(style) {
            ['upColor','borderUpColor','wickUpColor'].forEach(function(k) { changes['mainSeriesProperties.'+style+'.'+k] = up; });
            ['downColor','borderDownColor','wickDownColor'].forEach(function(k) { changes['mainSeriesProperties.'+style+'.'+k] = down; });
        });
        widget.applyOverrides(changes);
        widget.applyStudiesOverrides({'volume.volume.color.0':down,'volume.volume.color.1':up});
    }
    apply();
    window.addEventListener('storage', function(e) { if(e.key==='deepro.displayPreferences') apply(); });
    // Settings changed in the parent document do not send it a storage event.
    if (parent !== window && parent.location.origin === location.origin) parent.addEventListener('deepro:display-preferences', apply);
    window.addEventListener('pagehide', function() { if(parent!==window) parent.removeEventListener('deepro:display-preferences', apply); }, {once:true});
};
