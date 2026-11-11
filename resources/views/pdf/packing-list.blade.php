@php
    /** @var array<string, mixed> $data */
    $formatDate = fn ($value): string => $value
        ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
        : '—';
    $formatQty = fn (int|float|string|null $value): string => $value === null
        ? '—'
        : number_format((float) $value, 0, ',', ' ');
    $formatDecimal = fn ($value): string => $value === null
        ? '—'
        : number_format((float) $value, 2, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $data['shipment_number'] }} — Liste de colisage</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 10mm 16mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 9.5px; line-height: 1.35; }
        h1 { font-size: 20px; margin: 0; color: #111827; }
        h2 { font-size: 13px; margin: 16px 0 7px; padding-bottom: 3px; border-bottom: 1px solid #9ca3af; color: #111827; }
        h3 { font-size: 10px; margin: 0 0 3px; color: #374151; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5px; }
        .header { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .header td { vertical-align: top; }
        .company { font-size: 18px; font-weight: bold; letter-spacing: .5px; }
        .doc-title { text-align: right; }
        .doc-title .number { font-size: 16px; font-weight: bold; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .meta th, .meta td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
        .meta th { width: 22%; background: #f3f4f6; font-weight: bold; color: #374151; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.data th, table.data td { border: 1px solid #d1d5db; padding: 3px 4px; vertical-align: top; }
        table.data th { background: #f3f4f6; font-weight: bold; color: #374151; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .package { page-break-inside: avoid; margin-bottom: 10px; }
        .right { text-align: right; }
        .footer { margin-top: 14px; border-top: 1px solid #9ca3af; padding-top: 4px; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="company">{{ $data['company_name'] }}</div>
                <div class="muted">Charpente métallique — fabrication</div>
            </td>
            <td class="doc-title">
                <div class="doc-title-label">LISTE DE COLISAGE</div>
                <div class="number">{{ $data['shipment_number'] }}</div>
                <div class="muted">OF : {{ $data['work_order_number'] ?: '—' }}</div>
            </td>
        </tr>
    </table>

    <h2>Références</h2>
    <table class="meta">
        <tr>
            <th>Expédition</th>
            <td>{{ $data['shipment_number'] }}</td>
            <th>Révision OF</th>
            <td>{{ $data['of_revision'] ?: '—' }}</td>
        </tr>
        <tr>
            <th>Bon de livraison</th>
            <td>{{ $data['delivery_note_reference'] ?: '—' }}</td>
            <th>Client</th>
            <td>{{ $data['client_name'] ?: '—' }}</td>
        </tr>
        <tr>
            <th>Validation</th>
            <td>{{ $formatDate($data['validated_at']) }} — {{ $data['validated_by'] ?: '—' }}</td>
            <th>Nombre de colis</th>
            <td>{{ $data['package_count'] }}</td>
        </tr>
    </table>

    <h2>Contenu des colis</h2>
    @forelse ($data['packages'] as $package)
        <div class="package">
            <h3>{{ $package['code'] }} — {{ $package['type'] }}{{ $package['label'] ? ' — '.$package['label'] : '' }}</h3>
            <div class="small muted">
                Poids : {{ $package['weight_kg'] !== null ? $formatDecimal($package['weight_kg']).' kg' : '—' }}
                — Dimensions : {{ $package['length_cm'] !== null ? $formatDecimal($package['length_cm']) : '—' }}
                × {{ $package['width_cm'] !== null ? $formatDecimal($package['width_cm']) : '—' }}
                × {{ $package['height_cm'] !== null ? $formatDecimal($package['height_cm']) : '—' }} cm
                @if ($package['seal_number']) — Plomb : {{ $package['seal_number'] }} @endif
            </div>
            <table class="data">
                <thead>
                    <tr>
                        <th>Repère</th>
                        <th>Désignation</th>
                        <th class="right">Quantité</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($package['contents'] as $content)
                        <tr>
                            <td><strong>{{ $content['mark'] }}</strong></td>
                            <td>{{ $content['designation'] }}</td>
                            <td class="right">{{ $formatQty($content['quantity']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">Colis vide.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2">Total colis</th>
                        <th class="right">{{ $formatQty($package['total_quantity']) }}</th>
                    </tr>
                </tfoot>
            </table>
            @if ($package['notes'])
                <div class="small">{{ $package['notes'] }}</div>
            @endif
        </div>
    @empty
        <p class="muted">Aucun colis.</p>
    @endforelse

    <div class="footer">
        <div>Document généré depuis les données persistées — {{ $data['company_name'] }} — {{ $data['shipment_number'] }} — {{ $data['work_order_number'] ?: 'OF non renseigné' }}.</div>
        <div class="small">Révision documentaire : {{ $data['of_revision'] ?: '—' }} — gabarit {{ $data['template_version'] }}.</div>
    </div>
</body>
</html>
