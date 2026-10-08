@extends('emails.kernia.layout', ['titulo' => 'Cambio de plan'])

@section('contenido')
<p style="margin:0 0 16px;">Hola, equipo de <b>{{ $cliente }}</b>:</p>

<p style="margin:0 0 16px;">Te confirmamos que tu plan de <b>{{ $app }}</b> cambiará de <b>{{ $planActual }}</b> a <b>{{ $planNuevo }}</b>
a partir del <b>{{ $fechaEfectiva }}</b>, a las 00:00 (hora del centro de México).</p>

@if (count($pierde) || count($limites))
<p style="margin:0 0 8px;"><b>Qué cambia:</b></p>
<ul style="margin:0 0 16px;padding-left:20px;">
  @if (count($pierde))
    <li>Dejarás de tener: {{ implode(', ', $pierde) }}. <span style="color:#6b6258;">Tu información de esos módulos se conserva; solo deja de estar visible.</span></li>
  @endif
  @foreach ($limites as $l)
    <li>{{ $l['nombre'] }}: de {{ $l['antes'] }} a <b>{{ $l['despues'] }}</b>.</li>
  @endforeach
</ul>
@endif

<p style="margin:0 0 16px;">Hasta esa fecha conservas todo lo que incluye tu plan actual. Si necesitas ajustar algo antes del cambio,
tu asesor puede ayudarte.</p>

<p style="margin:0;">Gracias por confiar en Kernia. Seguimos a tus órdenes.</p>
@endsection
