<?php

namespace App\Mail\SystemMonitor;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SystemMonitorAlert extends Mailable
{
    use Queueable, SerializesModels;

    public $services;
    public $summary;
    public $checkedAt;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(array $services, array $summary, string $checkedAt)
    {
        $this->services = $services;
        $this->summary = $summary;
        $this->checkedAt = $checkedAt;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = $this->summary['critical'] > 0 
            ? '🚨 CRITICAL: ' . $this->summary['critical'] . ' Service(s) Down - Immediate Action Required'
            : '⚠️ WARNING: ' . $this->summary['offline'] . ' Service(s) Offline';

        return $this->markdown('emails.system-monitor.alert', [
            'services' => $this->services,
            'summary' => $this->summary,
            'checkedAt' => $this->checkedAt
        ])->subject($subject);
    }
}
