<?php

return [
    'project_id' => env('FCM_PROJECT_ID'),

    // JSON da conta de serviço do Firebase em base64.
    'credentials_base64' => env('FCM_CREDENTIALS_BASE64'),

    // Tempo de vida do push: pedido de 5 minutos atrás já não deve tocar.
    'ttl_seconds' => 120,
];
