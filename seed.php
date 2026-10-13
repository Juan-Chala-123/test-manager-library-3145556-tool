<?php

declare(strict_types=1);

/**
 * Seeds demonstration data through the HTTP API only.
 */

$baseUrl = rtrim((string) getenv('API_BASE_URL'), '/');
$username = getenv('SEEDER_USERNAME');
$password = getenv('SEEDER_PASSWORD');

if ($baseUrl === '' || $username === false || $password === false || $username === '' || $password === '') {
    fwrite(STDERR, "API_BASE_URL, SEEDER_USERNAME and SEEDER_PASSWORD are required.\n");
    exit(1);
}

function request(string $method, string $url, ?string $token = null, ?array $payload = null): array
{
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the HTTP client.');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false) {
        throw new RuntimeException("HTTP request failed: {$error}");
    }

    $data = $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if ($status < 200 || $status >= 300) {
        $message = is_array($data) ? ($data['message'] ?? 'Unknown API error') : 'Unknown API error';
        throw new RuntimeException("{$method} {$url} failed ({$status}): {$message}");
    }

    return is_array($data) ? $data : [];
}

try {
    $login = request('POST', "{$baseUrl}/auth/login", null, [
        'username' => $username,
        'password' => $password,
    ]);
    $token = $login['access_token'] ?? throw new RuntimeException('The API login response has no access token.');

    $categories = request('GET', "{$baseUrl}/categories", $token)['items'] ?? [];
    if (count($categories) < 5) {
        throw new RuntimeException('The API returned fewer than the five required categories.');
    }

    $titles = [
        ['Cien años de soledad', 'Gabriel García Márquez'],
        ['La casa de los espíritus', 'Isabel Allende'],
        ['Sapiens', 'Yuval Noah Harari'],
        ['Breve historia del tiempo', 'Stephen Hawking'],
        ['Clean Code', 'Robert C. Martin'],
        ['El principito', 'Antoine de Saint-Exupéry'],
        ['La Odisea', 'Homero'],
        ['El universo en una cáscara de nuez', 'Stephen Hawking'],
    ];
    $books = [];
    for ($index = 0; $index < 40; $index++) {
        [$title, $author] = $titles[$index % count($titles)];
        $book = request('POST', "{$baseUrl}/books", $token, [
            'title' => "{$title} " . ($index + 1),
            'author' => $author,
            'isbn' => sprintf('978030747%05d', $index),
            'category_id' => $categories[$index % count($categories)]['id'],
            'total_copies' => 3 + ($index % 3),
        ]);
        $books[] = $book['id'];
    }

    $members = [];
    for ($index = 0; $index < 10; $index++) {
        $member = request('POST', "{$baseUrl}/members", $token, [
            'full_name' => 'Socio de demostración ' . ($index + 1),
            'document_number' => sprintf('DEMO-%04d', $index + 1),
        ]);
        $members[] = $member['id'];
    }

    for ($index = 0; $index < 15; $index++) {
        request('POST', "{$baseUrl}/loans", $token, [
            'member_id' => $members[$index % count($members)],
            'items' => [['book_id' => $books[$index]]],
        ]);
    }

    fwrite(STDOUT, "Demo data created through the API: 40 books, 10 members, 15 loans.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Seeder failed: {$error->getMessage()}\n");
    exit(1);
}
