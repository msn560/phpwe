<?php

namespace App\Database;
class DB
{
    const CONNECT_TYPES = ["mysql", "sqlite"];
    private static $_start = null;
    private array $config = [];
    private bool $is_connected = false;
    private $conneted_type = null;
    private $_pdo = null;
    private $errors = [];
    private $success = [];
    private $_sqls = []; 
    private $cache = []; 
    public function __construct(array $config = [])
    {
        $this->config = empty($config) ? \App\Config::get("db") : $config;
        $type = $this->config["type"] ?? "mysql";
        if($this->is_connected) 
            return;
        if ($type == "mysql")
            $this->mysql_connect();
        else
            $this->sqlite_connect(); 
        $this->cache = new Cache($this);
    }
    public static function cache(){
        return self::start()->cache;
    }
    public static function start(array $config = []): self
    {
        self::$_start = self::$_start ?? new DB($config);
        return self::$_start;
    }
    public static function controllers($table = "" ){
        self::start()->check_connection();
        $class =  new Controllers(self::start()); 
        return $class->setTable($table);
    }
    public static function delete($table = "")
    {
        self::start()->check_connection();
        $class = new Delete(self::start());
        return $class->setTable($table);
    }
    public static function insert($table = "")
    {
        self::start()->check_connection();
        $class = new Insert(self::start());
        return $class->setTable($table);
    }
    public static function query($table = "")
    {
        self::start()->check_connection();
        $class = new Query(self::start());
        return $class->setTable($table);
    }
    public static function update($table = "")
    {
        self::start()->check_connection();
        $class = new Update(self::start());
        return $class->setTable($table);
    }
    public function check_connection(){
        if(!$this->is_connected){
            if(defined("DEBUG")){
                if(DEBUG){
                    $this->showDebug();
                    exit();
                }
            }
            die(end($this->errors)["msg"]);
        }
            
    } 
    public function addErrors(array $st): void
    {
        $this->errors[] = $st;
    }
    
    public function addSql(string $sql, array $params = []): void
    {
        $this->_sqls[] = [
            'sql' => $sql,
            'params' => $params,
            'time' => microtime(true),
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
        ];
    }
    
    public function addSuccess(array $st): void
    {
        $this->success[] = $st;
    }
    
    /**
     * Veritabanı türünü döndürür (mysql, sqlite)
     * 
     * @return string Veritabanı türü
     */
    public function getDatabaseType(): string
    {
        return $this->conneted_type ?? '';
    }
    
    public function showDebug(): void
    {
        if (!defined('DEBUG') || !DEBUG) {
            return;
        }
        
        // CSS Stiller
        require_once __DIR__ . "/debug.php";
        debug_print_style();
        echo '<div class="db-debug-container">';
        echo '<h2>Veritabanı Debug Paneli</h2>';
        
        // Bağlantı Bilgileri
        echo '<div class="db-debug-section connection">';
        echo '<h3>Veritabanı Bağlantı Bilgileri</h3>';
        echo '<ul class="db-debug-list">';
        echo '<li>Bağlantı Türü: <strong>' . ($this->conneted_type ?? 'Bağlantı yok') . '</strong></li>';
        echo '<li>Bağlantı Durumu: <span class="' . ($this->is_connected ? 'db-debug-status-connected' : 'db-debug-status-disconnected') . '">' . 
            ($this->is_connected ? 'Bağlı' : 'Bağlı değil') . '</span></li>';
        echo '</ul>';
        echo '</div>';
        
        // Önbellek İstatistikleri
        if ($this->cache) {
            echo '<div class="db-debug-section cache">';
            echo '<h3>Önbellek İstatistikleri <span class="db-debug-badge db-debug-badge-cache">Cache</span></h3>';
            $stats = is_array($this->cache->getStats()) ? $this->cache->getStats() : [];
            echo '<ul class="db-debug-list">';
            echo '<li>Toplam Önbelleklenmiş Tablo: <strong>' . $stats['tables'] . '</strong></li>';
            echo '<li>Toplam Önbellek Öğesi: <strong>' . $stats['total_items'] . '</strong></li>';
            
            if (!empty($stats['items_by_table'])) {
                echo '<li>Tablo Bazında Önbellek:';
                echo '<ul class="db-debug-list" style="padding-left: 20px;">';
                foreach ($stats['items_by_table'] as $table => $count) {
                    echo '<li>' . $table . ': <strong>' . $count . '</strong> öğe</li>';
                }
                echo '</ul>';
                echo '</li>';
            }
            
            echo '</ul>';
            echo '</div>';
        }
        
        // Hatalar
        if (!empty($this->errors)) {
            $errorCount = count($this->errors);
            echo '<div class="db-debug-section errors">';
            echo '<h3>Hatalar <span class="db-debug-badge db-debug-badge-error">' . $errorCount . '</span></h3>';
            echo '<button class="db-debug-collapse-btn" onclick="document.getElementById(\'db-debug-errors\').style.display = document.getElementById(\'db-debug-errors\').style.display === \'none\' ? \'block\' : \'none\';">Göster/Gizle</button>';
            echo '<div id="db-debug-errors">';
            echo '<ul class="db-debug-list">';
            foreach ($this->errors as $error) {
                echo '<li class="db-debug-item">';
                echo '<strong>Mesaj:</strong> ' . htmlspecialchars($error['msg'] ?? 'Bilinmeyen hata') . '<br>';
                
                if (isset($error['debug']) && $error['debug'] instanceof \Exception) {
                    echo '<strong>Hata Detayı:</strong> ' . htmlspecialchars($error['debug']->getMessage()) . '<br>';
                    echo '<strong>Dosya:</strong> ' . htmlspecialchars($error['debug']->getFile()) . '<br>';
                    echo '<strong>Satır:</strong> ' . htmlspecialchars($error['debug']->getLine()) . '<br>';
                    echo '<strong>Yığın İzleme:</strong> <pre class="db-debug-pre">' . htmlspecialchars($error['debug']->getTraceAsString()) . '</pre>';
                } elseif (isset($error['debug'])) {
                    echo '<strong>Debug:</strong> <pre class="db-debug-pre">' . htmlspecialchars(print_r($error['debug'], true)) . '</pre>';
                }
                
                echo '</li>';
            }
            echo '</ul>';
            echo '</div>';
            echo '</div>';
        }
        
        // Başarılı İşlemler
        if (!empty($this->success)) {
            $successCount = count($this->success);
            echo '<div class="db-debug-section success">';
            echo '<h3>Başarılı İşlemler <span class="db-debug-badge db-debug-badge-success">' . $successCount . '</span></h3>';
            echo '<button class="db-debug-collapse-btn" onclick="document.getElementById(\'db-debug-success\').style.display = document.getElementById(\'db-debug-success\').style.display === \'none\' ? \'block\' : \'none\';">Göster/Gizle</button>';
            echo '<div id="db-debug-success">';
            
            // Cache işlemlerini kategorize edelim
            $cacheOperations = [];
            $otherOperations = [];
            
            foreach ($this->success as $success) {
                if (isset($success['operation']) && strpos($success['operation'], 'cache_') === 0) {
                    $cacheOperations[] = $success;
                } else {
                    $otherOperations[] = $success;
                }
            }
            
            if (!empty($cacheOperations)) {
                echo '<h4>Önbellek İşlemleri <span class="db-debug-badge db-debug-badge-cache">' . count($cacheOperations) . '</span></h4>';
                echo '<ul class="db-debug-list">';
                foreach ($cacheOperations as $cache) {
                    echo '<li class="db-debug-item">';
                    echo '<pre class="db-debug-pre">' . htmlspecialchars(print_r($cache, true)) . '</pre>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            
            if (!empty($otherOperations)) {
                echo '<h4>Diğer İşlemler <span class="db-debug-badge db-debug-badge-success">' . count($otherOperations) . '</span></h4>';
                echo '<ul class="db-debug-list">';
                foreach ($otherOperations as $other) {
                    echo '<li class="db-debug-item">';
                    echo '<pre class="db-debug-pre">' . htmlspecialchars(print_r($other, true)) . '</pre>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
            echo '</div>';
        }
        
        // SQL Sorguları
        if (!empty($this->_sqls)) {
            $queryCount = count($this->_sqls);
            echo '<div class="db-debug-section queries">';
            echo '<h3>SQL Sorguları <span class="db-debug-badge db-debug-badge-query">' . $queryCount . '</span></h3>';
            echo '<button class="db-debug-collapse-btn" onclick="document.getElementById(\'db-debug-queries\').style.display = document.getElementById(\'db-debug-queries\').style.display === \'none\' ? \'block\' : \'none\';">Göster/Gizle</button>';
            echo '<div id="db-debug-queries" class="db-debug-table-container">';
            echo '<table class="db-debug-table">';
            echo '<tr>';
            echo '<th>#</th>';
            echo '<th>SQL</th>';
            echo '<th>Parametreler</th>';
            echo '<th>Zaman</th>';
            echo '<th>Kaynak</th>';
            echo '</tr>';
            
            foreach ($this->_sqls as $index => $query) {
                echo '<tr>';
                echo '<td>' . ($index + 1) . '</td>';
                echo '<td><pre class="db-debug-pre">' . htmlspecialchars($query['sql']) . '</pre></td>';
                echo '<td><pre class="db-debug-pre">' . htmlspecialchars(print_r($query['params'], true)) . '</pre></td>';
                
                $time = isset($query['time']) ? date('Y-m-d H:i:s', (int)$query['time']) : 'Bilinmiyor';
                echo '<td>' . $time . '</td>';
                
                $source = '';
                if (isset($query['backtrace']) && is_array($query['backtrace']) && !empty($query['backtrace'])) {
                    $frame = $query['backtrace'][0];
                    $source = (isset($frame['file']) ? basename($frame['file']) : 'Bilinmeyen dosya') . 
                            (isset($frame['line']) ? ':' . $frame['line'] : '');
                }
                echo '<td>' . htmlspecialchars($source) . '</td>';
                echo '</tr>';
            }
            
            echo '</table>';
            echo '</div>';
            echo '</div>';
        }
        
        // Controllers Cache İstatistikleri
        echo '<div class="db-debug-section cache-stats">';
        echo '<h3>Önbellek İstatistikleri <span class="db-debug-badge db-debug-badge-cache">Controllers</span></h3>';
        echo '<button class="db-debug-collapse-btn" onclick="document.getElementById(\'db-debug-cache-stats\').style.display = document.getElementById(\'db-debug-cache-stats\').style.display === \'none\' ? \'block\' : \'none\';">Göster/Gizle</button>';
        echo '<div id="db-debug-cache-stats">';
        echo '<pre class="db-debug-pre">';
        print_r($this->controllers()->getCacheStats());
        echo '</pre>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        
        // Basit JavaScript ile göster/gizle fonksiyonlarını başlat - varsayılan olarak hepsini gizle
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var sections = ["db-debug-errors", "db-debug-success", "db-debug-queries", "db-debug-cache-stats"];
                
                for (var i = 0; i < sections.length; i++) {
                    var section = document.getElementById(sections[i]);
                    if (section) {
                        section.style.display = "none";
                    }
                }
            });
        </script>';
    }
    
    public static function showDebugStatic(): void
    {
        self::start()->showDebug();
    }
    
    public static function pdo(){
        return self::start()->_pdo;
    }
    private function mysql_connect()
    {
        try{
            $mysql = $this->config["mysql"] ?? [];
            $host = $mysql["host"] ?? "localhost";
            $name = $mysql["name"] ?? "name";
            $user = $mysql["user"] ?? "user";
            $password = $mysql["password"] ?? "password";
            $charset = $mysql["charset"] ?? "utf8mb4"; 
            $this->_pdo = new \PDO('mysql:host=' . $host . ';dbname=' . $name, $user, $password);
            $this->_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->_pdo->query("SET CHARACTER SET " . $charset);
            $this->_pdo->exec("set names " . $charset);
            $this->conneted_type = self::CONNECT_TYPES[0]; 
            $this->is_connected = true;
        }catch(\PDOException $e){ 
            $this->addErrors(["msg" => "Mysql connection error", "debug" => $e]);
        }
    }
    private function sqlite_connect()
    {
        try{
            $path = $this->config["sqlite"] ?? ":memory:";
            $this->_pdo = new \PDO('sqlite:' . $path);
            $this->_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->conneted_type = self::CONNECT_TYPES[1];
            $this->is_connected = true;
        } catch (\PDOException $e) {
            $this->addErrors(["msg"=> "SQLite connection error","debug"=>$e]);
        }
    }
}