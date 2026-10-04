<template><ticket-layout><Head><title>{{ $t('My tickets') }}</title></Head>
<h1>{{ $t('My tickets') }}</h1>
<p>{{ $t('Track requests and replies here. Email is an additional notification.') }}</p>
<form class="ticket-actions" @submit.prevent="search"><label>{{ $t('Search') }}<input v-model="form.search" maxlength="150" :placeholder="$t('Ticket number or title')"></label><label>{{ $t('Status') }}<themed-select v-model="form.status"><option value="">{{ $t('All') }}</option><option v-for="(label,key) in statuses" :key="key" :value="key">{{ $t(label) }}</option></themed-select></label><button type="submit">{{ $t('Search') }}</button><Link :href="route('support')">{{ $t('Submit Support Request') }}</Link></form>
<p v-for="(error,key) in $page.props.errors" :key="key" role="alert">{{error}}</p>
<article v-for="t in tickets.data" :key="t.id" class="ticket-card"><div class="ticket-meta">#{{t.ticket_id}} · {{ $t(statuses[t.status]) }}</div><h2><Link :href="route('support.tickets.show',t.id)">{{t.title}}</Link></h2><p class="ticket-meta">{{ $t('Updated') }}: {{supportTime(t.updated_at)}}</p></article>
<p v-if="!tickets.data.length" class="ticket-card">{{ $t('No tickets match these filters.') }}</p><pagination :links="tickets.links" />
</ticket-layout></template>
<script>
import {supportTime} from '@/Functions/SupportTime';
import TicketLayout from '@/Components/Support/TicketLayout';import Pagination from '@/Jetstream/Pagination';export default{components:{TicketLayout,Pagination},props:{tickets:Object,filters:Object},data(){return{form:{search:'',status:'',...this.filters},statuses:{new:'Awaiting support',replied:'Support replied',closed:'Resolved'}}},methods:{supportTime,search(){this.$inertia.get(this.route('support.tickets'),this.form)}}};</script>
