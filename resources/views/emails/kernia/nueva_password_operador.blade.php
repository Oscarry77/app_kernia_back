@extends('emails.kernia.layout', ['titulo' => 'Tu nueva contraseña'])

@section('contenido')
<p style="margin:0 0 16px;">Hola, {{ $nombre }}:</p>

<p style="margin:0 0 16px;">Esta es tu nueva contraseña de acceso al panel de Kernia:</p>

<p style="margin:0 0 16px;padding:14px;background:#1f2328;color:#ffffff;border-radius:6px;font-family:Consolas,Menlo,monospace;font-size:18px;letter-spacing:1px;text-align:center;word-break:break-all;">{{ $password }}</p>

<p style="margin:0 0 16px;">La contraseña anterior ya no es válida. Guárdala en tu gestor de contraseñas y no la compartas.</p>

<p style="margin:0;font-size:14px;color:#6b6258;">Si tú no pediste este cambio, avisa de inmediato al administrador de Kernia.</p>
@endsection
