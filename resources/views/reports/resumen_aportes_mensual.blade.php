<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle de Aportes</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            margin: 28px;
            color: #000;
            font-size: 12px;
        }

        .container {
            max-width: 800px;
            margin: auto;
            border: 1px solid #ccc;
            padding: 18px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        .header-table td {
            border: none;
            vertical-align: middle;
            padding: 4px;
        }

        .logo {
            width: 72px;
            max-height: 72px;
        }

        .company {
            text-align: center;
        }

        .company h2 {
            margin: 0;
            font-size: 17px;
        }

        .company p {
            font-size: 10px;
            margin: 2px 0;
        }

        .date {
            margin-top: 14px;
        }

        .bold {
            font-weight: bold;
        }

        .ref {
            text-align: center;
            margin: 16px 0;
            font-weight: bold;
            text-decoration: underline;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table.data, table.data td, table.data th {
            border: 1px solid #000;
        }

        table.data td, table.data th {
            padding: 5px;
            font-size: 11px;
        }

        .table-title {
            background: #ddd;
            text-align: center;
            font-weight: bold;
        }

        .right {
            text-align: right;
        }

        .account-box {
            border: 1px solid #000;
            margin-top: 12px;
            padding: 8px;
            text-align: center;
            font-size: 11px;
        }

        .footer {
            margin-top: 22px;
        }

        .signature {
            margin-top: 48px;
        }

        .signature-line {
            border-top: 1px solid #000;
            width: 240px;
            margin-top: 32px;
        }

        .contact {
            margin-top: 22px;
            font-size: 10px;
            text-align: center;
            border-top: 1px solid #000;
            padding-top: 8px;
        }
    </style>
</head>
<body>

<div class="container">

    <table class="header-table">
        <tr>
            <td style="width: 88px;">
                @if (!empty($logoDataUri))
                    <img src="{{ $logoDataUri }}" class="logo" alt="Logo">
                @endif
            </td>
            <td class="company">
                <h2>{{ $consultoraTitulo }}</h2>
                @if (!empty($consultoraSubtitulo))
                    <p><b>{{ $consultoraSubtitulo }}</b></p>
                @endif
                <p>SERVICIOS DE CONTABILIDAD, AUDITORÍA, CONSULTORÍA Y ASESORÍA TRIBUTARIA</p>
                <p>CONSULTORÍA Y GESTIÓN EMPRESARIAL, ELABORACIÓN Y VALORACIÓN DE INVENTARIOS</p>
            </td>
        </tr>
    </table>

    <div class="date">
        {{ $fechaCarta }}
    </div>

    <p>
        <span class="bold">Señores:</span><br>
        @if (!empty($destinatarioNombre))
            {{ $destinatarioNombre }}<br>
        @endif
        {{ $destinatarioEmpresa }}<br>
        Presente.-
    </p>

    <div class="ref">
        {{ $refLinea1 }}<br>
        {{ $refLinea2 }}
    </div>

    <p>
        Estimados señores:<br>
        Mediante la presente adjunto el detalle de los aportes de la empresa {{ $cuerpoEmpresa }}.
    </p>

    <table class="data">
        <tr>
            <td colspan="2" class="table-title">{{ $tablaTitulo }}</td>
        </tr>
        <tr>
            <td>Total Ganado</td>
            <td class="right">{{ $fmt($totalGanado) }}</td>
        </tr>
        <tr>
            <td>Depósito CNS = 10% T.G</td>
            <td class="right">{{ $fmt($depositoCns) }}</td>
        </tr>
        <tr>
            <td>Aportes Gestora 19.92%</td>
            <td class="right">{{ $fmt($aportesGestora) }}</td>
        </tr>
        <tr>
            <td>Aporte Solidario Gestora</td>
            <td class="right">{{ $fmt($aporteSolidario) }}</td>
        </tr>
        <tr>
            <td>{{ $planillaEtiqueta }}</td>
            <td class="right">{{ $fmt($planillaMdt) }}</td>
        </tr>
        <tr>
            <td>Seprec Registro de poder a consultora</td>
            <td class="right">{{ $fmt($seprec) }}</td>
        </tr>
        <tr>
            <td class="bold">Total Aportes a pagar</td>
            <td class="right bold">{{ $fmt($totalAportes) }}</td>
        </tr>
    </table>

    <p>Por favor, prever que para los aportes de CNS, MDT y GESTORA los realicen a la siguiente cuenta:</p>

    <div class="account-box">
        {{ $cuentaTitular }}<br>
        {{ $cuentaBanco }}<br>
        <b>{{ $cuentaDetalle }}</b>
    </div>

    <div class="footer">
        Sin otro particular, me despido de usted con las consideraciones más distinguidas.<br><br>
        Atentamente,
    </div>

    <div class="signature">
        <div class="signature-line"></div>
        {{ $firmaNombre }}<br>
        {{ $firmaCargo }}
    </div>

    <div class="contact">
        @if (!empty($contactoDireccion))
            {{ $contactoDireccion }}<br>
        @endif
        @if (!empty($contactoTelefonos))
            {{ $contactoTelefonos }}<br>
        @endif
        @if (!empty($contactoCorreo))
            {{ $contactoCorreo }}<br>
        @endif
        {{ $contactoCiudad }}
    </div>

</div>

</body>
</html>
