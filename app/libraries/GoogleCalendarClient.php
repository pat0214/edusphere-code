<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Minimal, server-side Google OAuth and Calendar API client for classroom meetings. */
class GoogleCalendarClient
{
    public static function configured(): bool
    {
        return (bool)(getenv('GOOGLE_OAUTH_CLIENT_ID') && getenv('GOOGLE_OAUTH_CLIENT_SECRET') && getenv('GOOGLE_OAUTH_REDIRECT_URI'));
    }

    public static function authorizationUrl(string $state, string $challenge): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => getenv('GOOGLE_OAUTH_CLIENT_ID'),
            'redirect_uri' => getenv('GOOGLE_OAUTH_REDIRECT_URI'),
            'response_type' => 'code',
            'scope' => 'openid email https://www.googleapis.com/auth/calendar.events',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public static function exchangeCode(string $code, string $verifier): array
    {
        $tokens = self::form('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => getenv('GOOGLE_OAUTH_CLIENT_ID'),
            'client_secret' => getenv('GOOGLE_OAUTH_CLIENT_SECRET'),
            'redirect_uri' => getenv('GOOGLE_OAUTH_REDIRECT_URI'),
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ]);
        if (empty($tokens['access_token'])) throw new RuntimeException('Google authorization did not return an access token.');
        return $tokens;
    }

    public static function refreshAccessToken(string $refreshToken): string
    {
        $tokens = self::form('https://oauth2.googleapis.com/token', [
            'client_id' => getenv('GOOGLE_OAUTH_CLIENT_ID'),
            'client_secret' => getenv('GOOGLE_OAUTH_CLIENT_SECRET'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        if (empty($tokens['access_token'])) throw new RuntimeException('Google did not refresh the classroom calendar authorization.');
        return (string)$tokens['access_token'];
    }

    public static function profile(string $accessToken): array
    {
        [$status, $json] = self::request(
            'GET',
            'https://openidconnect.googleapis.com/v1/userinfo',
            $accessToken
        );
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            throw new RuntimeException('Could not read the connected Google account profile.');
        }
        return $json;
    }

    public static function createCalendarEvent(string $accessToken, array $event): array
    {
        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events?sendUpdates=all';
        [$status, $json] = self::request('POST', $url, $accessToken, $event);
        if ($status === 409 && !empty($event['id'])) {
            [$status, $json] = self::request(
                'GET',
                'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($event['id']),
                $accessToken
            );
        }
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            throw new RuntimeException('Google Calendar could not create the live classroom event (HTTP ' . $status . ').');
        }
        return [
            'eventId' => (string)($json['id'] ?? $event['id']),
        ];
    }

    public static function finishCalendarEvent(string $accessToken, string $eventId, string $endsAt): void
    {
        [$status] = self::request(
            'PATCH',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($eventId) . '?sendUpdates=all',
            $accessToken,
            ['end' => ['dateTime' => gmdate('c', strtotime($endsAt)), 'timeZone' => 'UTC']]
        );
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Google Calendar could not update the classroom event (HTTP ' . $status . ').');
        }
    }

    private static function form(string $url, array $values): array
    {
        $handle = curl_init($url);
        self::configure($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($values),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        [$status, $json] = self::execute($handle);
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            throw new RuntimeException('Google OAuth token request failed (HTTP ' . $status . ').');
        }
        return $json;
    }

    private static function request(string $method, string $url, string $accessToken, ?array $payload = null): array
    {
        $handle = curl_init($url);
        $headers = ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        self::configure($handle, $options);
        return self::execute($handle);
    }

    private static function configure($handle, array $options): void
    {
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_CONNECTTIMEOUT] = 5;
        $options[CURLOPT_TIMEOUT] = 20;
        $bundle = getenv('CURL_CA_BUNDLE');
        if (is_string($bundle) && $bundle !== '') $options[CURLOPT_CAINFO] = $bundle;
        curl_setopt_array($handle, $options);
    }

    private static function execute($handle): array
    {
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);
        if ($curlError !== 0 || !is_string($body)) throw new RuntimeException('Google service is temporarily unreachable.');
        $json = $body === '' ? [] : json_decode($body, true);
        return [$status, is_array($json) ? $json : null];
    }
}
