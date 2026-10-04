<script>
import { legacyText } from '@/Functions/LegacyTranslation';
import OperationsHealth from '@/Components/OperationsHealth.vue';

    import Template from '{Template}/Admin/Pages/Admin/Dashboard.template'
    import AppLayout from '@/Layouts/AdminLayout'
    import NavButton from "@/Jetstream/NavButton";
    import TextInput from '@/Jetstream/TextInput'
    import TextareaInput from "@/Jetstream/TextareaInput";
    import LoadingButton from "@/Jetstream/LoadingButton";
    import TrashedMessage from "@/Jetstream/TrashedMessage";
    import SelectInput from "@/Jetstream/SelectInput";
    import EmptyColumn from "@/Jetstream/EmptyColumn";
    import Badge from "@/Jetstream/Badge";

    export default Template({
        components: {
            OperationsHealth,
            NavButton,
            AppLayout,
            TextInput,
            TextareaInput,
            LoadingButton,
            TrashedMessage,
            SelectInput,
            EmptyColumn,
            Badge
        },

        props: {
            stats: Object,
            services: Array,
            operationsHealth: Object,
            last: String,
            version: String,
            readonlyMode: Boolean,
            filters: Object,
            reportPeriod: Array,
            groupLeaders: {
                type: Array,
                default: function () {
                    return [];
                },
            },
            activeGroup: {
                type: Object,
                default: function () {
                    return null;
                },
            },
        },

        data() {
            return {
                interval: null,
                loading: false,
                socketState: false,
                groupDropdownOpen: false,
                testProgress: {
                    is_running: false,
                    current_service: null,
                    progress: 0,
                    services_checked: 0,
                    services_total: 0,
                },
                progressInterval: null,
                form: {
                    start_date: this.toDatetimeLocal(this.filters && this.filters.start_date ? this.filters.start_date : ''),
                    end_date: this.toDatetimeLocal(this.filters && this.filters.end_date ? this.filters.end_date : ''),
                    team_user_id: this.filters && this.filters.team_user_id ? this.filters.team_user_id : '',
                },
            }
        },

        computed: {
            socketConnection() {
                return window.Echo && window.Echo.connector.pusher.connection.state == "connected";
            },

            isSuperAdmin() {
                const props = this.$page && this.$page.props ? this.$page.props : {};
                const user = props.user || (props.auth ? props.auth.user : null);
                const roles = user && user.roles ? user.roles : [];

                return roles.some(r => {
                    if (typeof r === 'string') {
                        return r === 'superadmin';
                    }

                    return r.id === 8 || r.name === 'superadmin';
                });
            },

            activeGroupName() {
                if (!this.form.team_user_id) {
                    return legacyText("全部");
                }

                const currentId = Number(this.form.team_user_id);

                const group = (this.groupLeaders || []).find(item => {
                    return Number(item.id) === currentId;
                });

                if (group) {
                    return this.getGroupDisplayName(group);
                }

                if (this.activeGroup) {
                    return this.getGroupDisplayName(this.activeGroup);
                }

                if (this.stats && this.stats.groupName) {
                    return this.stats.groupName;
                }

                if (this.stats && this.stats.group_name) {
                    return this.stats.group_name;
                }

                return legacyText("全部");
            },
        },

        mounted() {
            document.addEventListener('click', this.handleDocumentClick);

            setTimeout(() => {
                this.testWebsockets();
            }, 3000);
        },

        beforeDestroy: function () {
            document.removeEventListener('click', this.handleDocumentClick);
            clearInterval(this.interval);
            this.stopProgressPolling();
        },

        methods: {
            reportLink(name, dated = true, extra = {}) {
                const query = {...extra};
                const filters = this.filters || {};
                if (filters.team_user_id) query.team_user_id = filters.team_user_id;
                if (dated && this.reportPeriod?.length === 2) { query['period[0]'] = this.reportPeriod[0]; query['period[1]'] = this.reportPeriod[1]; }
                return this.route(name, query);
            },
            getGroupDisplayName(group) {
                if (!group) {
                    return '';
                }

                if (group.leader_nickname) {
                    return group.leader_nickname;
                }

                if (group.display_name) {
                    return group.display_name;
                }

                if (group.account) {
                    return group.account;
                }

                if (group.email) {
                    return group.email;
                }

                if (group.wallet_id) {
                    return group.wallet_id;
                }

                if (group.name) {
                    return group.name;
                }

                return '';
            },

            toggleGroupDropdown(event) {
                if (event) {
                    event.stopPropagation();
                }

                this.groupDropdownOpen = !this.groupDropdownOpen;
            },

            handleDocumentClick(event) {
                if (!this.groupDropdownOpen) {
                    return;
                }

                const dropdown = this.$refs.groupDropdown;

                if (dropdown && !dropdown.contains(event.target)) {
                    this.groupDropdownOpen = false;
                }
            },

            selectGroup(group) {
                this.form.team_user_id = group && group.id ? group.id : '';
                this.groupDropdownOpen = false;
                this.getList();
            },

            toDatetimeLocal(value) {
                if (!value) {
                    return '';
                }

                value = String(value).trim();

                if (!value) {
                    return '';
                }

                if (value.includes('T')) {
                    return value.slice(0, 19);
                }

                return value.replace(' ', 'T').slice(0, 19);
            },

            fromDatetimeLocal(value) {
                if (!value) {
                    return '';
                }

                value = String(value).trim();

                if (!value) {
                    return '';
                }

                if (value.length === 16) {
                    value = value + ':00';
                }

                return value.replace('T', ' ');
            },

            getDatePart(value) {
                if (!value) {
                    return '';
                }

                value = String(value).trim();

                if (!value) {
                    return '';
                }

                value = value.replace(' ', 'T');

                if (value.includes('T')) {
                    return value.split('T')[0];
                }

                return value.slice(0, 10);
            },

            normalizeStartDateTime() {
                if (!this.form.start_date) {
                    return;
                }

                const date = this.getDatePart(this.form.start_date);

                if (!date || date.length !== 10) {
                    return;
                }

                this.form.start_date = date + 'T00:00:00';
            },

            normalizeEndDateTime() {
                if (!this.form.end_date) {
                    return;
                }

                const date = this.getDatePart(this.form.end_date);

                if (!date || date.length !== 10) {
                    return;
                }

                this.form.end_date = date + 'T23:59:59';
            },

            getList() {
                this.normalizeStartDateTime();
                this.normalizeEndDateTime();

                const query = {};

                if (this.form.start_date) {
                    query.start_date = this.fromDatetimeLocal(this.form.start_date);
                }

                if (this.form.end_date) {
                    query.end_date = this.fromDatetimeLocal(this.form.end_date);
                }

                if (this.form.team_user_id) {
                    query.team_user_id = this.form.team_user_id;
                }

                this.$inertia.get(this.route('admin.dashboard'), query, {
                    preserveState: true,
                    preserveScroll: true,
                })
            },

            reset() {
                this.form = {
                    start_date: '',
                    end_date: '',
                    team_user_id: '',
                }

                this.groupDropdownOpen = false;

                this.$inertia.get(this.route('admin.dashboard'), {}, {
                    preserveState: true,
                    preserveScroll: true,
                })
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

                this.startProgressPolling();

                axios.get(this.route('admin.system.monitor.test')).then(response => {

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

                    if (!response.data.is_running && this.loading) {
                        this.loading = false;
                        this.stopProgressPolling();
                        this.$toast.open('Health check completed!');
                        this.getList();
                    }
                }).catch(error => {

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
                }).then(() => {

                });
            }
        },
    })
</script>
