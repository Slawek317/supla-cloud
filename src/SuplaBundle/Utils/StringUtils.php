<?php

namespace SuplaBundle\Utils;

final class StringUtils {
    private function __construct() {
    }

    public static function snakeCaseToCamelCase(string $string): string {
        return lcfirst(str_replace('_', '', ucwords(strtolower($string), '_')));
    }

    public static function camelCaseToSnakeCase(string $string): string {
        return strtoupper(trim(preg_replace('#([A-Z])#', '_$1', $string), '_'));
    }
}
