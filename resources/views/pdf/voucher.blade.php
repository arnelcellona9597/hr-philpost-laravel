{{-- Disbursement voucher PDF. DejaVu Sans carries every character, and tables stand in for flexbox. --}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <title>Disbursement voucher — {{ $remittance->label }}</title>
        <style>
            @page { margin: 36pt; }
            body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #121419; line-height: 1.3; }
        </style>
    </head>
    <body>
        @include('remittances._voucher')
    </body>
</html>
