<?php
/**
 * Interface d'envoi SMS/WhatsApp — sans dépendance externe.
 *
 * Utilise file_get_contents avec stream context pour les appels HTTP.
 * Supporte plusieurs providers configurables par établissement/pays.
 *
 * Configuration dans backend/config/notifications.php :
 *   'default' => 'africas_talking',
 *   'providers' => [
 *       'africas_talking' => [ 'api_key' => '...', 'username' => '...' ],
 *       'twilio'          => [ 'account_sid' => '...', 'auth_token' => '...', 'from' => '...' ],
 *       'log'             => [ 'path' => __DIR__ . '/../cron/notifications.log' ],
 *   ]
 */

require_once __DIR__ . '/../config/database.php';

class SmsGateway {
    private array $config;

    public function __construct() {
        $configPath = __DIR__ . '/../config/notifications.php';
        $this->config = file_exists($configPath) ? require $configPath : ['default' => 'log', 'providers' => ['log' => ['path' => __DIR__ . '/../cron/notifications.log']]];
    }

    public function envoyer(string $contact, string $message, string $canal = 'sms'): bool {
        $provider = $this->config['default'] ?? 'log';
        $method = 'envoyer' . ucfirst($provider);
        if (method_exists($this, $method)) {
            return $this->$method($contact, $message, $canal);
        }
        return $this->envoyerLog($contact, $message, $canal);
    }

    /**
     * Africa's Talking — API REST sans bibliothèque
     * https://africastalking.com/
     */
    private function envoyerAfricasTalking(string $contact, string $message, string $canal): bool {
        $cfg = $this->config['providers']['africas_talking'] ?? [];
        if (empty($cfg['api_key']) || empty($cfg['username'])) return false;

        $data = [
            'username' => $cfg['username'],
            'to'       => $contact,
            'message'  => $message,
        ];
        if ($canal === 'whatsapp') {
            // Africa's Talking WhatsApp API
            $url = 'https://api.africastalking.com/version1/messaging';
        } else {
            $url = 'https://api.africastalking.com/version1/messaging';
        }

        return $this->httpPost($url, $data, [
            'apiKey: ' . $cfg['api_key'],
            'Accept: application/json',
        ]);
    }

    /**
     * Twilio — API REST sans bibliothèque
     * https://www.twilio.com/docs/sms
     */
    private function envoyerTwilio(string $contact, string $message, string $canal): bool {
        $cfg = $this->config['providers']['twilio'] ?? [];
        if (empty($cfg['account_sid']) || empty($cfg['auth_token'])) return false;

        $from = $cfg['from'] ?? '+15000000000';
        $data = [
            'From' => $from,
            'To'   => $contact,
            'Body' => $message,
        ];

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$cfg['account_sid']}/Messages.json";
        $auth = base64_encode($cfg['account_sid'] . ':' . $cfg['auth_token']);

        return $this->httpPost($url, $data, [
            "Authorization: Basic $auth",
        ]);
    }

    /**
     * Log local (fallback par défaut)
     */
    private function envoyerLog(string $contact, string $message, string $canal): bool {
        $path = $this->config['providers']['log']['path'] ?? __DIR__ . '/../cron/notifications.log';
        $line = "[" . date('Y-m-d H:i:s') . "] [$canal] À: $contact — $message\n";
        return file_put_contents($path, $line, FILE_APPEND) !== false;
    }

    /**
     * Appel HTTP POST avec file_get_contents (pas de curl)
     */
    private function httpPost(string $url, array $data, array $headers = []): bool {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n" . implode("\r\n", $headers),
                'content' => http_build_query($data),
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }

    /**
     * Test de connexion — vérifie qu'au moins un provider est configuré
     */
    public function estConfigure(): bool {
        $provider = $this->config['default'] ?? 'log';
        if ($provider === 'log') return true;
        $cfg = $this->config['providers'][$provider] ?? [];
        return !empty($cfg);
    }
}
