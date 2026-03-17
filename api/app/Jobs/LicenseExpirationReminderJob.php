<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\License;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Checks for licenses approaching expiration and logs reminders.
 *
 * Scheduled to run daily. Checks each license's renewal_lead_days
 * window and logs any that need attention.
 */
class LicenseExpirationReminderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $tenantId,
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);

        $tenant->run(function () {
            $expiringLicenses = License::whereNotNull('expiration_date')
                ->get()
                ->filter(fn (License $license) => $license->needsRenewalReminder());

            if ($expiringLicenses->isEmpty()) {
                return;
            }

            foreach ($expiringLicenses as $license) {
                $daysLeft = $license->daysUntilExpiration();
                $status = $daysLeft !== null && $daysLeft < 0 ? 'EXPIRED' : 'EXPIRING SOON';

                Log::warning('LicenseExpirationReminder: '.$status, [
                    'license_id' => $license->id,
                    'license_type' => $license->license_type,
                    'jurisdiction' => $license->jurisdiction,
                    'license_number' => $license->license_number,
                    'expiration_date' => $license->expiration_date?->toDateString(),
                    'days_until_expiration' => $daysLeft,
                ]);
            }
        });
    }
}
