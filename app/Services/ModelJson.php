<?php

namespace App\Services;

/**
 * The JSON object a model answered with, forgiving the slips models make even
 * in JSON mode: a code fence or a sentence around the object, and raw line
 * breaks or tabs inside its strings (a JSON control character error).
 */
class ModelJson
{
    public static function decode(string $content, int $depth = 64): array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        $json = $start !== false && $end !== false && $end > $start ? substr($content, $start, $end - $start + 1) : $content;
        // Control characters are never valid raw in JSON: outside a string they
        // are whitespace, inside one they are the model's line breaks.
        $json = preg_replace('/[\x00-\x1F]+/', ' ', $json);

        return (array) json_decode($json, true, $depth, JSON_THROW_ON_ERROR);
    }
}
