/* Presentation only: values, studies and candles remain owned by TradingView. */
(function () {
  window.attachDeeproChartLayout = function (widget, compact, stylesheet) {
    if (!compact) {
      window.deeproChartDetails = function (value) {widget.applyOverrides({'paneProperties.legendProperties.showSeriesOHLC': value});};
      return;
    }
    const chart = widget.activeChart();
    let details = false, selected = false, shown = false;
    widget.applyOverrides({
      'paneProperties.legendProperties.showSeriesTitle': false,
      'paneProperties.legendProperties.showSeriesOHLC': false,
      'paneProperties.legendProperties.showBarChange': false,
      'scalesProperties.showStudyLastValue': false,
      'scalesProperties.fontSize': 11
    });
    widget.addCustomCSSFile(stylesheet);
    function showDetails() {
      const next = details || selected;
      if (next === shown) return;
      shown = next;
      widget.applyOverrides({'paneProperties.legendProperties.showSeriesOHLC': next});
    }
    window.deeproChartDetails = function (value) {details = value; showDetails();};
    chart.crossHairMoved().subscribe(null, function (event) {
      selected = Number.isFinite(event.time) && !!event.entityValues && !!event.entityValues._seriesId;
      showDetails();
    });
    // The bundled library exposes semantic legend nodes but no short-argument
    // option. Label MA/EMA with their actual period; do not replace study values.
    const frame = document.querySelector('#tv_chart_container iframe');
    const doc = frame && frame.contentDocument;
    if (!doc) return;
    const updateLabels = function () {
      doc.querySelectorAll('[data-name="legend-source-item"]').forEach(function (item) {
        const titles = item.querySelectorAll('[data-name="legend-source-title"]');
        const name = titles[0] && titles[0].textContent.trim();
        const args = titles[1] && titles[1].textContent.trim();
        const length = args && args.match(/^\d+\b/);
        const label = (name === 'MA' || name === 'EMA') && length ? name + '(' + length[0] + ')' : '';
        if (label) {
          if (item.dataset.deeproAverage !== label) item.dataset.deeproAverage = label;
        } else if (item.dataset.deeproAverage) delete item.dataset.deeproAverage;
      });
    };
    updateLabels();
    const observer = new MutationObserver(updateLabels);
    observer.observe(doc.body, {childList: true, subtree: true, characterData: true});
    window.addEventListener('pagehide', function () {observer.disconnect();}, {once: true});
  };
})();
