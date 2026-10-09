@extends('emails.kernia.layout', ['titulo' => 'Tu plan ya cambió'])

@section('contenido')
<p style="margin:0 0 16px;">Hola, equipo de <b>{{ $cliente }}</b>:</p>

<p style="margin:0 0 16px;">Te confirmamos que tu plan de <b>{{ $app }}</b> ya es <b>{{ $planNuevo }}</b> y que tu servicio está disponible de nuevo.</p>

@if ($sinSeleccion)
<p style="margin:0 0 16px;">Como no recibimos la lista de empresas que deseas conservar, <b>todas tus empresas quedaron bloqueadas por el plan</b>.
Tu información está intacta. En cuanto le indiques a tu asesor cuáles deseas usar, las reactivamos.</p>
@else
  @if (count($disponibles))
<p style="margin:0 0 8px;"><b>Empresas disponibles:</b></p>
<ul style="margin:0 0 16px;padding-left:20px;">
  @foreach ($disponibles as $e)
    <li>{{ $e['nombre'] }}@if ($e['rfc']) <span style="color:#6b6258;">({{ $e['rfc'] }})</span>@endif</li>
  @endforeach
</ul>
  @endif
@endif

@if (count($bloqueadas))
<p style="margin:0 0 8px;"><b>Empresas bloqueadas por el plan</b> <span style="color:#6b6258;">(nadie entra, pero sus datos se conservan intactos):</span></p>
<ul style="margin:0 0 16px;padding-left:20px;">
  @foreach ($bloqueadas as $e)
    <li>{{ $e['nombre'] }}@if ($e['rfc']) <span style="color:#6b6258;">({{ $e['rfc'] }})</span>@endif</li>
  @endforeach
</ul>
<p style="margin:0 0 16px;">Puedes recuperarlas subiendo de plan o contratando empresas adicionales. Los accesos de tus usuarios a esas empresas se conservan
y vuelven en cuanto se reactiven.</p>
@endif

@if ($asesor)
<p style="margin:0 0 16px;">Tu asesor, <b>{{ $asesor['nombre'] }}</b>@if ($asesor['email']) ({{ $asesor['email'] }})@endif, puede ayudarte con cualquier duda.</p>
@endif

<p style="margin:0;">Gracias por confiar en Kernia. Seguimos a tus órdenes.</p>
@endsection
