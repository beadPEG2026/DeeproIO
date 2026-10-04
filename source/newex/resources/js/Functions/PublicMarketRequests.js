import axios from 'axios';
const values = new Map(), pending = new Map();
export function publicMarketRequest(url, maxAge = 2000) {
    const key = new URL(url, location.origin).href;
    const saved = values.get(key);
    if (saved && Date.now() - saved.at < maxAge) return Promise.resolve(saved.response);
    if (pending.has(key)) return pending.get(key);
    const promise = axios.get(url,{timeout:15000}).then(response=>{values.set(key,{at:Date.now(),response});return response;}).finally(()=>pending.delete(key));
    pending.set(key,promise);return promise;
}
