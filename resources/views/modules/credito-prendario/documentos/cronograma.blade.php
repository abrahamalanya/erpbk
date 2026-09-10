<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cronograma de cuotas — Crédito #{{ $credito->id }}</title>
    <style>
        @page { margin: 4cm 1cm 3cm 3cm; }
        body { font-family: DejaVu Sans, sans-serif; }
    </style>
</head>
<body>
    @include('modules.credito-prendario.documentos._cronograma_cuerpo')
</body>
</html>
