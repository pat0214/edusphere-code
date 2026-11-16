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

    public function __construct(string $mode = 'self_hosted', string $domain = '')
    {
        $this->mode = $mode;
        $this->domain = $domain;
    }

    public static function validMode(string $mode): bool
    {
        return in_array($mode, ['public', 'self_hosted'], true);
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
        return (bool)(
            self::validDomain($this->domain)
            && getenv('JITSI_JWT_APP_ID')
            && is_string(getenv('JITSI_JWT_SECRET'))
            && strlen((string)getenv('JITSI_JWT_SECRET')) >= 32
        );
    }

    public function createRoom(): array
    {
        if (!self::validMode($this->mode)) throw new RuntimeException('Unsupported Jitsi provider mode.');
        if ($this->mode === 'public' && $this->domain !== 'meet.jit.si') throw new RuntimeException('Public Jitsi must use meet.jit.si.');
        if ($this->mode === 'self_hosted' && !self::validDomain($this->domain)) throw new RuntimeException('Configure a valid self-hosted Jitsi domain.');
        if (!$this->configured()) throw new RuntimeException('Configure the selected Jitsi provider credentials on the backend.');

        $randomName = 'edusphere-' . bin2hex(random_bytes(16));

        return ['provider' => $this->mode, 'domain' => $this->domain, 'roomName' => $randomName];
    }

    public function joinCredentials(string $roomName, string $userId, string $displayName, bool $moderator, string $endsAt): array
    {
        $endTimestamp = strtotime($endsAt . ' UTC');
        if ($endTimestamp === false || $endTimestamp <= time()) throw new RuntimeException('This live session has ended.');

        $token = $this->mode === 'self_hosted'
            ? $this->selfHostedToken($roomName, $userId, $displayName, $moderator, $endTimestamp)
            : null;

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
        return $this->signJwt($header, $claims, (string)getenv('JITSI_JWT_SECRET'));
    }

    private function signJwt(array $header, array $claims, string $secret): string
    {
        $encodedHeader = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $message = $encodedHeader . '.' . $encodedClaims;
        $signature = hash_hmac('sha256', $message, $secret, true);
        return $message . '.' . self::base64Url($signature);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
