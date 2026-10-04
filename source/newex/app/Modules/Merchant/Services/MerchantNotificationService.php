<?php

namespace App\Modules\Merchant\Services;

use App\Models\User\User;
use App\Modules\Merchant\Mail\AdminMerchantApplicationReceived;
use App\Modules\Merchant\Mail\AdminMerchantInvoicePaid;
use App\Modules\Merchant\Mail\AdminMerchantPayoutRequested;
use App\Modules\Merchant\Mail\MerchantApplicationApproved;
use App\Modules\Merchant\Mail\MerchantApplicationRejected;
use App\Modules\Merchant\Mail\MerchantInvoiceCreated;
use App\Modules\Merchant\Mail\MerchantInvoiceExpired;
use App\Modules\Merchant\Mail\MerchantInvoicePaid;
use App\Modules\Merchant\Mail\MerchantPaymentReceived;
use App\Modules\Merchant\Mail\MerchantPayoutApproved;
use App\Modules\Merchant\Mail\MerchantPayoutCompleted;
use App\Modules\Merchant\Mail\MerchantPayoutFailed;
use App\Modules\Merchant\Mail\MerchantPayoutRejected;
use App\Modules\Merchant\Mail\MerchantReactivated;
use App\Modules\Merchant\Mail\MerchantSuspended;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Models\MerchantPayout;
use App\Modules\Merchant\Models\MerchantRefund;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class MerchantNotificationService
{
    /**
     * Check if email notifications are allowed
     */
    protected function isNotificationAllowed(): bool
    {
        return Setting::get('notification.email', false);
    }

    /**
     * Get admin email from settings
     */
    protected function getAdminEmail(): ?string
    {
        return Setting::get('notification.admin_email', null);
    }

    /**
     * Get merchant owner user
     */
    protected function getMerchantOwner(Merchant $merchant): ?User
    {
        return User::find($merchant->user_id);
    }

    /**
     * Send notification when a new merchant application is submitted
     */
    public function notifyMerchantApplicationSubmitted(Merchant $merchant): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $adminEmail = $this->getAdminEmail();
        if ($adminEmail) {
            try {
                $url = route('admin.merchant.merchants.show', $merchant->id);
                Mail::to($adminEmail)->queue(new AdminMerchantApplicationReceived($merchant, $url));
            } catch (\Exception $e) {
                Log::error('Failed to send admin merchant application notification', [
                    'merchant_id' => $merchant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when merchant application is approved
     */
    public function notifyMerchantApproved(Merchant $merchant): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $dashboardUrl = route('merchant.dashboard');
                Mail::to($user->email)->queue(new MerchantApplicationApproved($user, $merchant, $dashboardUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send merchant approved notification', [
                    'merchant_id' => $merchant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when merchant application is rejected
     */
    public function notifyMerchantRejected(Merchant $merchant, string $reason): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $reapplyUrl = route('merchant.apply');
                Mail::to($user->email)->queue(new MerchantApplicationRejected($user, $merchant, $reason, $reapplyUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send merchant rejected notification', [
                    'merchant_id' => $merchant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when merchant is suspended
     */
    public function notifyMerchantSuspended(Merchant $merchant, string $reason): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                Mail::to($user->email)->queue(new MerchantSuspended($user, $merchant, $reason));
            } catch (\Exception $e) {
                Log::error('Failed to send merchant suspended notification', [
                    'merchant_id' => $merchant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when merchant is reactivated
     */
    public function notifyMerchantReactivated(Merchant $merchant): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $dashboardUrl = route('merchant.dashboard');
                Mail::to($user->email)->queue(new MerchantReactivated($user, $merchant, $dashboardUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send merchant reactivated notification', [
                    'merchant_id' => $merchant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when an invoice is created
     */
    public function notifyInvoiceCreated(MerchantInvoice $invoice): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $invoice->merchant;
        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $viewUrl = route('merchant.invoices.show', $invoice->id);
                Mail::to($user->email)->queue(new MerchantInvoiceCreated($user, $invoice, $viewUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send invoice created notification', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when an invoice is paid
     */
    public function notifyInvoicePaid(MerchantInvoice $invoice): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $invoice->merchant;

        // Notify merchant owner
        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $viewUrl = route('merchant.invoices.show', $invoice->id);
                Mail::to($user->email)->queue(new MerchantInvoicePaid($user, $invoice, $viewUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send invoice paid notification to merchant', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify admin
        $adminEmail = $this->getAdminEmail();
        if ($adminEmail) {
            try {
                $adminUrl = route('admin.merchant.invoices.show', $invoice->id);
                Mail::to($adminEmail)->queue(new AdminMerchantInvoicePaid($invoice, $adminUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send invoice paid notification to admin', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when an invoice expires
     */
    public function notifyInvoiceExpired(MerchantInvoice $invoice): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $invoice->merchant;
        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $viewUrl = route('merchant.invoices.show', $invoice->id);
                Mail::to($user->email)->queue(new MerchantInvoiceExpired($user, $invoice, $viewUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send invoice expired notification', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when a payment is received
     */
    public function notifyPaymentReceived(MerchantInvoice $invoice, MerchantInvoicePayment $payment): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $invoice->merchant;
        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $viewUrl = route('merchant.invoices.show', $invoice->id);
                Mail::to($user->email)->queue(new MerchantPaymentReceived($user, $invoice, $payment, $viewUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send payment received notification', [
                    'invoice_id' => $invoice->id,
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when a payout is requested
     */
    public function notifyPayoutRequested(MerchantPayout $payout): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        // Notify admin
        $adminEmail = $this->getAdminEmail();
        if ($adminEmail) {
            try {
                $url = route('admin.merchant.payouts');
                Mail::to($adminEmail)->queue(new AdminMerchantPayoutRequested($payout, $url));
            } catch (\Exception $e) {
                Log::error('Failed to send payout requested notification to admin', [
                    'payout_id' => $payout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when a payout is approved
     */
    public function notifyPayoutApproved(MerchantPayout $payout): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $payout->merchant;
        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $viewUrl = route('merchant.payouts');
                Mail::to($user->email)->queue(new MerchantPayoutApproved($user, $payout, $viewUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send payout approved notification', [
                    'payout_id' => $payout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when a payout is completed
     */
    public function notifyPayoutCompleted(MerchantPayout $payout): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $payout->merchant;
        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $viewUrl = route('merchant.payouts');
                Mail::to($user->email)->queue(new MerchantPayoutCompleted($user, $payout, $viewUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send payout completed notification', [
                    'payout_id' => $payout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when a payout is rejected
     */
    public function notifyPayoutRejected(MerchantPayout $payout, string $reason): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $payout->merchant;
        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $viewUrl = route('merchant.payouts');
                Mail::to($user->email)->queue(new MerchantPayoutRejected($user, $payout, $reason, $viewUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send payout rejected notification', [
                    'payout_id' => $payout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when a payout transfer fails
     */
    public function notifyPayoutFailed(MerchantPayout $payout, string $error): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $payout->merchant;
        $user = $this->getMerchantOwner($merchant);
        if ($user && $user->email) {
            try {
                $viewUrl = route('merchant.payouts');
                Mail::to($user->email)->queue(new MerchantPayoutFailed($user, $payout, $error, $viewUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send payout failed notification', [
                    'payout_id' => $payout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when a refund is initiated
     */
    public function notifyRefundInitiated(MerchantRefund $refund): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $refund->merchant;
        $user = $this->getMerchantOwner($merchant);
        
        if ($user && $user->email) {
            try {
                Log::info('Refund initiated notification sent', [
                    'refund_id' => $refund->id,
                    'merchant_id' => $merchant->id,
                    'user_email' => $user->email,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send refund initiated notification', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify admin about refund request
        $adminEmail = $this->getAdminEmail();
        if ($adminEmail) {
            try {
                Log::info('Refund initiated admin notification sent', [
                    'refund_id' => $refund->id,
                    'admin_email' => $adminEmail,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send refund initiated notification to admin', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification when a refund is completed
     */
    public function notifyRefundCompleted(MerchantRefund $refund): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $refund->merchant;
        $user = $this->getMerchantOwner($merchant);
        
        if ($user && $user->email) {
            try {
                Log::info('Refund completed notification sent', [
                    'refund_id' => $refund->id,
                    'merchant_id' => $merchant->id,
                    'user_email' => $user->email,
                    'txn_hash' => $refund->txn_hash,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send refund completed notification', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify customer if we have their email
        if ($refund->invoice_id) {
            $invoice = MerchantInvoice::find($refund->invoice_id);
            if ($invoice && $invoice->customer_email) {
                try {
                    Log::info('Refund completed customer notification sent', [
                        'refund_id' => $refund->id,
                        'customer_email' => $invoice->customer_email,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send refund completed notification to customer', [
                        'refund_id' => $refund->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Send notification when a refund fails
     */
    public function notifyRefundFailed(MerchantRefund $refund, string $error): void
    {
        if (!$this->isNotificationAllowed()) {
            return;
        }

        $merchant = $refund->merchant;
        $user = $this->getMerchantOwner($merchant);
        
        if ($user && $user->email) {
            try {
                Log::info('Refund failed notification sent', [
                    'refund_id' => $refund->id,
                    'merchant_id' => $merchant->id,
                    'user_email' => $user->email,
                    'error' => $error,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send refund failed notification', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify admin about failed refund
        $adminEmail = $this->getAdminEmail();
        if ($adminEmail) {
            try {
                Log::info('Refund failed admin notification sent', [
                    'refund_id' => $refund->id,
                    'admin_email' => $adminEmail,
                    'error' => $error,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send refund failed notification to admin', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
