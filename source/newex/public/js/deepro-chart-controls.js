/* Same-origin UI bridge for the bundled chart. Commands cannot alter market data. */
(function () {
  window.attachDeeproChartControls = function (widget, bootstrap) {
    const chart = widget.activeChart();
    const studies = {
      MA: {name: 'Moving Average', lengths: [7, 25, 99]},
      EMA: {name: 'Moving Average Exponential', lengths: [7, 25, 99]},
      BOLL: {name: 'Bollinger Bands'},
      SAR: {name: 'Parabolic SAR'},
      VOL: {name: 'Volume'}
    };
    const available = widget.getStudiesList();
    const resolutions = (bootstrap.config.supported_resolutions || []).map(String);
    const chartTypes = [{id:1,label:'Candles'},{id:0,label:'Bars'},{id:2,label:'Line'},{id:3,label:'Area'},{id:8,label:'Heikin Ashi'},{id:9,label:'Hollow candles'},{id:10,label:'Baseline'},{id:12,label:'High-low'},{id:13,label:'Columns'},{id:14,label:'Line with markers'},{id:15,label:'Step line'}];
    const owned = {}, colors = ['#d1ad45', '#d35bb8', '#8c78d5'];
    let busy = false;
    let deferredError = false;
    let preferences = {indicators: ['MA', 'VOL']};
    try { preferences = Object.assign(preferences, JSON.parse(sessionStorage.getItem('deepro.chart.preferences') || '{}')); } catch (_) {}
    const keys = () => Object.keys(studies).filter(key => available.includes(studies[key].name) && (key !== 'VOL' || window.deeproChartHasVolume === true));
    function syncStudies() {
      if (!chart.getAllStudies) return;
      const ids = new Set(chart.getAllStudies().map(study => study.id));
      Object.keys(owned).forEach(key => {owned[key] = owned[key].filter(id => ids.has(id));});
    }
    function state(error) {
      deferredError = deferredError || !!error;
      // Data can arrive while default studies are still being created. Do not
      // advertise clickable controls until the bridge can accept commands.
      if (busy) return;
      syncStudies();
      parent.postMessage({type: 'deepro-chart-controls', resolutions, resolution: chart.resolution(), indicators: keys(), chartTypes, chartType: chart.chartType ? chart.chartType() : 1, active: Object.keys(owned).filter(k => owned[k].length), error: deferredError}, location.origin);
      deferredError = false;
    }
    function persist() {
      preferences = {resolution: chart.resolution(), indicators: Object.keys(owned).filter(k => owned[k].length)};
      try {sessionStorage.setItem('deepro.chart.preferences', JSON.stringify(preferences));} catch (_) {}
    }
    async function toggle(key) {
      syncStudies();
      if (!keys().includes(key)) return;
      if (owned[key] && owned[key].length) {
        owned[key].forEach(id => chart.removeEntity(id));
        delete owned[key];
        return;
      }
      const spec = studies[key], ids = [];
      try {
        for (const [i, length] of (spec.lengths || [null]).entries()) {
          const id = await chart.createStudy(spec.name, false, false, length ? {length} : undefined, length ? {'plot.color': colors[i]} : undefined);
          if (id != null) ids.push(id);
        }
        owned[key] = ids;
      } catch (error) {ids.forEach(id => chart.removeEntity(id)); throw error;}
    }
    async function receive(event) {
      if (event.origin !== location.origin || event.source !== parent || !event.data || event.data.type !== 'deepro-chart-command' || busy) return;
      const {action, value} = event.data;
      if (action === 'state') return state();
      if (action === 'resolution' && resolutions.includes(value)) {
        chart.setResolution(value, () => {persist(); state();});
        return;
      }
      if (action === 'chartType' && chartTypes.some(type => type.id === value)) {chart.setChartType(value, () => state()); return;}
      if (action === 'allIndicators') {chart.executeActionById('insertIndicator'); state(); return;}
      if (action === 'details' && typeof value === 'boolean') {if (window.deeproChartDetails) window.deeproChartDetails(value); state(); return;}
      if (action === 'snapshot') {
        busy=true;
        try {
          const canvas=await widget.takeClientScreenshot();
          const link=document.createElement('a'); link.download='Deepro-chart.png'; link.href=canvas.toDataURL('image/png'); link.click();
        } catch (_) {state(true);} finally {busy=false; state();}
        return;
      }
      if (action !== 'indicator' || !keys().includes(value)) return;
      busy = true;
      try {await toggle(value); persist(); state();} catch (_) {state(true);} finally {busy = false; state();}
    }
    window.addEventListener('message', receive);
    if (widget.subscribe) widget.subscribe('study_event', (_, type) => {if (type === 'remove') state();});
    if (chart.onChartTypeChanged) chart.onChartTypeChanged().subscribe(null, () => state());
    chart.onIntervalChanged().subscribe(null, () => {state();});
    // Remove stale volume series if this symbol/period has no volume data.
    window.addEventListener('deepro:chart-volume', () => {
      if (!window.deeproChartHasVolume && owned.VOL) {owned.VOL.forEach(id => chart.removeEntity(id)); delete owned.VOL;}
      state();
    });
    busy = true;
    return (async function () {
      try {
        if (resolutions.includes(preferences.resolution) && chart.resolution() !== preferences.resolution) await new Promise(resolve => chart.setResolution(preferences.resolution, resolve));
        for (const key of Array.isArray(preferences.indicators) ? preferences.indicators : []) await toggle(key);
        state();
      } catch (_) {state(true);} finally {busy = false; state();}
    })();
  };
})();
