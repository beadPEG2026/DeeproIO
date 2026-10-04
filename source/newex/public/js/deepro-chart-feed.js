/* Keep the bundled UDF implementation for history and live subscriptions.
 * The first symbol/configuration already comes from the same server-rendered page. */
(function () {
  window.createDeeproChartFeed = function (url, bootstrap) {
    class DeeproDatafeed extends Datafeeds.UDFCompatibleDatafeed {
      _requestConfiguration() { return Promise.resolve(bootstrap.config); }
      resolveSymbol(name, done, fail, extension) {
        const info = bootstrap.symbol;
        if (!info || !info.name || (name !== info.name && name !== info.ticker) || (extension && (extension.currencyCode || extension.unitId))) {
          return super.resolveSymbol(name, done, fail, extension);
        }
        setTimeout(function () { done(Object.assign({
          base_name: [info.listed_exchange + ':' + info.name],
          has_daily: true, format: 'price',
          supported_resolutions: bootstrap.config.supported_resolutions
        }, info)); }, 0);
      }
      getBars(symbol, resolution, period, done, fail) {
        return super.getBars(symbol, resolution, period, function (bars, meta) {
          if (period.firstDataRequest || window.deeproChartHasVolume === undefined) {
            window.deeproChartHasVolume = bars.some(bar => bar.volume !== undefined && bar.volume !== null && Number.isFinite(Number(bar.volume)));
            window.dispatchEvent(new Event('deepro:chart-volume'));
          }
          done(bars, meta);
        }, function (error) {
          // A history-page outage must not cover the already loaded live chart.
          if (period.firstDataRequest) parent.postMessage({type: 'deepro-chart-error'}, location.origin);
          fail(error);
        });
      }
    }
    return new DeeproDatafeed(url, bootstrap.symbol?.listed_exchange === 'HKEX' ? 60000 : 2000);
  };
})();
