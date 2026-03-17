@php
    $badgeClasses = match($wineType) {
        'not_over_16' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
        'over_16_to_21' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
        'over_21_to_24' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
        'artificially_carbonated' => 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
        'sparkling' => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300',
        'hard_cider' => 'bg-lime-100 text-lime-800 dark:bg-lime-900/30 dark:text-lime-300',
        'all' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    };

    $labels = [
        'not_over_16' => 'Not Over 16%',
        'over_16_to_21' => 'Over 16-21%',
        'over_21_to_24' => 'Over 21-24%',
        'artificially_carbonated' => 'Artif. Carbonated',
        'sparkling' => 'Sparkling',
        'hard_cider' => 'Hard Cider',
        'all' => 'All Types',
    ];

    $label = $labels[$wineType] ?? ucfirst(str_replace('_', ' ', $wineType));
@endphp
<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold {{ $badgeClasses }}">
    {{ $label }}
</span>
