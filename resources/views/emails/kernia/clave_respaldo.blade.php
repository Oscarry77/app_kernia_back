@extends('emails.kernia.layout', ['titulo' => 'Contraseña de tu respaldo'])

@section('contenido')
<p style="margin:0 0 16px;">Hola, equipo de <b>{{ $cliente }}</b>:</p>

<p style="margin:0 0 16px;">Esta es la contraseña para abrir el respaldo de <b>{{ $app }}</b> que te enviamos en el correo anterior:</p>

<p style="margin:0 0 16px;padding:14px;background:#1f2328;color:#ffffff;border-radius:6px;font-family:Consolas,Menlo,monospace;font-size:18px;letter-spacing:1px;text-align:center;word-break:break-all;">{{ $clave }}</p>

<p style="margin:0 0 16px;padding:12px 14px;background:#fdf3ea;border-radius:6px;font-size:14px;">
<b>Guárdala en un lugar seguro</b> (por ejemplo, un gestor de contraseñas): sin ella el archivo no se puede abrir.
Si la pierdes, pide a tu asesor que te la reenvíe; lo hará solo a este mismo correo y con autorización interna.
Tienes hasta el <b>{{ $fechaLimite }}</b> para descargar el respaldo.</p>

<p style="margin:0;font-size:14px;color:#6b6258;">No compartas este correo. Kernia nunca te pedirá esta contraseña por teléfono ni por correo.</p>
@endsection
