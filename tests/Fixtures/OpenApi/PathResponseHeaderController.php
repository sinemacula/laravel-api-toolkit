<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;

/**
 * Fixture non-resource controller declaring response headers.
 *
 * The sign-in action declares the headers of its success response and throws a
 * subclass inheriting its parent's declared header beside an exception
 * declaring none; the quota action throws two exceptions sharing a status whose
 * headers overlap; the bare action declares nothing at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathResponseHeaderController
{
    /**
     * Sign in, answering with the refresh token as a cookie.
     *
     * @param  bool  $limited
     * @return never
     *
     * @throws \Tests\Fixtures\OpenApi\BurstLimitedException
     * @throws \SineMacula\ApiToolkit\Exceptions\ConflictException
     */
    #[ResponseHeader('Set-Cookie', 'The refresh token, HttpOnly, Secure, SameSite=Strict.', ['type' => 'string', 'pattern' => '^refresh_token='])]
    #[ResponseHeader('X-Session-Id')]
    public function signIn(bool $limited): never
    {
        throw $limited ? new BurstLimitedException : new ConflictException;
    }

    /**
     * Spend from a quota guarded by two rate limits.
     *
     * @param  bool  $exhausted
     * @return never
     *
     * @throws \Tests\Fixtures\OpenApi\QuotaExceededException
     * @throws \SineMacula\ApiToolkit\Exceptions\TooManyRequestsException
     */
    public function quota(bool $exhausted): never
    {
        throw $exhausted ? new QuotaExceededException : new TooManyRequestsException;
    }

    /**
     * Respond without declaring any header.
     *
     * @return never
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ConflictException
     */
    public function bare(): never
    {
        throw new ConflictException;
    }
}
