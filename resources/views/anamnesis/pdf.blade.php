<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('anamnesis.title') }}</title>
    <style>
        @page { margin: 24mm 18mm 22mm 18mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11pt;
            color: #1f2937;
            line-height: 1.5;
        }

        h1, h2, h3 { color: #0d3a40; margin: 0; }
        a { color: #0d6e6c; text-decoration: none; }

        .header {
            border-bottom: 2px solid #0ABBA5;
            padding-bottom: 10pt;
            margin-bottom: 16pt;
        }
        .header h1 { font-size: 18pt; font-weight: 700; }
        .header .subtitle { color: #6b7280; font-size: 10pt; margin-top: 2pt; }
        .header .brand {
            float: right; text-align: right;
            font-size: 9pt; color: #0ABBA5; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
        }

        .meta {
            margin-bottom: 16pt;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 4pt;
            padding: 8pt 10pt;
            font-size: 10pt;
        }
        .meta-row { display: block; padding: 1pt 0; }
        .meta-row .label {
            display: inline-block; width: 120pt;
            color: #6b7280; font-weight: 600;
            text-transform: uppercase; font-size: 8pt; letter-spacing: 0.04em;
        }
        .meta-row .value { color: #111827; }

        .section { margin-bottom: 14pt; page-break-inside: avoid; }
        .section h2 {
            font-size: 11pt; font-weight: 700; color: #0ABBA5;
            text-transform: uppercase; letter-spacing: 0.06em;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 3pt; margin-bottom: 8pt;
        }

        .field {
            margin-bottom: 10pt;
            page-break-inside: avoid;
        }
        .field .label {
            font-weight: 700; color: #111827;
            font-size: 10pt; margin-bottom: 2pt;
        }
        .field .value {
            color: #374151;
            white-space: pre-wrap;
        }
        .field .value.empty { color: #9ca3af; font-style: italic; }

        table.health {
            width: 100%; border-collapse: collapse;
            font-size: 10pt; margin-top: 6pt;
        }
        table.health th, table.health td {
            border-bottom: 1px solid #e5e7eb;
            padding: 5pt 6pt; text-align: left;
            vertical-align: top;
        }
        table.health th {
            background: #f1f5f9;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            font-weight: 700;
        }
        table.health td.value { font-weight: 700; color: #111827; }

        .pill {
            display: inline-block;
            padding: 1pt 6pt;
            border-radius: 9pt;
            font-size: 8pt;
            font-weight: 700;
        }
        .pill-below { background: #fef3c7; color: #92400e; }
        .pill-normal { background: #d1fae5; color: #065f46; }
        .pill-above { background: #e0f2fe; color: #075985; }

        .footer {
            position: fixed; left: 0; right: 0; bottom: -10mm;
            font-size: 8pt; color: #6b7280;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            padding-top: 4pt;
        }
        .footer .disclaimer {
            font-style: italic;
            margin-bottom: 2pt;
        }
        .footer .meta {
            background: none; border: none; padding: 0;
            font-size: 8pt;
        }
    </style>
</head>
<body>
    <div class="header">
        <span class="brand">Bağyt AI</span>
        <h1>{{ __('anamnesis.title') }}</h1>
        <div class="subtitle">{{ __('anamnesis.subtitle') }}</div>
    </div>

    <div class="meta">
        <span class="meta-row">
            <span class="label">{{ __('anamnesis.patient_name') }}</span>
            <span class="value">{{ $patient['name'] ?? '—' }}</span>
        </span>
        @if(!empty($patient['email']))
        <span class="meta-row">
            <span class="label">{{ __('anamnesis.patient_email') }}</span>
            <span class="value">{{ $patient['email'] }}</span>
        </span>
        @endif
        @if(!empty($patient['age']))
        <span class="meta-row">
            <span class="label">{{ __('anamnesis.patient_age') }}</span>
            <span class="value">{{ __('anamnesis.years_old', ['years' => $patient['age']]) }}</span>
        </span>
        @endif
        @if(!empty($patient['sex']))
        <span class="meta-row">
            <span class="label">{{ __('anamnesis.patient_sex') }}</span>
            <span class="value">{{ __('anamnesis.sex.' . $patient['sex'], [], $locale) ?? $patient['sex'] }}</span>
        </span>
        @endif
        <span class="meta-row">
            <span class="label">{{ __('anamnesis.generated_at') }}</span>
            <span class="value">{{ $generatedAt }}</span>
        </span>
        @if(!empty($chatTitle))
        <span class="meta-row">
            <span class="label">{{ __('anamnesis.chat_title') }}</span>
            <span class="value">{{ $chatTitle }}</span>
        </span>
        @endif
    </div>

    <div class="section">
        @foreach($fields as $key => $value)
            <div class="field">
                <div class="label">{{ __('anamnesis.fields.' . $key) }}</div>
                @if($value !== null && $value !== '')
                    <div class="value">{{ $value }}</div>
                @else
                    <div class="value empty">{{ __('anamnesis.not_provided') }}</div>
                @endif
            </div>
        @endforeach
    </div>

    @if(!empty($healthRows))
    <div class="section">
        <h2>{{ __('anamnesis.health_section') }}</h2>
        <p style="font-size: 9pt; color: #6b7280; margin: 0 0 6pt 0;">{{ __('anamnesis.health_intro') }}</p>
        <table class="health">
            <thead>
                <tr>
                    <th>{{ __('anamnesis.health.metric') }}</th>
                    <th>{{ __('anamnesis.health.value') }}</th>
                    <th>{{ __('anamnesis.health.norm') }}</th>
                    <th>{{ __('anamnesis.health.avg7d') }}</th>
                    <th>{{ __('anamnesis.health.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($healthRows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="value">{{ $row['value'] }}</td>
                    <td>{{ $row['norm'] }}</td>
                    <td>{{ $row['avg7d'] }}</td>
                    <td>
                        @if($row['status'])
                            <span class="pill pill-{{ $row['status'] }}">
                                {{ __('anamnesis.status.' . $row['status']) }}
                            </span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="footer">
        <div class="disclaimer">{{ __('anamnesis.footer_disclaimer') }}</div>
        <div>{{ __('anamnesis.footer_generated_by') }} · {{ $generatedAt }}</div>
    </div>
</body>
</html>
