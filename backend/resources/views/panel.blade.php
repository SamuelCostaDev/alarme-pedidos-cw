@extends('layouts.app')

@section('subtitle', $installation->company_name ?? 'Sua loja')

@push('head')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
@endpush

@section('content')
@if (session('status'))
    <div class="flash">{{ session('status') }}</div>
@endif

<div class="card">
    <h2>Parear um celular</h2>
    @if ($pairingCode)
        <p>No app <strong>Alarme de Pedidos</strong>, toque em “Parear” e digite o código — ou aponte a câmera para o QR code.</p>
        <div class="pair">
            <div id="qr"></div>
            <div>
                <div class="code">{{ $pairingCode->code }}</div>
                <p>Válido até {{ $pairingCode->expires_at->timezone('America/Sao_Paulo')->format('H:i') }}. Use uma vez por celular.</p>
                <form method="post" action="{{ route('panel.code') }}">@csrf
                    <button class="btn btn-ghost">Gerar outro código</button>
                </form>
            </div>
        </div>
        <script>
            new QRCode(document.getElementById('qr'), { text: {!! json_encode($pairingLink, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, width: 160, height: 160 });
        </script>
    @else
        <ol>
            <li>Instale o app <strong>Alarme de Pedidos</strong> no celular que fica no balcão.</li>
            <li>Gere um código aqui e digite no app (ou escaneie o QR code).</li>
            <li>Pronto: os pedidos novos passam a tocar naquele celular.</li>
        </ol>
        <form method="post" action="{{ route('panel.code') }}">@csrf
            <button class="btn btn-primary">Gerar código de pareamento</button>
        </form>
    @endif
</div>

<div class="card">
    <h2>Celulares que tocam o alarme</h2>
    @if ($devices->isEmpty())
        <p>Nenhum celular pareado ainda.</p>
    @else
        <table>
            <tr><th>Celular</th><th>Último alarme</th><th>Situação</th><th></th></tr>
            @foreach ($devices as $device)
                <tr>
                    <td>{{ $device->name }}</td>
                    <td>{{ $device->last_push_at?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—' }}</td>
                    <td>
                        @if (! $device->fcm_token)
                            <span class="warn">Desconectado — abra o app de novo</span>
                        @elseif ($device->last_push_error)
                            <span class="warn">Falha no último envio</span>
                        @else
                            <span class="ok">Ativo</span>
                        @endif
                    </td>
                    <td>
                        <form method="post" action="{{ route('panel.device.delete', $device->id) }}"
                              onsubmit="return confirm('Remover este celular? Ele para de tocar.')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger">Remover</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
        <form method="post" action="{{ route('panel.test') }}" style="margin-top:16px">@csrf
            <button class="btn btn-primary">Tocar alarme de teste</button>
        </form>
    @endif
</div>

<form method="post" action="{{ route('panel.logout') }}">@csrf
    <button class="btn btn-ghost">Sair</button>
</form>
@endsection
