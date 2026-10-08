@extends('emails.kernia.layout', ['titulo' => 'Recordatorio de descarga'])

@section('contenido')
<p style="margin:0 0 16px;">Hola, equipo de <b>{{ $cliente }}</b>:</p>

<p style="margin:0 0 16px;">Te recordamos que el respaldo de tu información de <b>{{ $app }}</b> está disponible para descargar
hasta el <b>{{ $fechaLimite }}</b> ({{ $diasRestantes === 1 ? 'mañana' : 'en '.$diasRestantes.' días' }}).</p>

<p style="margin:0 0 16px;">Entra a {{ $app }} con tu usuario de administrador, abre la sección <b>Descargas</b> y descarga el archivo.
Para abrirlo usa 7-Zip o WinRAR, con la contraseña que te enviamos en un correo aparte.</p>

<p style="margin:0;">Si ya lo descargaste, no necesitas hacer nada más. Seguimos a tus órdenes.</p>
@endsection
