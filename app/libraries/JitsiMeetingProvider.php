<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

interface MeetingProviderInterface
{
    public function configured(): bool;
    public function createRoom(): array;
    public function joinCredentials(string $roomName, string $userId, string $displayName, bool $moderator, string $endsAt): array;
}

class JitsiMeetingProvider implements MeetingProviderInterface
{
    private $mode;
    private $domain;

    public function __construct(string $mode = 'jaas', string $domain = '8x8.vc')
    {
        $this->mode = $mode;
        $this->domain = $domain;
    }

    public static function validMode(string $mode): bool
    {
        return in_array($mode, ['public', 'self_hosted', 'jaas'], true);
    }

    public static function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $domain) === 1;
    }

    public static function settingsFromDatabase(array $settings): self
    {
        return new self((string)$settings['provider'], (string)$settings['domain']);
    }

    public function configured(): bool
    {
        if ($this->mode === 'public') return true;
        if ($this->mode === 'jaas') {
            $privateKey = self::jaasPrivateKey();
            if (!getenv('JAAS_APP_ID') || !getenv('JAAS_API_KEY_ID') || !$privateKey) return false;
            $key = openssl_pkey_get_private($privateKey);
            if ($key === false) return false;
            openssl_pkey_free($key);
            return true;
        }
        return (bool)(
            getenv('JITSI_JWT_APP_ID')
            && is_string(getenv('JITSI_JWT_SECRET'))
            && strlen((string)getenv('JITSI_JWT_SECRET')) >= 32
        );
    }

    public function createRoom(): array
    {
        if (!self::validMode($this->mode)) throw new RuntimeException('Unsupported Jitsi provider mode.');
        if ($this->mode === 'public' && $this->domain !== 'meet.jit.si') throw new RuntimeException('Public Jitsi must use meet.jit.si.');
        if ($this->mode === 'jaas' && $this->domain !== '8x8.vc') throw new RuntimeException('JaaS must use 8x8.vc.');
        if ($this->mode === 'self_hosted' && !self::validDomain($this->domain)) throw new RuntimeException('Configure a valid self-hosted Jitsi domain.');
        if (!$this->configured()) throw new RuntimeException('Configure the selected Jitsi provider credentials on the backend.');

        $randomName = 'edusphere-' . bin2hex(random_bytes(16));
        $roomName = $this->mode === 'jaas'
            ? rtrim((string)getenv('JAAS_APP_ID'), '/') . '/' . $randomName
            : $randomName;

        return ['provider' => $this->mode, 'domain' => $this->domain, 'roomName' => $roomName];
    }

    public function joinCredentials(string $roomName, string $userId, string $displayName, bool $moderator, string $endsAt): array
    {
        $endTimestamp = strtotime($endsAt . ' UTC');
        if ($endTimestamp === false || $endTimestamp <= time()) throw new RuntimeException('This live session has ended.');

        $token = null;
        if ($this->mode === 'jaas') {
            $token = $this->jaasToken($roomName, $userId, $displayName, $moderator, $endTimestamp);
        } elseif ($this->mode === 'self_hosted') {
            $token = $this->selfHostedToken($roomName, $userId, $displayName, $moderator, $endTimestamp);
        }

        $url = 'https://' . $this->domain . '/' . implode('/', array_map('rawurlencode', explode('/', $roomName)));
        if ($token !== null) $url .= '?jwt=' . rawurlencode($token);

        return [
            'provider' => $this->mode,
            'domain' => $this->domain,
            'roomName' => $roomName,
            'jwt' => $token,
            'url' => $url,
        ];
    }

    private function jaasToken(string $roomName, string $userId, string $displayName, bool $moderator, int $sessionEnd): string
    {
        $appId = (string)getenv('JAAS_APP_ID');
        $keyId = (string)getenv('JAAS_API_KEY_ID');
        $privateKey = self::jaasPrivateKey();
        $header = ['alg' => 'RS256', 'kid' => $appId . '/' . $keyId, 'typ' => 'JWT'];
        $claims = [
            'aud' => 'jitsi',
            'context' => [
                'user' => [
                    'id' => $userId,
                    'name' => mb_substr($displayName, 0, 100),
                    'moderator' => $moderator ? 'true' : 'false',
                ],
                'features' => [
                    'livestreaming' => false,
                    'recording' => false,
                    'transcription' => false,
                    'outbound-call' => false,
                ],
                'room' => ['regex' => false],
            ],
            'exp' => min(time() + 60, $sessionEnd),
            'iss' => 'chat',
            'nbf' => time() - 5,
            'room' => $roomName,
            'sub' => $appId,
        ];

        return $this->signJwt($header, $claims, $privateKey, OPENSSL_ALGO_SHA256);
    }

    private function selfHostedToken(string $roomName, string $userId, string $displayName, bool $moderator, int $sessionEnd): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims = [
            'aud' => 'jitsi',
            'context' => ['user' => [
                'id' => $userId,
                'name' => mb_substr($displayName, 0, 100),
                'moderator' => $moderator,
            ]],
            'exp' => min(time() + 60, $sessionEnd),
            'iss' => (string)getenv('JITSI_JWT_APP_ID'),
            'nbf' => time() - 5,
            'room' => $roomName,
            'sub' => $this->domain,
        ];
        return $this->signJwt($header, $claims, (string)getenv('JITSI_JWT_SECRET'), OPENSSL_ALGO_SHA256, true);
    }

    private function signJwt(array $header, array $claims, $key, int $algorithm, bool $hmac = false): string
    {
        $encodedHeader = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $message = $encodedHeader . '.' . $encodedClaims;
        if ($hmac) {
            $signature = hash_hmac('sha256', $message, (string)$key, true);
        } else {
            $privateKey = openssl_pkey_get_private((string)$key);
            if ($privateKey === false || !openssl_sign($message, $signature, $privateKey, $algorithm)) {
                throw new RuntimeException('JaaS JWT could not be signed. Check the backend private-key configuration.');
            }
        }
        return $message . '.' . self::base64Url($signature);
    }

    private static function jaasPrivateKey()
    {
        $value = getenv('JAAS_PRIVATE_KEY');
        if (!is_string($value) || trim($value) === '') return false;
        return str_replace('\\n', "\n", $value);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
