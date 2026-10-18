<?php
declare(strict_types=1);

namespace Bicho;

final class Privacy
{
    public static function sensitive(string $key): bool
    {
        return preg_match('/password|passwd|secret|token|authorization|cookie|api[_-]?key|sentry_key|email|phone|address|^user$|^ip$/i', $key) === 1;
    }

    public static function text(string $value, int $limit = 2048): string
    {
        $value = json_decode(json_encode(substr($value, 0, $limit + 4), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $value = substr($value, 0, $limit);
        while (preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        $value = preg_replace('/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/=_.-]+/i', '[Filtered]', $value);
        return preg_replace_callback('~https?://[^\s<>"\x27)]+~i', static function (array $match): string {
            $url = preg_split('/[?#]/', $match[0], 2)[0];
            return preg_replace('~\A(https?://)[^/]*@~i', '$1', $url);
        }, $value);
    }

    public static function normalize(mixed $value, int $depth = 0, int &$budget = 300): mixed
    {
        if ($depth > 5 || --$budget < 0) {
            return '[Truncated]';
        }
        if (is_string($value)) {
            return self::text($value);
        }
        if (is_array($value)) {
            if (array_is_list($value) && count($value) === 2 && is_string($value[0]) && self::sensitive($value[0])) {
                return [self::text($value[0], 128), '[Filtered]'];
            }
            $result = [];
            foreach (array_slice($value, 0, 50, true) as $key => $item) {
                $result[is_string($key) ? self::text($key, 128) : $key] = is_string($key) && self::sensitive($key)
                    ? '[Filtered]' : self::normalize($item, $depth + 1, $budget);
            }
            return $result;
        }
        if (is_float($value) && !is_finite($value)) {
            return '[Non-finite number]';
        }
        if (is_object($value)) {
            return '[Object ' . self::text(get_class($value), 128) . ']';
        }
        return is_resource($value) ? '[Resource]' : $value;
    }
}
