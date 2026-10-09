@extends('emails.kernia.layout', ['titulo' => $motivo === 'archivo' ? 'Respaldo de empresa' : 'Tu copia está lista'])

@section('contenido')
<p style="margin:0 0 16px;">Hola, equipo de <b>{{ $cliente }}</b>:</p>

@if ($motivo === 'archivo')
<p style="margin:0 0 16px;">Tal como lo solicitaron, preparamos el respaldo de la empresa <b>{{ $empresa }}</b> en <b>{{ $app }}</b>.
Cuando lo descarguen, o al vencer el plazo, la empresa se <b>archiva</b>: sus datos se retiran de {{ $app }} y se libera su lugar en su plan.
Sus demás empresas siguen operando igual.</p>
@else
<p style="margin:0 0 16px;">Tal como lo solicitaron, preparamos una copia de toda su información en <b>{{ $app }}</b>. Su servicio sigue
funcionando normalmente; esta copia es solo para que la conserven.</p>
@endif

@if (count($contenido))
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;font-size:14px;">
  @foreach ($contenido as $c)
    <tr>
      <td style="padding:4px 16px 4px 0;border-bottom:1px solid #eee7dd;">{{ $c['archivo'] }}</td>
      <td style="padding:4px 0;border-bottom:1px solid #eee7dd;text-align:right;">{{ number_format($c['registros']) }} registros</td>
    </tr>
  @endforeach
</table>
@endif

<p style="margin:0 0 8px;"><b>Cómo descargarlo</b></p>
<ol style="margin:0 0 16px;padding-left:20px;">
  <li>Entra a {{ $app }} con tu usuario de administrador y abre la sección <b>Descargas</b>.</li>
  <li>Descarga el archivo. Tienes hasta el <b>{{ $fechaLimite }}</b>.</li>
  <li>Ábrelo con <b>7-Zip</b> (gratuito, <a href="https://www.7-zip.org" style="color:#C2661D;">7-zip.org</a>) o WinRAR, con la contraseña
      que te enviamos <b>en un correo aparte</b>.</li>
</ol>

<p style="margin:0 0 16px;color:#6b6258;font-size:14px;">Huella SHA-256 del archivo, para comprobar que no cambió:<br>
<code style="word-break:break-all;">{{ $huella }}</code></p>

@if ($asesor)
<p style="margin:0 0 16px;">Tu asesor, <b>{{ $asesor['nombre'] }}</b>@if ($asesor['email']) ({{ $asesor['email'] }})@endif, puede ayudarte con cualquier duda.</p>
@endif

<p style="margin:0;">Gracias por confiar en Kernia. Seguimos a tus órdenes.</p>
@endsection
