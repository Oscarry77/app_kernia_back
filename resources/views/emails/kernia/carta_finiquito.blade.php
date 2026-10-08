@extends('emails.kernia.layout', ['titulo' => 'Tu respaldo está listo'])

@section('contenido')
<p style="margin:0 0 16px;">Hola, equipo de <b>{{ $cliente }}</b>:</p>

<p style="margin:0 0 16px;">Gracias por haber confiado en <b>{{ $app }}</b> y en Kernia. Fue un gusto acompañarlos.
Tal como lo acordamos, preparamos el respaldo de su información para que la conserven.</p>

<p style="margin:0 0 8px;"><b>Qué contiene</b></p>
<p style="margin:0 0 8px;">
  {{ $alcance === 'empresa' ? 'La información de:' : 'Toda la información de su cuenta, incluidas las empresas:' }}
  @foreach ($empresas as $e){{ $e['nombre'] }} ({{ $e['rfc'] }})@if (! $loop->last), @endif @endforeach.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;font-size:14px;">
  @foreach ($contenido as $c)
    <tr>
      <td style="padding:4px 16px 4px 0;border-bottom:1px solid #eee7dd;">{{ $c['archivo'] }}</td>
      <td style="padding:4px 0;border-bottom:1px solid #eee7dd;text-align:right;">{{ number_format($c['registros']) }} registros</td>
    </tr>
  @endforeach
</table>
<p style="margin:0 0 16px;color:#6b6258;font-size:14px;">Incluye además los XML originales de sus comprobantes fiscales (CFDI) y un
documento LEEME que explica cada archivo. La información viene en archivos CSV, que se abren con Excel o con cualquier hoja de cálculo.</p>

<p style="margin:0 0 8px;"><b>Cómo descargarlo</b></p>
<ol style="margin:0 0 16px;padding-left:20px;">
  <li>Entra a {{ $app }} con tu usuario de administrador y abre la sección <b>Descargas</b>.</li>
  <li>Descarga el archivo. Tienes hasta el <b>{{ $fechaLimite }}</b>.</li>
  <li>Ábrelo con <b>7-Zip</b> (gratuito, <a href="https://www.7-zip.org" style="color:#C2661D;">7-zip.org</a>) o WinRAR, con la contraseña
      que te enviamos <b>en un correo aparte</b>. El explorador de Windows no puede abrir este tipo de archivo cifrado.</li>
</ol>

<p style="margin:0 0 16px;font-size:13px;color:#6b6258;">Para comprobar que el archivo está completo y no fue alterado, su huella SHA-256 es:<br>
<code style="font-size:12px;word-break:break-all;color:#1f2328;">{{ $huella }}</code></p>

<p style="margin:0 0 8px;"><b>Después del plazo</b></p>
<p style="margin:0 0 16px;">Si no alcanzas a descargarlo, guardaremos el archivo cifrado hasta el <b>{{ $fechaEliminacion }}</b>; en ese periodo
puedes pedírselo a tu asesor o a nuestro equipo de soporte. Después de esa fecha la información se elimina de forma definitiva de nuestros servidores.</p>

<p style="margin:0 0 16px;padding:12px 14px;background:#fdf3ea;border-radius:6px;font-size:14px;">
<b>Importante:</b> las disposiciones fiscales (artículo 30 del Código Fiscal de la Federación) obligan a conservar la contabilidad y los
comprobantes fiscales durante cinco años. Les recomendamos guardar este respaldo en un lugar seguro durante ese tiempo.</p>

@if ($enlaceFormulario)
<p style="margin:0 0 16px;">Nos ayudaría mucho conocer su opinión para seguir mejorando. Son menos de dos minutos:
<a href="{{ $enlaceFormulario }}" style="color:#C2661D;font-weight:600;">contestar la encuesta de salida</a>.</p>
@endif

<p style="margin:0;">Gracias de nuevo. Si en el futuro podemos ayudarles otra vez, seguimos a sus órdenes.</p>
<p style="margin:16px 0 0;">Atentamente,<br><b>El equipo de Kernia</b></p>
@endsection
