<template><app-layout><section class="ticket-page"><nav><Link :href="route('support')">{{ $t('Support') }}</Link><Link :href="route('support.tickets')">{{ $t('My tickets') }}</Link></nav><slot /></section></app-layout></template>
<script>import AppLayout from '@/Layouts/AppLayout';export default {components:{AppLayout}};</script>
<style>
.ticket-page{max-width:1040px;margin:40px auto;padding:0 20px 50px;color:var(--text-primary,#202936)}
.ticket-page nav,.ticket-page .ticket-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:20px}
.ticket-page a{text-decoration:underline;text-underline-offset:3px}.ticket-page h1{font-size:26px;font-weight:700;margin:16px 0}.ticket-page h2{font-size:18px;font-weight:600}
.ticket-page .ticket-card{border:1px solid #b89c5466;border-radius:14px;padding:20px;margin:14px 0;background:#fff}
.ticket-page label{display:block;margin:8px 0}.ticket-page input,.ticket-page select,.ticket-page textarea{background:transparent;border:1px solid #9a927e;border-radius:8px;padding:10px;max-width:100%;color:inherit}.ticket-page textarea{width:100%}
.ticket-page button{border:1px solid #a78b44;border-radius:8px;padding:10px 18px}.ticket-page button[type=submit]{background:#f3cc39;color:#171a21}.ticket-page button:disabled{opacity:.5;cursor:wait}.ticket-page :focus-visible{outline:2px solid #b79532;outline-offset:3px}
.ticket-page .ticket-meta{font-size:13px;opacity:.75;margin:8px 0}.ticket-page .ticket-body{white-space:pre-wrap;overflow-wrap:anywhere}.ticket-page .ticket-notice{padding:12px;border:1px solid #269473;border-radius:8px}
.dark .ticket-page, [data-theme=dark] .ticket-page{color:#e3e8f0}.dark .ticket-page .ticket-card,[data-theme=dark] .ticket-page .ticket-card{background:#17212d}.dark .ticket-page select option{background:#17212d}
@media(max-width:600px){.ticket-page{margin:20px auto;padding:0 12px 35px}.ticket-page .ticket-card{padding:14px}.ticket-page h1{font-size:22px}.ticket-page .ticket-actions>*{max-width:100%}}
</style>
