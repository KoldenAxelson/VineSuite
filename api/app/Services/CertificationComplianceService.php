<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\WineryProfile;

/**
 * CertificationComplianceService — checks additions against certification rules.
 *
 * When a winery has organic, biodynamic, or sustainable certification,
 * this service validates that additions (SO2, yeasts, enzymes, etc.)
 * are on the approved inputs list for that certification.
 *
 * Advisory only — flags non-approved inputs as warnings but does not
 * block operations. A winery may choose to lose certification on a
 * specific lot rather than all lots.
 */
class CertificationComplianceService
{
    /** Known certification types. */
    public const CERT_USDA_ORGANIC = 'usda_organic';

    public const CERT_DEMETER_BIODYNAMIC = 'demeter_biodynamic';

    public const CERT_SIP_CERTIFIED = 'sip_certified';

    public const CERT_CCOF = 'ccof';

    public const CERT_SALMON_SAFE = 'salmon_safe';

    /** @var array<string, string> */
    public const CERT_LABELS = [
        self::CERT_USDA_ORGANIC => 'USDA Organic',
        self::CERT_DEMETER_BIODYNAMIC => 'Demeter Biodynamic',
        self::CERT_SIP_CERTIFIED => 'SIP Certified',
        self::CERT_CCOF => 'CCOF Organic',
        self::CERT_SALMON_SAFE => 'Salmon-Safe',
    ];

    /**
     * Prohibited inputs by certification type.
     * These are representative — real lists are maintained by certifying bodies.
     *
     * @var array<string, array<int, string>>
     */
    private const PROHIBITED_INPUTS = [
        self::CERT_USDA_ORGANIC => [
            'synthetic_yeast',
            'mega_purple',
            'velcorin',
            'sorbic_acid',
            'synthetic_tannin',
            'gum_arabic',
            'copper_sulfate_excess', // > 4 ppm
        ],
        self::CERT_DEMETER_BIODYNAMIC => [
            'synthetic_yeast',
            'mega_purple',
            'velcorin',
            'sorbic_acid',
            'synthetic_tannin',
            'gum_arabic',
            'copper_sulfate_excess',
            'commercial_yeast', // Biodynamic requires wild/native yeast
            'commercial_enzyme',
            'bentonite_excessive',
        ],
        self::CERT_SIP_CERTIFIED => [
            'mega_purple',
            'velcorin',
        ],
        self::CERT_CCOF => [
            'synthetic_yeast',
            'mega_purple',
            'velcorin',
            'sorbic_acid',
            'synthetic_tannin',
        ],
        self::CERT_SALMON_SAFE => [
            // Focused on vineyard practices, minimal winemaking restrictions
        ],
    ];

    /**
     * Check if an addition is compliant with the winery's certifications.
     *
     * @param  string  $productName  The name/type of the addition product
     * @param  string|null  $productCategory  Optional category (e.g., 'yeast', 'enzyme', 'fining_agent')
     * @return array{compliant: bool, violations: array<int, array{certification: string, certification_label: string, reason: string}>}
     */
    public function checkAddition(string $productName, ?string $productCategory = null): array
    {
        $profile = WineryProfile::first();
        /** @var array<int, string> $certifications */
        $certifications = $profile !== null ? ($profile->certification_types ?? []) : [];

        if (empty($certifications)) {
            return ['compliant' => true, 'violations' => []];
        }

        $violations = [];
        $normalizedProduct = strtolower(str_replace([' ', '-'], '_', $productName));

        foreach ($certifications as $certType) {
            $prohibitedList = self::PROHIBITED_INPUTS[$certType] ?? [];

            foreach ($prohibitedList as $prohibited) {
                if ($normalizedProduct === $prohibited || str_contains($normalizedProduct, $prohibited)) {
                    $violations[] = [
                        'certification' => $certType,
                        'certification_label' => self::CERT_LABELS[$certType] ?? $certType,
                        'reason' => sprintf(
                            '"%s" is not approved for %s certification',
                            $productName,
                            self::CERT_LABELS[$certType] ?? $certType,
                        ),
                    ];
                }
            }

            // Additional category-level checks for biodynamic
            if ($certType === self::CERT_DEMETER_BIODYNAMIC && $productCategory !== null) {
                if (in_array($productCategory, ['commercial_yeast', 'commercial_enzyme'], true)) {
                    $violations[] = [
                        'certification' => $certType,
                        'certification_label' => self::CERT_LABELS[$certType],
                        'reason' => sprintf(
                            'Product category "%s" is not approved for Demeter Biodynamic certification',
                            $productCategory,
                        ),
                    ];
                }
            }
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
        ];
    }

    /**
     * Get certification audit trail for a lot — all additions with compliance flags.
     *
     * @return array<int, array{event_id: string, product_name: string, performed_at: string|null, compliant: bool, violations: array<int, array{certification: string, certification_label: string, reason: string}>}>
     */
    public function getLotAuditTrail(string $lotId): array
    {
        $additions = Event::ofType('addition_created')
            ->forEntity('lot', $lotId)
            ->orderBy('performed_at')
            ->get();

        $trail = [];

        foreach ($additions as $event) {
            $productName = $event->payload['product_name'] ?? $event->payload['addition_type'] ?? 'Unknown';
            $productCategory = $event->payload['product_category'] ?? null;

            $check = $this->checkAddition($productName, $productCategory);

            $trail[] = [
                'event_id' => $event->id,
                'product_name' => $productName,
                'performed_at' => $event->performed_at->toIso8601String(),
                'compliant' => $check['compliant'],
                'violations' => $check['violations'],
            ];
        }

        return $trail;
    }

    /**
     * Get the winery's active certifications.
     *
     * @return array<int, array{type: string, label: string}>
     */
    public function getActiveCertifications(): array
    {
        $profile = WineryProfile::first();
        /** @var array<int, string> $certifications */
        $certifications = $profile !== null ? ($profile->certification_types ?? []) : [];

        return array_map(fn (string $type) => [
            'type' => $type,
            'label' => array_key_exists($type, self::CERT_LABELS) ? self::CERT_LABELS[$type] : $type,
        ], $certifications);
    }
}
