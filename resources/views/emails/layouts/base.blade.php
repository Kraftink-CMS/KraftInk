<!DOCTYPE html>
<html lang="{{ $mailLocale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $mailPalette['colorScheme'] }}">
    <meta name="supported-color-schemes" content="{{ $mailPalette['colorScheme'] }}">
    <title>{{ view()->yieldContent('subject', $mailSiteName) }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: {{ $mailPalette['background'] }};
            color: {{ $mailPalette['text'] }};
            -webkit-text-size-adjust: 100%;
        }
        body, .mail-wrapper, .mail-body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.65;
        }
        table {
            border-collapse: collapse;
            mso-table-lspace: 0;
            mso-table-rspace: 0;
        }
        .mail-wrapper {
            width: 100%;
            background-color: {{ $mailPalette['background'] }};
        }
        .mail-container {
            width: 100%;
            max-width: 600px;
        }
        .mail-brand {
            padding: 0 0 24px;
            font-family: {!! $mailPalette['headingFont'] !!};
            font-size: 22px;
            font-weight: bold;
        }
        .mail-brand a {
            color: {{ $mailPalette['text'] }};
            text-decoration: none;
        }
        .mail-body {
            padding: 32px;
            background-color: {{ $mailPalette['surface'] }};
            color: {{ $mailPalette['text'] }};
            border: 1px solid {{ $mailPalette['border'] }};
            border-top: 4px solid {{ $mailPalette['accent'] }};
            word-wrap: break-word;
        }
        h1 {
            margin: 0 0 24px;
            font-family: {!! $mailPalette['headingFont'] !!};
            font-size: 24px;
            line-height: 1.35;
            font-weight: bold;
        }
        p {
            margin: 0 0 16px;
        }
        a {
            color: {{ $mailPalette['accent'] }};
        }
        .mail-action {
            margin: 24px 0;
        }
        .mail-button-cell {
            background-color: {{ $mailPalette['accent'] }};
            border-radius: 4px;
            text-align: center;
        }
        .mail-button {
            display: inline-block;
            border: 12px solid {{ $mailPalette['accent'] }};
            padding: 0 8px;
            border-radius: 4px;
            background-color: {{ $mailPalette['accent'] }};
            color: {{ $mailPalette['accentText'] }};
            font-size: 15px;
            line-height: 1.5;
            font-weight: bold;
            text-decoration: none;
            mso-padding-alt: 0;
        }
        .mail-muted, .mail-link-help {
            color: {{ $mailPalette['muted'] }};
            font-size: 13px;
            line-height: 1.6;
        }
        .mail-link {
            word-break: break-all;
            overflow-wrap: anywhere;
        }
        .mail-code, .mail-panel {
            padding: 16px;
            border: 1px solid {{ $mailPalette['border'] }};
            background-color: {{ $mailPalette['soft'] }};
            color: {{ $mailPalette['text'] }};
        }
        .mail-code {
            font-family: Consolas, 'Courier New', monospace;
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 2px;
            word-break: break-all;
        }
        .mail-footer {
            padding: 24px 16px 0;
            color: {{ $mailPalette['muted'] }};
            font-size: 12px;
            text-align: center;
        }
        @media only screen and (max-width: 600px) {
            .mail-body {
                padding: 24px 20px !important;
            }
        }
    </style>
    @stack('mail-styles')
</head>
<body>
    @hasSection('preview')
        <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">{{ view()->yieldContent('preview') }}</div>
    @endif
    <table role="presentation" class="mail-wrapper" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding:32px 12px">
                <!--[if mso]><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
                <table role="presentation" class="mail-container" width="100%" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td class="mail-brand" align="center"><a href="{{ url('/') }}">{{ $mailSiteName }}</a></td>
                    </tr>
                    <tr>
                        <td class="mail-body">@yield('content')</td>
                    </tr>
                    <tr>
                        <td class="mail-footer">{{ $mailFooter }}</td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
