<?php

namespace APP\plugins\paymethod\multipay\classes;

class HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        $ch = curl_init();
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        if ($body !== null) {
            $headerLines[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception('HTTP request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $raw];
        }
        return ['status' => $status, 'body' => $decoded];
    }
}

