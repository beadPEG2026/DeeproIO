<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/SystemMonitor/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import OperationsHealth from '@/Components/OperationsHealth.vue'
import NavButton from "@/Jetstream/NavButton"
import Badge from "@/Jetstream/Badge"
import TextInput from '@/Jetstream/TextInput'
import SelectInput from "@/Jetstream/SelectInput"

export default Template({
    components: {
        AppLayout,
        OperationsHealth,
        NavButton,
        Badge,
        TextInput,
        SelectInput,
    },
    props: {
        services: Array,
        servicesGrouped: Object,
        summary: Object,
        categories: Array,
        alertSettings: Object,
        version: String,
        operationsHealth: Object,
    },
    data() {
        return {
            interval: null,
            loading: false,
            socketState: false,
            selectedCategory: 'all',
            showAlertSettings: false,
            alertForm: {
                enabled: this.alertSettings.enabled,
                cooldown: this.alertSettings.cooldown,
            },
            autoRefresh: true,
            refreshInterval: 30000,
            lastRefresh: null,
            sendingTestAlert: false,
            // Test progress tracking
            testProgress: {
                is_running: false,
                current_service: null,
                progress: 0,
                services_checked: 0,
                services_total: 0,
            },
            progressInterval: null,
        }
    },
    computed: {
        socketConnection() {
            return window.Echo && window.Echo.connector.pusher.connection.state == "connected";
        },
        filteredServices() {
            if (this.selectedCategory === 'all') {
                return this.services;
            }
            return this.services.filter(s => s.category === this.selectedCategory);
        },
        onlinePercentage() {
            return this.summary.uptime_percentage || 0;
        },
        statusColor() {
            if (this.summary.critical_offline > 0) return 'red';
            if (this.summary.offline > 0) return 'yellow';
            if (this.summary.degraded > 0) return 'orange';
            if (this.summary.not_checked > 0 || !this.summary.total) return 'yellow';
            return 'green';
        },
        overallStatus() {
            if (this.summary.critical_offline > 0) return 'Critical';
            if (this.summary.offline > 0) return 'Degraded';
            if (this.summary.degraded > 0) return 'Warning';
            if (this.summary.not_checked > 0 || !this.summary.total) return this.$t('Unknown');
            return 'Operational';
        }
    },
    mounted() {
        setTimeout(() => {
            this.testWebsockets();
        }, 3000);
        
        if (this.autoRefresh) {
            this.startAutoRefresh();
        }
        
        this.lastRefresh = new Date();
    },
    beforeDestroy() {
        this.stopAutoRefresh();
        this.stopProgressPolling();
    },
    methods: {
        getList() {
            this.$inertia.reload({ preserveScroll: true });
        },
        testServices() {
            if (this.loading) return;

            this.loading = true;
            this.testProgress = {
                is_running: true,
                current_service: 'Initializing...',
                progress: 0,
                services_checked: 0,
                services_total: 36,
            };
            
            clearInterval(this.interval);
            clearInterval(this.progressInterval);

            this.$toast.open('Running system health check...');

            // Start polling for progress
            this.startProgressPolling();

            axios.post(this.route('admin.system.monitor.test')).then(response => {
                // Don't stop loading here, wait for progress to complete
            }).catch(error => {
                this.loading = false;
                this.stopProgressPolling();
                this.$toast.error('Failed to run health check');
            });
        },
        testServicesWithAlerts() {
            if (this.loading) return;

            this.loading = true;
            this.testProgress = {
                is_running: true,
                current_service: 'Initializing...',
                progress: 0,
                services_checked: 0,
                services_total: 36,
            };
            
            clearInterval(this.interval);
            clearInterval(this.progressInterval);

            this.$toast.open('Running health check with alert notifications...');

            // Start polling for progress
            this.startProgressPolling();

            axios.post(this.route('admin.system.monitor.test.alerts')).then(response => {
                // Don't stop loading here, wait for progress to complete
            }).catch(error => {
                this.loading = false;
                this.stopProgressPolling();
                this.$toast.error('Failed to run health check');
            });
        },
        startProgressPolling() {
            this.progressInterval = setInterval(() => {
                this.fetchProgress();
            }, 500);
        },
        stopProgressPolling() {
            if (this.progressInterval) {
                clearInterval(this.progressInterval);
                this.progressInterval = null;
            }
        },
        fetchProgress() {
            axios.get(this.route('admin.system.monitor.progress')).then(response => {
                this.testProgress = response.data;
                
                // Check if test completed
                if (!response.data.is_running && this.loading) {
                    this.loading = false;
                    this.stopProgressPolling();
                    this.$toast.open('Health check completed!');
                    this.getList();
                }
            }).catch(error => {
                // Silent fail for progress polling
            });
        },
        testWebsockets() {
            this.services.forEach((service, index) => {
                if (service.title == "Websockets") {
                    this.services[index].status = this.socketState || this.socketConnection ? "online" : "offline";
                }
            });
            this.storeWebsockets();
        },
        storeWebsockets() {
            axios.post(this.route('admin.system.monitor.websocket'), {
                status: this.socketConnection,
            }).then(() => {});
        },
        saveAlertSettings() {
            axios.post(this.route('admin.system.monitor.alerts.update'), this.alertForm)
                .then(response => {
                    this.$toast.open('Alert settings saved');
                    this.showAlertSettings = false;
                })
                .catch(error => {
                    this.$toast.error('Failed to save settings');
                });
        },
        sendTestAlert() {
            if (this.sendingTestAlert) return;
            
            this.sendingTestAlert = true;
            
            axios.post(this.route('admin.system.monitor.alerts.test'))
                .then(response => {
                    this.sendingTestAlert = false;
                    if (response.data.success) {
                        this.$toast.open(response.data.message);
                    } else {
                        this.$toast.error(response.data.message);
                    }
                })
                .catch(error => {
                    this.sendingTestAlert = false;
                    this.$toast.error(error.response?.data?.message || 'Failed to send test alert');
                });
        },
        startAutoRefresh() {
            this.interval = setInterval(() => {
                this.getList();
                this.lastRefresh = new Date();
            }, this.refreshInterval);
        },
        stopAutoRefresh() {
            if (this.interval) {
                clearInterval(this.interval);
                this.interval = null;
            }
        },
        toggleAutoRefresh() {
            this.autoRefresh = !this.autoRefresh;
            if (this.autoRefresh) {
                this.startAutoRefresh();
            } else {
                this.stopAutoRefresh();
            }
        },
        getStatusBadgeType(status) {
            switch (status) {
                case 'online': return 'green';
                case 'offline': return 'red';
                case 'maintenance': return 'orange';
                case 'degraded': return 'yellow';
                default: return 'gray';
            }
        },
        getStatusText(status) {
            switch (status) {
                case 'online': return 'Online';
                case 'offline': return legacyText("Offline");
                case 'maintenance': return 'Maintenance';
                case 'degraded': return 'Degraded';
                default: return 'Not Checked';
            }
        },
        getPriorityBadgeType(priority) {
            switch (priority) {
                case 'critical': return 'red';
                case 'high': return 'orange';
                case 'medium': return 'yellow';
                case 'low': return 'gray';
                default: return 'gray';
            }
        },
        getCategoryIcon(category) {
            switch (category) {
                case 'Infrastructure': return 'server';
                case 'Trading Engine': return 'chart-line';
                case 'Blockchain Networks': return 'link';
                case 'Payment Services': return 'credit-card';
                case 'Security': return 'shield-alt';
                case 'Notifications': return 'bell';
                default: return 'cog';
            }
        },
        formatTime(timestamp) {
            if (!timestamp) return legacyText("Never");
            return new Date(timestamp).toLocaleString();
        }
    },
    watch: {
        autoRefresh(value) {
            if (value) {
                this.startAutoRefresh();
            } else {
                this.stopAutoRefresh();
            }
        }
    }
})
</script>
