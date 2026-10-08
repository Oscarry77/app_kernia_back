{{-- (07-oct-2026) Plantilla base de los correos de Kernia. Estilos en línea: los clientes de correo ignoran <style>. --}}
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titulo ?? 'Kernia' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f1ec;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2328;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ec;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e7e1d8;">
      <tr>
        <td style="background:#C2661D;padding:18px 28px;color:#ffffff;font-size:20px;font-weight:600;letter-spacing:.5px;">Kernia</td>
      </tr>
      <tr>
        <td style="padding:28px;font-size:15px;line-height:1.6;">
          @yield('contenido')
        </td>
      </tr>
      <tr>
        <td style="padding:16px 28px;background:#faf8f5;border-top:1px solid #eee7dd;font-size:12px;line-height:1.5;color:#6b6258;">
          Este correo lo envió Kernia de forma automática.
          @isset($asesor)
            Si tienes dudas, escribe a tu asesor: {{ $asesor['nombre'] }}@if(!empty($asesor['email'])) ({{ $asesor['email'] }})@endif.
          @endisset
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
