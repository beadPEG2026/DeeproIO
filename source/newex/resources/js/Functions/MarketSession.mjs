// The clock is anchored to the response's server time, never the device timezone.
export function sessionView(market, serverNow) {
    if (!market?.price_reference_product) return {blocked:false, label:''};
    const s=market.trading_session;
    if (!s) return {blocked:true, label:'Trading paused'};
    if (s.state==='suspended') return {blocked:true, label:'Trading paused'};
    const close=Date.parse(s.closes_at), next=Date.parse(s.next_open_at), sampled=Date.parse(s.server_time);
    const fresh=Number.isFinite(serverNow) && serverNow>=sampled && serverNow-sampled<=30000;
    if (s.can_trade && fresh && serverNow<close) return {blocked:false, label:'Market open'};
    // Reopening needs a fresh server response. Closing happens at the boundary even offline.
    if (s.can_trade && serverNow<close || !s.can_trade && serverNow>=next && Number.isFinite(next))
        return {blocked:true,label:'Updating market status'};
    return {blocked:true,label:s.state==='lunch' ? 'Lunch break' : 'Market closed'};
}
