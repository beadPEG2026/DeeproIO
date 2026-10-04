<template><section class="admin-review-panel"><slot /></section></template>
<style>
.admin-review-panel { color:var(--ui-text); background:var(--ui-surface); border:1px solid var(--ui-line); border-radius:16px; padding:clamp(16px,2vw,28px); margin-bottom:20px; min-width:0; }
.admin-review-panel h1 { font-size:24px; font-weight:700; margin-bottom:18px; }
.admin-review-panel h2 { font-size:18px; font-weight:650; margin:20px 0 12px; }
.admin-review-panel .review-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:16px; }
.admin-review-panel dt { color:var(--ui-muted);font-size:13px;margin-bottom:6px; }
.admin-review-panel dd { overflow-wrap:anywhere; }
.admin-review-panel .review-scroll { overflow:auto;max-width:100%; }
.admin-review-panel table { width:100%;border-collapse:collapse; }
.admin-review-panel th,.admin-review-panel td { text-align:start;padding:12px;border-bottom:1px solid var(--ui-line);vertical-align:top; }
.admin-review-panel th { color:var(--ui-muted);font-weight:600;white-space:nowrap; }
.admin-review-panel input,.admin-review-panel select,.admin-review-panel textarea { background:var(--ui-surface);color:var(--ui-text);border:1px solid var(--ui-line);border-radius:8px;padding:10px;max-width:100%; }
.admin-review-panel textarea { width:100%;min-height:80px; }
.admin-review-panel button,.admin-review-panel .review-link { border:1px solid var(--ui-line);border-radius:8px;padding:10px 14px;margin:6px 8px 6px 0;display:inline-block;color:var(--ui-text); }
.admin-review-panel button:disabled { opacity:.45;cursor:not-allowed; }
.admin-review-panel .review-notice { padding:12px;margin:14px 0;background:var(--ui-field);border-inline-start:3px solid var(--ui-accent); }
</style>
