<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>TTB Form 5120.17 — {{ $report->periodLabel() }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 9pt; color: #333; line-height: 1.3; }
        .page { padding: 0.5in; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
        .header h1 { font-size: 14pt; font-weight: bold; }
        .header h2 { font-size: 11pt; font-weight: normal; margin-top: 2px; }
        .header .form-number { font-size: 8pt; color: #666; }

        .winery-info { display: table; width: 100%; margin-bottom: 12px; border: 1px solid #999; }
        .winery-info .row { display: table-row; }
        .winery-info .cell { display: table-cell; padding: 4px 8px; border-bottom: 1px solid #ddd; font-size: 8pt; }
        .winery-info .label { font-weight: bold; width: 140px; background: #f5f5f5; }

        .section-header { background: #333; color: #fff; padding: 4px 8px; font-weight: bold; font-size: 10pt; margin-top: 12px; margin-bottom: 0; }

        table.report-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.report-table th { background: #f0f0f0; border: 1px solid #999; padding: 3px 6px; text-align: center; font-size: 8pt; font-weight: bold; }
        table.report-table td { border: 1px solid #ccc; padding: 3px 6px; font-size: 8pt; }
        table.report-table td.gallons { text-align: right; font-family: 'Courier New', monospace; }
        table.report-table td.line-num { text-align: center; width: 30px; }
        table.report-table tr.total { font-weight: bold; background: #f9f9f9; }
        table.report-table td.review-flag { color: #c00; font-weight: bold; }

        .wine-type-col { width: 70px; }

        .summary-box { border: 1px solid #999; padding: 8px; margin: 8px 0; }
        .summary-box h4 { font-size: 9pt; margin-bottom: 4px; }
        .summary-row { display: table; width: 100%; }
        .summary-cell { display: table-cell; padding: 2px 6px; font-size: 8pt; }
        .summary-label { width: 200px; }
        .summary-value { text-align: right; font-family: 'Courier New', monospace; font-weight: bold; }
        .summary-balanced { color: #060; }
        .summary-unbalanced { color: #c00; }

        .footer { margin-top: 20px; border-top: 1px solid #999; padding-top: 8px; font-size: 7pt; color: #666; text-align: center; }
        .signature-line { margin-top: 24px; display: table; width: 100%; }
        .signature-line .sig { display: table-cell; width: 50%; padding: 0 12px; }
        .signature-line .sig .line { border-bottom: 1px solid #333; height: 24px; margin-bottom: 2px; }
        .signature-line .sig .label { font-size: 7pt; color: #666; }
    </style>
</head>
<body>
    <div class="page">
        {{-- Form Header --}}
        <div class="header">
            <div class="form-number">Department of the Treasury — Alcohol and Tobacco Tax and Trade Bureau</div>
            <h1>REPORT OF WINE PREMISES OPERATIONS</h1>
            <h2>TTB Form 5120.17</h2>
        </div>

        {{-- Winery Information --}}
        <div class="winery-info">
            <div class="row">
                <div class="cell label">Proprietor</div>
                <div class="cell">{{ $winery?->name ?? 'N/A' }}</div>
                <div class="cell label">Permit Number</div>
                <div class="cell">{{ $winery?->ttb_permit_number ?? 'N/A' }}</div>
            </div>
            <div class="row">
                <div class="cell label">Registry Number</div>
                <div class="cell">{{ $winery?->ttb_registry_number ?? 'N/A' }}</div>
                <div class="cell label">Reporting Period</div>
                <div class="cell">{{ $report->periodLabel() }}</div>
            </div>
            <div class="row">
                <div class="cell label">Address</div>
                <div class="cell" colspan="3">
                    {{ $winery?->address_line_1 }}{{ $winery?->city ? ', '.$winery->city : '' }}{{ $winery?->state ? ', '.$winery->state : '' }} {{ $winery?->zip }}
                </div>
            </div>
        </div>

        @php
            // Map wine_type values to their TTB form column index (0-5 for a-f)
            $columnMap = [
                'not_over_16' => 0,
                'over_16_to_21' => 1,
                'over_21_to_24' => 2,
                'artificially_carbonated' => 3,
                'sparkling' => 4,
                'hard_cider' => 5,
                'all' => -1, // Summary lines span all columns
            ];
        @endphp

        {{-- Part I, Section A: Bulk Wines --}}
        <div class="section-header">PART I, SECTION A — BULK WINES (Lines 1-32)</div>
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 30px">Line</th>
                    <th>Description</th>
                    <th class="wine-type-col">(a)<br>Not Over 16%</th>
                    <th class="wine-type-col">(b)<br>Over 16-21%</th>
                    <th class="wine-type-col">(c)<br>Over 21-24%</th>
                    <th class="wine-type-col">(d)<br>Artif.<br>Carbonated</th>
                    <th class="wine-type-col">(e)<br>Sparkling</th>
                    <th class="wine-type-col">(f)<br>Hard Cider</th>
                </tr>
            </thead>
            <tbody>
                @if(isset($linesBySection['A']) && $linesBySection['A']->isNotEmpty())
                    @foreach($linesBySection['A'] as $line)
                        @php $colIdx = $columnMap[$line->wine_type] ?? -1; @endphp
                        <tr @if(in_array($line->category, ['total_increases', 'total_decreases', 'on_hand_end'])) class="total" @endif>
                            <td class="line-num">{{ $line->line_number }}</td>
                            <td>
                                {{ $line->description }}
                                @if($line->needs_review)
                                    <span class="review-flag">*</span>
                                @endif
                            </td>
                            @for($i = 0; $i < 6; $i++)
                                <td class="gallons">
                                    @if($colIdx === -1 && $line->gallons > 0)
                                        {{ number_format((int) $line->gallons) }}
                                    @elseif($colIdx === $i)
                                        {{ number_format((int) $line->gallons) }}
                                    @else
                                        -
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="8" style="text-align: center; color: #999; font-style: italic;">No activity</td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- Section A Summary --}}
        @if(!empty($sectionASummary))
            <div class="summary-box">
                <h4>Section A Summary</h4>
                <div class="summary-row">
                    <span class="summary-cell summary-label">Opening Inventory:</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionASummary['opening_inventory'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">+ Produced:</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionASummary['total_produced'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">+ Received:</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionASummary['total_received'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">= Total (Line 12):</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionASummary['total_increases'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">- Bottled:</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionASummary['total_bottled'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">- Losses:</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionASummary['total_losses'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">= Closing Inventory (Line 31):</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionASummary['closing_inventory'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">Balance:</span>
                    <span class="summary-cell summary-value {{ ($sectionASummary['balanced'] ?? false) ? 'summary-balanced' : 'summary-unbalanced' }}">
                        {{ ($sectionASummary['balanced'] ?? false) ? 'VERIFIED' : 'ERROR — DOES NOT BALANCE' }}
                    </span>
                </div>
            </div>
        @endif

        {{-- Part I, Section B: Bottled Wines --}}
        <div class="section-header">PART I, SECTION B — BOTTLED WINES (Lines 1-21)</div>
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 30px">Line</th>
                    <th>Description</th>
                    <th class="wine-type-col">(a)<br>Not Over 16%</th>
                    <th class="wine-type-col">(b)<br>Over 16-21%</th>
                    <th class="wine-type-col">(c)<br>Over 21-24%</th>
                    <th class="wine-type-col">(d)<br>Artif.<br>Carbonated</th>
                    <th class="wine-type-col">(e)<br>Sparkling</th>
                    <th class="wine-type-col">(f)<br>Hard Cider</th>
                </tr>
            </thead>
            <tbody>
                @if(isset($linesBySection['B']) && $linesBySection['B']->isNotEmpty())
                    @foreach($linesBySection['B'] as $line)
                        @php $colIdx = $columnMap[$line->wine_type] ?? -1; @endphp
                        <tr @if(in_array($line->category, ['total_increases', 'total_decreases', 'on_hand_end'])) class="total" @endif>
                            <td class="line-num">{{ $line->line_number }}</td>
                            <td>
                                {{ $line->description }}
                                @if($line->needs_review)
                                    <span class="review-flag">*</span>
                                @endif
                            </td>
                            @for($i = 0; $i < 6; $i++)
                                <td class="gallons">
                                    @if($colIdx === -1 && $line->gallons > 0)
                                        {{ number_format((int) $line->gallons) }}
                                    @elseif($colIdx === $i)
                                        {{ number_format((int) $line->gallons) }}
                                    @else
                                        -
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="8" style="text-align: center; color: #999; font-style: italic;">No activity</td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- Section B Summary --}}
        @if(!empty($sectionBSummary))
            <div class="summary-box">
                <h4>Section B Summary</h4>
                <div class="summary-row">
                    <span class="summary-cell summary-label">Opening Inventory:</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionBSummary['opening_inventory'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">+ Bottled (from bulk):</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionBSummary['total_bottled'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">= Total (Line 7):</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionBSummary['total_increases'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">- Removed Taxpaid:</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionBSummary['total_removed_taxpaid'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">= Closing Inventory (Line 20):</span>
                    <span class="summary-cell summary-value">{{ number_format($sectionBSummary['closing_inventory'] ?? 0) }} gal</span>
                </div>
                <div class="summary-row">
                    <span class="summary-cell summary-label">Balance:</span>
                    <span class="summary-cell summary-value {{ ($sectionBSummary['balanced'] ?? false) ? 'summary-balanced' : 'summary-unbalanced' }}">
                        {{ ($sectionBSummary['balanced'] ?? false) ? 'VERIFIED' : 'ERROR — DOES NOT BALANCE' }}
                    </span>
                </div>
            </div>
        @endif

        {{-- Signature --}}
        <div class="signature-line">
            <div class="sig">
                <div class="line"></div>
                <div class="label">Signature of Proprietor or Authorized Person</div>
            </div>
            <div class="sig">
                <div class="line"></div>
                <div class="label">Date</div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="footer">
            Generated by VineSuite on {{ now()->format('M j, Y g:i A') }}
            | * Items marked with asterisk require manual verification
        </div>
    </div>
</body>
</html>
