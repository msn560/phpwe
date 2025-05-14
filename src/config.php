<?php
namespace App;
class Config
{
    private static $type = "system";
    private static $data = [];

    public static function set(  $data, $type = "system")
    {
        if(is_string($data)){
            if(file_exists($data)) $data = require_once $data;
        }
        $type = $type ?: self::$type;
        self::$data[$type] = array_merge(self::$data[$type] ?? [], $data);
    }

    public static function get($keys = null, $type = "", $default = null)
    {
        if (is_null($keys)) {
            return self::$data[$type ?: self::$type] ?? $default;
        }

        $type = $type ?: self::$type;
        $data = self::$data[$type] ?? [];

        return array_reduce(explode("/", $keys), function ($carry, $key) {
            return is_array($carry) && isset($carry[$key]) ? $carry[$key] : null;
        }, $data) ?? $default;
    }

    public static function setValue(string $keys, $value, $type = "")
    {
        $type = empty($type) ? self::$type : $type;

        // Dizi şeklinde anahtarlar oluştur
        $keysArr = explode("/", $keys);
        $temp = &self::$data[$type]; // Referans kullanarak değer üzerinde çalışıyoruz

        foreach ($keysArr as $key) {
            if (!isset($temp[$key])) {
                $temp[$key] = [];
            }
            $temp = &$temp[$key];
        }

        $temp = $value;
    }

    public static function getData($type = "")
    {
        return self::get(null, $type, []);
    }
}