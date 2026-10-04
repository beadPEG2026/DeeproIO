@component('mail::message')
# 🔔 System Monitor Alert

**Checked at:** {{ $checkedAt }}

## Summary
- **Total Services:** {{ $summary['total'] }}
- **Online:** {{ $summary['online'] }} ✅
- **Offline:** {{ $summary['offline'] }} ❌
- **Critical:** {{ $summary['critical'] }} 🚨
- **Maintenance:** {{ $summary['maintenance'] }} 🔧

---

## Offline Services Requiring Attention

@foreach($services as $service)
@if($service['status'] === 'offline')
### ❌ {{ $service['title'] }}
- **Category:** {{ $service['category'] }}
- **Description:** {{ $service['description'] }}
- **Priority:** {{ ucfirst($service['priority'] ?? 'normal') }}

@endif
@endforeach

---

@component('mail::button', ['url' => config('app.url') . '/exchange-control-panel/system-monitor', 'color' => 'red'])
View System Monitor Dashboard
@endcomponent

**Action Required:** Please investigate the offline services immediately to ensure platform stability.

Thanks,<br>
{{ config('app.name') }} System Monitor
@endcomponent
