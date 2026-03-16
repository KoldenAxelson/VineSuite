<?php

declare(strict_types=1);

/**
 * Event source mapping — maps operation type prefixes to event source categories.
 *
 * Used by EventLogger::resolveSource() to automatically categorize events.
 * When adding a new module, add its operation type prefixes here instead
 * of modifying EventLogger directly.
 *
 * The default source (when no prefix matches) is 'production'.
 *
 * @see \App\Services\EventLogger::resolveSource()
 * @see docs/references/event-source-partitioning.md
 */
return [
    'lab' => [
        'lab_',
        'fermentation_',
        'sensory_',
    ],

    'inventory' => [
        'stock_',
        'purchase_',
        'equipment_',
        'dry_goods_',
        'raw_material_',
    ],

    'accounting' => [
        'cost_',
        'cogs_',
    ],

    // Future modules — uncomment and extend as needed:
    // 'dtc' => ['order_', 'club_', 'shipment_'],
    // 'pos' => ['pos_', 'terminal_'],
    // 'compliance' => ['ttb_', 'excise_'],
];
