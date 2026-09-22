<?php
/**
 * Configuration des notifications SMS.
 *
 * Provider disponible :
 *   - 'log'             : écrit dans un fichier (mode développement/test)
 *   - 'africas_talking' : API Africa's Talking (recommandé pour Afrique)
 *   - 'twilio'          : API Twilio (international)
 *
 * Le canal 'whatsapp' est accepté par l'API mais son envoi réel dépend
 * du provider choisi (Africa's Talking/Twilio peuvent router vers WhatsApp).
 *
 * Change 'default' pour activer le provider souhaité,
 * puis remplis les clés API correspondantes.
 */

return [
    'default' => 'log', // 'log' | 'africas_talking' | 'twilio'

    'providers' => [
        'africas_talking' => [
            'username' => '',
            'api_key'  => '',
        ],

        'twilio' => [
            'account_sid' => '',
            'auth_token'  => '',
            'from'        => '', // numéro Twilio
        ],

        'log' => [
            'path' => __DIR__ . '/../cron/notifications.log',
        ],
    ],
];
