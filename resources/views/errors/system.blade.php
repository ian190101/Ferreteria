<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error {{ $error['http_status'] }}</title>
</head>
<body>
    <main>
        <h1>No se pudo completar la operación</h1>
        <p>{{ $error['explanation'] }}</p>
        <dl>
            <dt>Código</dt><dd>{{ $error['code'] }}</dd>
            <dt>Estado HTTP</dt><dd>{{ $error['http_status'] }}</dd>
            <dt>Tipo técnico</dt><dd>{{ $error['exception_type'] }}</dd>
            <dt>Código nativo</dt><dd>{{ $error['native_code'] }}</dd>
            <dt>Detalle técnico</dt><dd>{{ $error['technical_message'] }}</dd>
            <dt>Referencia</dt><dd>{{ $error['id'] }}</dd>
        </dl>
        <a href="/">Volver al inicio</a>
    </main>
</body>
</html>
