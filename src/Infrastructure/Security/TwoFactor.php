<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Security;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use LiveScoreTracker\Infrastructure\Clock;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Psr\Clock\ClockInterface;

/**
 * TOTP codes for the admins' two-factor authentication (spec § 19.1):
 * 6 digits, 30-second period, compatible with every authenticator app.
 */
final class TwoFactor implements ClockInterface
{
    /** Accepted clock drift between the phone and the server, in seconds. */
    private const LEEWAY = 29;

    public function __construct(private readonly Clock $clock) {}

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * New 160-bit secret (the usual size: 32 characters to type if the QR code cannot be scanned).
     *
     * @return non-empty-string
     */
    public function newSecret(): string
    {
        $secret = Base32::encodeUpperUnpadded(random_bytes(20));

        return $secret !== '' ? $secret : throw new \RuntimeException('Cannot generate a 2FA secret.');
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (\strlen($code) !== 6 || $secret === '') {
            return false;
        }

        return TOTP::createFromSecret($secret, $this)->verify($code, null, self::LEEWAY);
    }

    /**
     * Current code (used by tests).
     *
     * @param non-empty-string $secret
     */
    public function currentCode(string $secret): string
    {
        return TOTP::createFromSecret($secret, $this)->now();
    }

    /**
     * Link that authenticator apps read from the QR code.
     *
     * @param non-empty-string $secret
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $totp = TOTP::createFromSecret($secret, $this);
        $totp->setLabel($account !== '' ? $account : 'account');
        $totp->setIssuer($issuer !== '' ? $issuer : 'LiveScoreTracker');

        return $totp->getProvisioningUri();
    }

    /**
     * QR code as inline SVG markup (no external service: the secret never leaves the server).
     */
    public function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));
        $svg = $writer->writeString($uri);

        return preg_replace('/^<\?xml[^>]*>\s*/', '', $svg) ?? $svg;
    }
}
