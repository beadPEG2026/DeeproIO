let activeUrl = '';
let loading = null;
let ready = false;

export function chatEmbedUrl(url) {
    const match = /^https:\/\/tawk\.to\/chat\/([a-zA-Z0-9]+)\/([a-zA-Z0-9]+)$/.exec(url || '');
    return match ? `https://embed.tawk.to/${match[1]}/${match[2]}` : null;
}

// A document or script load alone does not mean the chat UI is ready.
export function loadSupportChat(url, environment = window) {
    const src = chatEmbedUrl(url);
    if (!src) return Promise.reject(new Error('invalid_chat_url'));
    if (activeUrl === src && ready) return Promise.resolve(environment.Tawk_API);
    if (activeUrl === src && loading) return loading;
    activeUrl = src;
    ready = false;
    environment.document.getElementById('deepro-support-widget')?.remove();
    const api = environment.Tawk_API = environment.Tawk_API || {};
    api.onBeforeLoad = () => api.hideWidget?.();
    api.onChatMinimized = () => api.hideWidget?.();
    loading = new Promise((resolve, reject) => {
        const script = environment.document.createElement('script');
        script.id = 'deepro-support-widget';
        script.async = true;
        script.src = src;
        script.charset = 'UTF-8';
        script.setAttribute('crossorigin', '*');
        const timer = environment.setTimeout(() => {
            loading = null;
            reject(new Error('chat_timeout'));
        }, 12000);
        api.onLoad = () => {
            environment.clearTimeout(timer);
            ready = true;
            api.hideWidget?.();
            resolve(api);
        };
        script.onerror = () => {
            environment.clearTimeout(timer);
            loading = null;
            script.remove();
            reject(new Error('chat_unavailable'));
        };
        environment.document.head.appendChild(script);
    });
    return loading;
}

export function hideSupportChat(environment = window) {
    environment.Tawk_API?.hideWidget?.();
}
