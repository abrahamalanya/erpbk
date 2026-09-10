<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ficha socioeconómica — Crédito #{{ $credito->id }}</title>
    <style>
        @page { margin: 1.6cm 1cm 1.4cm 1cm; }
        body { font-family: DejaVu Sans, sans-serif; }
    </style>
</head>
<body>
    @include('modules.credito-hipotecario.documentos._ficha_cuerpo')
</body>
</html>
