@extends('emails.kernia.layout', ['titulo' => 'Prueba del buzón'])

@section('contenido')
<p style="margin:0 0 16px;">Hola:</p>

<p style="margin:0 0 16px;">Este es un correo de prueba del buzón de Kernia, solicitado por <b>{{ $operador }}</b> desde el panel.</p>

<p style="margin:0;">Si lo recibiste, Kernia envía sus correos con las credenciales guardadas en la bóveda.</p>
@endsection
