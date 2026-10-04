<template>
<div>
    <private-channel></private-channel>
</div>
</template>

<script>
import Echo from 'laravel-echo';
import Pusher from "pusher-js"
import {mapGetters} from 'vuex'

// Private channel
import PrivateChannel from "@/Store/Channels/Private/PrivateChannel";

export default {
    components: {PrivateChannel},
    computed: mapGetters({
        socket: 'getSocket',
        user: 'getUser',
    }),
    methods: {
        loadSocket: function () {
            // If there is already connected echo server shut it down
            if (window.Echo) window.Echo.disconnect();

            let authUrl = window.hostname;

            if(this.$page.props.alt) {
                let mobilePrefix = authUrl;
                authUrl = mobilePrefix.replace(/^.{2}/g, '');
            }

            const settings = this.$page.props.broadcast || {};
            window.Echo = new Echo({
                broadcaster: 'pusher',
                authEndpoint: '/api/broadcasting/auth',
                key: (settings.key || process.env.MIX_PUSHER_APP_KEY),
                wsHost: (settings.host || process.env.MIX_PUSHER_HOST),
                wsPort: (settings.port || process.env.MIX_PUSHER_PORT),
                wssPort: (settings.port || process.env.MIX_PUSHER_PORT),
                forceTLS: (settings.scheme || process.env.MIX_PUSHER_SCHEME) === 'https',
                encrypted: true,
                disableStats: true,
                enabledTransports: ['ws', 'wss'],
                cluster: (settings.cluster || process.env.MIX_PUSHER_APP_CLUSTER)
            });

            window.Echo.connector.pusher.connection.bind('connected', () => {
                this.$store.dispatch('setSocket', {
                    socket: window.Echo.socketId()
                });
            });

            window.Echo.connector.pusher.connection.bind('state_change', ({current}) => {
                if (current !== 'connected') this.$store.dispatch('setSocket', {socket: null});
            });
        }
    },
    data() {
        return {
            connectionTimeout: 2000,
        }
    },
    mounted() {
        // Initial load
        if(!this.socket) {
            this.loadSocket();
        }

        // Cycling socket loading to reconnect
        this.reconnectTimer = setTimeout(() => { if(!this.socket) this.loadSocket(); }, this.connectionTimeout);
    },

    beforeDestroy() {
        clearTimeout(this.reconnectTimer);
    },
    watch: {
        user: function () {
            this.loadSocket();
        }
    },
}
</script>
