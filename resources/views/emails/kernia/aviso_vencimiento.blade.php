@extends('emails.kernia.layout', ['titulo' => $suspendida ? 'Servicio suspendido' : 'Aviso de vencimiento'])

@section('contenido')
<p style="margin:0 0 16px;">Hola, equipo de <b>{{ $cliente }}</b>:</p>

@if ($suspendida)
<p style="margin:0 0 16px;">Tu servicio de <b>{{ $app }}</b>@if ($plan) ({{ $plan }})@endif quedó <b>suspendido</b> porque no hemos registrado
el pago que vencía el <b>{{ $fechaPago }}</b>. Tu información está intacta.</p>

<p style="margin:0 0 16px;">En cuanto registremos tu pago, el servicio se reactiva de inmediato. Si ya pagaste, envía tu comprobante a tu asesor.</p>
@else
<p style="margin:0 0 16px;">Te recordamos que tu suscripción de <b>{{ $app }}</b>@if ($plan) ({{ $plan }})@endif
@if ($diasRestantes <= 0)
<b>vence hoy</b>, {{ $fechaPago }}.
@elseif ($diasRestantes === 1)
<b>vence mañana</b>, {{ $fechaPago }}.
@else
vence el <b>{{ $fechaPago }}</b>, en <b>{{ $diasRestantes }} días</b>.
@endif
</p>

<p style="margin:0 0 16px;">Para que tu servicio continúe sin interrupción, realiza tu pago antes de esa fecha. Si no se registra, el servicio se
suspende a las 00:00 (hora del centro de México) del día de vencimiento. Tu información nunca se pierde por una suspensión.</p>
@endif

@if ($asesor)
<p style="margin:0 0 16px;">Tu asesor, <b>{{ $asesor['nombre'] }}</b>@if ($asesor['email']) ({{ $asesor['email'] }})@endif, puede ayudarte con tu pago o cualquier duda.</p>
@endif

<p style="margin:0;">Gracias por confiar en Kernia. Seguimos a tus órdenes.</p>
@endsection
