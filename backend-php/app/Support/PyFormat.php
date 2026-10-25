<?php

namespace App\Support;

final class PyFormat
{
    /**
     * Python str.format with named fields. Doubled braces stay literal.
     *
     * @param  array<string, string|int|float>  $vars
     */
    public static function format(string $template, array $vars): string
    {
        $out = '';
        $length = strlen($template);
        for ($i = 0; $i < $length; $i++) {
            $char = $template[$i];
            if ($char === '{') {
                if ($i + 1 < $length && $template[$i + 1] === '{') {
                    $out .= '{';
                    $i++;

                    continue;
                }
                $end = strpos($template, '}', $i);
                if ($end === false) {
                    $out .= substr($template, $i);
                    break;
                }
                $key = substr($template, $i + 1, $end - $i - 1);
                $out .= array_key_exists($key, $vars) ? (string) $vars[$key] : '{'.$key.'}';
                $i = $end;

                continue;
            }
            if ($char === '}' && $i + 1 < $length && $template[$i + 1] === '}') {
                $out .= '}';
                $i++;

                continue;
            }
            $out .= $char;
        }

        return $out;
    }
}
