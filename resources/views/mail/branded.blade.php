<!doctype html>
<html>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <title>{{ $subject }}</title>
    </head>
    <body style="margin:0;padding:0;background:#f3f4f6;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#111418">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px">
            <tr>
                <td align="center">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">
                        <tr>
                            <td style="background:{{ $primary }};color:{{ $onPrimary }};padding:18px 24px;font-size:16px;font-weight:600">
                                @if ($logoUrl)
                                    <img src="{{ $logoUrl }}" alt="" height="32" style="height:32px;vertical-align:middle;margin-right:10px;border-radius:6px;background:#fff" />
                                @endif
                                {{ $companyName }}
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:24px;font-size:15px;line-height:1.55">
                                <h1 style="margin:0 0 16px;font-size:18px">{{ $subject }}</h1>
                                {!! $bodyHtml !!}
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:16px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.5">
                                @foreach ($footerLines as $line)
                                    {{ $line }}@unless ($loop->last)<br />@endunless
                                @endforeach
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
