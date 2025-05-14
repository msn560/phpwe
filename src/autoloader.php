<?php
    namespace App;
    
    class Autoloader{
        private static $loaded = [];
        public static function DIR($dir, $exclude = []){

            if (!is_dir($dir))
                return;
            $exclude = array_merge($exclude, ["autoloader.php", "..", "."]);
            $files = scandir($dir);
            $files = array_diff($files, $exclude);
            foreach ($files as $file) {
                $filePath = $dir . '/' . $file;
                if (in_array($filePath, self::$loaded))
                    continue;
                if (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
                    $className = pathinfo($filePath, PATHINFO_FILENAME);
                    if (strpos($className, "!") !== false)
                        continue;
                    self::$loaded[] = $filePath;
                    require_once $filePath;
                }
                if (is_dir($filePath)) {
                    self::DIR($filePath);
                }
            }
        }
    }
    Autoloader::DIR(__DIR__ ); 
    Config::set(defined("CONFIG_PATH") ? CONFIG_PATH : []);