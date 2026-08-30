<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

/**
 * Canonical JSON payload for POST /images/generations.
 *
 * Server validation remains authoritative: the API rejects unknown top level
 * fields, unknown models and upload records exceeding the aggregate 50 MiB
 * budget with 422. validate() is a local fast-fail helper only, listing
 * human-readable violations so a caller can short-circuit the round trip
 * without waiting for the server; it is not a substitute for the server
 * rules, and in particular it never resolves DNS, so a hostname that
 * resolves to a private address is only caught server side.
 *
 * The webhook checks run against the literal host after normalization:
 * lowercase, brackets stripped, IPv4-mapped IPv6 (dotted-quad and hex
 * forms) expanded to the embedded dotted quad, 0-padded IPv4 octets
 * normalized to their numeric value, IPv6 canonicalized (inet_ntop).
 * Loopback names (localhost, *.localhost) and literal
 * loopback/private/link-local/metadata addresses (IPv4 and IPv6,
 * including :: and ::1) are rejected. DNS rebinding remains out of
 * scope: the host is never resolved, so a name that resolves to a
 * private address is only caught server side.
 */
final readonly class ImageGenerationRequest
{
    /**
     * Supported output resolutions.
     *
     * @var list<string>
     */
    public const RESOLUTIONS = ['0.5k', '1k', '2k', '3k', '4k'];

    /**
     * Supported output aspect ratios.
     *
     * @var list<string>
     */
    public const ASPECT_RATIOS = ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '21:9', '9:21', 'as_input_image'];

    /**
     * Supported output formats.
     *
     * @var list<string>
     */
    public const FORMATS = ['png', 'jpeg', 'webp', 'svg'];

    /**
     * @param  list<InputImage>|null  $inputImages
     * @param  array<string, mixed>|null  $modelSettings
     * @param  list<string>|null  $providers
     */
    public function __construct(
        public string $model,
        public string $prompt,
        public string $webhookUrl,
        public ?array $inputImages = null,
        public ?InputImage $inputImage = null,
        public ?InputImage $maskImage = null,
        public ?InputImage $objectsImage = null,
        public ?string $resolution = null,
        public ?ImageDimensions $dimensions = null,
        public ?string $aspectRatio = null,
        public ?string $format = null,
        public ?int $quality = null,
        public ?array $modelSettings = null,
        public ?array $providers = null,
    ) {}

    /**
     * Runs the local fast-fail rules and returns the list of violations;
     * an empty list means the request passed every local check. Server
     * validation remains authoritative.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $violations = [];

        $prompt = trim($this->prompt);

        if ($prompt === '') {
            $violations[] = 'prompt must not be empty.';
        } elseif (mb_strlen($prompt) > 5000) {
            $violations[] = 'prompt must be at most 5000 characters.';
        }

        if ($this->inputImage !== null && $this->inputImages !== null) {
            $violations[] = 'input_image and input_images are mutually exclusive.';
        }

        if ($this->maskImage !== null || $this->objectsImage !== null) {
            if ($this->inputImage === null) {
                $violations[] = 'mask_image and objects_image require input_image.';
            }

            if ($this->inputImages !== null) {
                $violations[] = 'mask_image and objects_image cannot be combined with input_images.';
            }
        }

        if ($this->inputImages !== null && count($this->inputImages) > 10) {
            $violations[] = 'input_images must contain at most 10 images.';
        }

        if (mb_strlen($this->webhookUrl) > 2048) {
            $violations[] = 'webhook_url must be at most 2048 characters.';
        }

        $parts = parse_url($this->webhookUrl);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if ($scheme !== 'https') {
            $violations[] = 'webhook_url must use the https scheme.';
        }

        if (! is_string($host) || $host === '') {
            $violations[] = 'webhook_url must carry a resolvable host.';
        } elseif (self::isBlockedHost($host)) {
            $violations[] = 'webhook_url must not target loopback, private, link-local or metadata addresses.';
        }

        if ($this->resolution !== null && ! in_array($this->resolution, self::RESOLUTIONS, true)) {
            $violations[] = sprintf(
                'resolution "%s" is not supported; expected one of %s.',
                $this->resolution,
                implode(', ', self::RESOLUTIONS),
            );
        }

        if ($this->aspectRatio !== null && ! in_array($this->aspectRatio, self::ASPECT_RATIOS, true)) {
            $violations[] = sprintf(
                'aspect_ratio "%s" is not supported; expected one of %s.',
                $this->aspectRatio,
                implode(', ', self::ASPECT_RATIOS),
            );
        }

        if ($this->format !== null && ! in_array($this->format, self::FORMATS, true)) {
            $violations[] = sprintf(
                'format "%s" is not supported; expected one of %s.',
                $this->format,
                implode(', ', self::FORMATS),
            );
        }

        if ($this->quality !== null && ($this->quality < 1 || $this->quality > 100)) {
            $violations[] = 'quality must be between 1 and 100.';
        }

        if ($this->dimensions !== null) {
            if ($this->dimensions->width < 1) {
                $violations[] = 'dimensions.width must be at least 1.';
            }

            if ($this->dimensions->height < 1) {
                $violations[] = 'dimensions.height must be at least 1.';
            }
        }

        if ($this->providers !== null) {
            if ($this->providers === []) {
                $violations[] = 'providers must contain at least one provider.';
            } elseif (count($this->providers) !== count(array_unique($this->providers))) {
                $violations[] = 'providers must not contain duplicate entries.';
            }
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'model' => $this->model,
            'prompt' => $this->prompt,
            'webhook_url' => $this->webhookUrl,
        ];

        if ($this->inputImages !== null) {
            $payload['input_images'] = array_map(static fn (InputImage $image): array => $image->toArray(), $this->inputImages);
        }

        if ($this->inputImage !== null) {
            $payload['input_image'] = $this->inputImage->toArray();
        }

        if ($this->maskImage !== null) {
            $payload['mask_image'] = $this->maskImage->toArray();
        }

        if ($this->objectsImage !== null) {
            $payload['objects_image'] = $this->objectsImage->toArray();
        }

        if ($this->resolution !== null) {
            $payload['resolution'] = $this->resolution;
        }

        if ($this->dimensions !== null) {
            $payload['dimensions'] = $this->dimensions->toArray();
        }

        if ($this->aspectRatio !== null) {
            $payload['aspect_ratio'] = $this->aspectRatio;
        }

        if ($this->format !== null) {
            $payload['format'] = $this->format;
        }

        if ($this->quality !== null) {
            $payload['quality'] = $this->quality;
        }

        if ($this->modelSettings !== null) {
            $payload['model_settings'] = $this->modelSettings;
        }

        if ($this->providers !== null) {
            $payload['providers'] = $this->providers;
        }

        return $payload;
    }

    private static function isBlockedHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $host = trim($host, '[]');

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        $normalized = self::normalizeIpLiteral($host);

        if ($normalized === null) {
            // A DNS name or malformed literal: not classified. DNS
            // rebinding stays out of scope (the server resolves the
            // host, never this local check).
            return false;
        }

        if (str_contains($normalized, ':')) {
            return self::isBlockedIpv6($normalized);
        }

        return self::isBlockedIpv4($normalized);
    }

    /**
     * Normalizes an IP literal so the block lists see a canonical
     * address: IPv4-mapped IPv6 (RFC 4291 section 2.5.5.2, dotted-quad
     * and hex forms) is expanded to its embedded dotted quad, 0-padded
     * decimal octets are converted to their numeric value, and IPv6
     * literals are canonicalized through inet_pton/inet_ntop (so the
     * expanded loopback "0:0:0:0:0:0:0:1" is classified as "::1").
     * Returns null when the value is not an IP literal (DNS name or
     * malformed address).
     */
    private static function normalizeIpLiteral(string $host): ?string
    {
        if (str_starts_with($host, '::ffff:')) {
            $embedded = substr($host, strlen('::ffff:'));

            if (str_contains($embedded, '.')) {
                return self::normalizeIpv4Octets(explode('.', $embedded));
            }

            $hex = '';

            foreach (explode(':', $embedded) as $group) {
                if ($group === '' || strlen($group) > 4 || ! ctype_xdigit($group)) {
                    return null;
                }

                $hex .= str_pad($group, 4, '0', STR_PAD_LEFT);
            }

            if (strlen($hex) !== 8) {
                return null;
            }

            $packed = @hex2bin($hex);

            if ($packed === false) {
                return null;
            }

            $expanded = inet_ntop($packed);

            if ($expanded === false) {
                return null;
            }

            return $expanded;
        }

        if (! str_contains($host, ':')) {
            if (! str_contains($host, '.')) {
                return null; // DNS name.
            }

            return self::normalizeIpv4Octets(explode('.', $host));
        }

        $packed = @inet_pton($host);

        if ($packed === false) {
            return null;
        }

        $expanded = inet_ntop($packed);

        if ($expanded === false) {
            return null;
        }

        return $expanded;
    }

    /**
     * Normalizes a 4-octet IPv4 dotted quad: every octet is parsed as a
     * decimal number (leading zeros are allowed and normalized away) and
     * must fit in 0..255. Returns null for malformed quads.
     *
     * @param  list<string>  $octets
     */
    private static function normalizeIpv4Octets(array $octets): ?string
    {
        if (count($octets) !== 4) {
            return null;
        }

        $normalized = [];

        foreach ($octets as $octet) {
            if ($octet === '' || ! ctype_digit($octet)) {
                return null;
            }

            $value = (int) $octet;

            if ($value > 255) {
                return null;
            }

            $normalized[] = (string) $value;
        }

        return implode('.', $normalized);
    }

    /**
     * RFC 6890 special-purpose blocks relevant to webhook callbacks.
     *
     * @var list<array{string, int}>
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],      // this network
        ['10.0.0.0', 8],     // private
        ['100.64.0.0', 10],  // carrier-grade NAT
        ['127.0.0.0', 8],    // loopback
        ['169.254.0.0', 16], // link-local
        ['172.16.0.0', 12],  // private
        ['192.0.0.0', 24],   // IETF protocol assignments
        ['192.0.2.0', 24],   // TEST-NET-1
        ['192.168.0.0', 16], // private
        ['198.18.0.0', 15],  // benchmarking
        ['198.51.100.0', 24], // TEST-NET-2
        ['203.0.113.0', 24], // TEST-NET-3
        ['224.0.0.0', 4],    // multicast
        ['240.0.0.0', 4],    // reserved
    ];

    private static function isBlockedIpv4(string $ip): bool
    {
        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return false;
        }

        foreach (self::BLOCKED_V4 as [$cidr, $bits]) {
            $cidrLong = ip2long($cidr);

            if ($cidrLong === false) {
                continue;
            }

            $mask = -1 << (32 - $bits);

            if (($ipLong & $mask) === ($cidrLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    private static function isBlockedIpv6(string $ip): bool
    {
        if (str_starts_with($ip, '::ffff:')) {
            $embedded = substr($ip, 7);

            if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return self::isBlockedIpv4($embedded);
            }

            return false;
        }

        if (str_starts_with($ip, '::') || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) {
            return true;
        }

        return str_starts_with($ip, 'fe8')
            || str_starts_with($ip, 'fe9')
            || str_starts_with($ip, 'fea')
            || str_starts_with($ip, 'feb');
    }
}
