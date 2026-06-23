<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans; text-align: center; }
        .box { border:1px solid #000; padding:20px; }
    </style>
</head>
<body>

<div class="box">

<h2>Payment Receipt</h2>

<p><strong>Invoice ID:</strong> {{ $invoice_id }}</p>
<p><strong>Student:</strong> {{ $student }}</p>
<p><strong>Amount:</strong> {{ number_format($amount,2) }}</p>
<p><strong>Date:</strong> {{ $date }}</p>

<hr>

<p>Thank you</p>

</div>

</body>
</html>