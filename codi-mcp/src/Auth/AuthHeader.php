<?php

namespace CodiMcp\Auth;

final class AuthHeader
{
    public function value(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'AUTHORIZATION'] as $key) {
            $value = trim((string) ($_SERVER[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function fromRequest($request): string
    {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            return '';
        }

        return trim((string) $request->get_header('authorization'));
    }

    public function bearerTokenFromRequest($request): string
    {
        $value = $this->fromRequest($request);
        if (stripos($value, 'Bearer ') !== 0) {
            return '';
        }

        return trim(substr($value, 7));
    }

    public function bearerToken(): string
    {
        $value = $this->value();
        if (stripos($value, 'Bearer ') !== 0) {
            return '';
        }

        return trim(substr($value, 7));
    }
}
