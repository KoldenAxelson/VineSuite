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

        .part-header { background: #333; color: #fff; padding: 4px 8px; font-weight: bold; font-size: 10pt; margin-top: 12px; margin-bottom: 0; }

        table.report-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.report-table th { background: #f0f0f0; border: 1px solid #999; padding: 3px 6px; text-align: left; font-size: 8pt; font-weight: bold; }
        table.report-table td { border: 1px solid #ccc; padding: 3px 6px; font-size: 8pt; }
        table.report-table td.gallons { text-align: right; font-family: 'Courier New', monospace; }
        table.report-table td.line-num { text-align: center; width: 30px; }
        table.report-table tr.total { font-weight: bold; background: #f9f9f9; }
        table.report-table td.review-flag { color: #c00; font-weight: bold; }

        .summary-box { border: 2px solid #333; padding: 8px; margin-bottom: 12px; }
        .summary-box h3 { font-size: 10pt; margin-bottom: 6px; }
        .summary-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 9pt; }
        .summary-row.total { border-top: 1px solid #333; font-weight: bold; padding-top: 4px; margin-top: 4px; }

        .balance-check { text-align: center; margin-top: 8px; padding: 4px; font-weight: bold; }
        .balance-ok { color: #060; background: #e0f0e0; }
        .balance-err { color: #c00; background: #f0e0e0; }

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

        {{-- Part I: Summary --}}
        <div class="part-header">PART I — SUMMARY OF WINE OPERATIONS</div>
        <div class="summary-box">
            <table class="report-table">
                <tr>
                    <td style="width: 60%">1. Wine on hand beginning of month</td>
                    <td class="gallons">{{ number_format($summary['opening_inventory'] ?? 0, 1) }}</td>
                </tr>
                <tr>
                    <td>2. Wine produced (Part II total)</td>
                    <td class="gallons">{{ number_format($summary['total_produced'] ?? 0, 1) }}</td>
                </tr>
                <tr>
                    <td>3. Wine received (Part III total)</td>
                    <td class="gallons">{{ number_format($summary['total_received'] ?? 0, 1) }}</td>
                </tr>
                <tr class="total">
                    <td>4. TOTAL WINE TO BE ACCOUNTED FOR (Lines 1+2+3)</td>
                    <td class="gallons">{{ number_format($summary['total_available'] ?? 0, 1) }}</td>
                </tr>
                <tr>
                    <td>5. Wine removed (Part IV total)</td>
                    <td class="gallons">{{ number_format($summary['total_removed'] ?? 0, 1) }}</td>
                </tr>
                <tr>
                    <td>6. Losses (Part V total)</td>
                    <td class="gallons">{{ number_format($summary['total_losses'] ?? 0, 1) }}</td>
                </tr>
                <tr class="total">
                    <td>7. Wine on hand end of month</td>
                    <td class="gallons">{{ number_format($summary['closing_inventory'] ?? 0, 1) }}</td>
                </tr>
            </table>
            <div class="balance-check {{ ($summary['balanced'] ?? false) ? 'balance-ok' : 'balance-err' }}">
                {{ ($summary['balanced'] ?? false) ? 'BALANCED — Lines 5+6+7 = Line 4' : 'BALANCE ERROR — Verify all entries' }}
            </div>
        </div>

        {{-- Parts II-V --}}
        @foreach(['II' => 'WINE PRODUCED BY FERMENTATION OR OTHER PROCESS', 'III' => 'WINE RECEIVED IN BOND', 'IV' => 'WINE REMOVED FROM BOND', 'V' => 'LOSSES OF WINE'] as $part => $title)
            <div class="part-header">PART {{ $part }} — {{ $title }}</div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width: 30px">Line</th>
                        <th>Description</th>
                        <th style="width: 80px">Wine Type</th>
                        <th style="width: 80px; text-align: right">Gallons</th>
                    </tr>
                </thead>
                <tbody>
                    @if(isset($linesByPart[$part]) && $linesByPart[$part]->isNotEmpty())
                        @foreach($linesByPart[$part] as $line)
                            <tr>
                                <td class="line-num">{{ $line->line_number }}</td>
                                <td>
                                    {{ $line->description }}
                                    @if($line->needs_review)
                                        <span class="review-flag">*</span>
                                    @endif
                                </td>
                                <td>{{ ucfirst(str_replace('_', ' ', $line->wine_type)) }}</td>
                                <td class="gallons">{{ number_format((float) $line->gallons, 1) }}</td>
                            </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="4" style="text-align: center; color: #999; font-style: italic;">No activity</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        @endforeach

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
