{{-- The COS payroll sheet as a PDF, Legal landscape. --}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <title>Payroll — {{ $period->name }}</title>
        <style>
            @page { margin: 22pt 24pt; }
            body { margin: 0; font-family: 'DejaVu Sans', sans-serif; }
        </style>
    </head>
    <body>
        @include('payroll._register')
    </body>
</html>
