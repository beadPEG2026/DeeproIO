console.log(require('path').dirname(require.main.filename).replace('chart-udf/src', '') + '/.env');
require('dotenv').config({ path: require('path').dirname(require.main.filename).replace('chart-udf/src', '') + '/.env' })

let fs = require('fs');
let https;
let credentials;

if(process.env.TRADINGVIEW_CHART_SSL.toLowerCase() == "true") {
    https = require('https');
    let privateKey  = fs.readFileSync(process.env.TRADINGVIEW_CHART_PRIVATE_KEY, 'utf8');
    let certificate = fs.readFileSync(process.env.TRADINGVIEW_CHART_CERT_FILE, 'utf8');
    credentials = {key: privateKey, cert: certificate};
}

const express = require('express')
const app = express()

const cors = require('cors')
app.use(cors())

const morgan = require('morgan')
app.use(morgan('tiny'))

const request = require('request')
const UDF = require('./udf')
const udf = new UDF()

// Common

const query = require('./query')

function handlePromise(res, next, promise) {
    promise.then(result => {
        res.send(result)
    }).catch(err => {
        next(err)
    })
}

function laravelChartUrl(endpoint) {
    const baseUrl = (process.env.APP_URL || '').replace(/\/+$/, '')

    if (!baseUrl) {
        return null
    }

    return `${baseUrl}/tradingview-chart${endpoint ? '/' + endpoint : ''}`
}

function proxyLaravelChart(req, res, next, endpoint) {
    const url = laravelChartUrl(endpoint)

    if (!url) {
        return next(new Error('APP_URL is not configured'))
    }

    request.get({
        url,
        qs: req.query,
        json: true,
        timeout: 10000,
    }, (err, response, body) => {
        if (err) {
            return next(err)
        }

        res.status(response && response.statusCode ? response.statusCode : 200).send(body)
    })
}

// Endpoints

app.all('/chart.io', (req, res) => {
    res.set('Content-Type', 'text/plain').send('Welcome to the Binance UDF Adapter for TradingView. See ./config for more details.')
})

app.get('/chart.io/time', (req, res) => {
    proxyLaravelChart(req, res, () => {
        const time = Math.floor(Date.now() / 1000)  // In seconds
        res.set('Content-Type', 'text/plain').send(time.toString())
    }, 'time')
})

app.get('/chart.io/config', (req, res, next) => {
    proxyLaravelChart(req, res, next, 'config')
})

app.get('/chart.io/symbol_info', (req, res, next) => {
    handlePromise(res, next, udf.symbolInfo())
})

app.get('/chart.io/symbols', [query.symbol], (req, res, next) => {
    proxyLaravelChart(req, res, next, 'symbols')
})

app.get('/chart.io/search', [query.query, query.limit], (req, res, next) => {
    proxyLaravelChart(req, res, next, 'search')
})

app.get('/chart.io/history', [
    query.symbol,
    query.from,
    query.to,
    query.resolution
], (req, res, next) => {
    proxyLaravelChart(req, res, next, 'history')
})

// Handle errors

app.use((err, req, res, next) => {
    if (err instanceof query.Error) {
        return res.status(err.status).send({
            s: 'error',
            errmsg: err.message
        })
    }

    if (err instanceof UDF.SymbolNotFound) {
        return res.status(404).send({
            s: 'error',
            errmsg: 'Symbol Not Found'
        })
    }
    if (err instanceof UDF.InvalidResolution) {
        return res.status(400).send({
            s: 'error',
            errmsg: 'Invalid Resolution'
        })
    }

    console.error(err)
    res.status(500).send({
        s: 'error',
        errmsg: 'Internal Error'
    })
})


//Listen


const port = process.env.TRADINGVIEW_CHART_PORT || 8081

if(process.env.TRADINGVIEW_CHART_SSL.toLowerCase() == "true") {
    var httpsServer = https.createServer(credentials, app);

    httpsServer.listen(port, () => {
        console.log(`Listening on port ${port}`)
    });
} else {
    app.listen(port, () => {
        console.log(`Listening on port ${port}`)
    })
}
