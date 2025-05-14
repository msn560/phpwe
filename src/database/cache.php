<?php

namespace App\Database;

class Cache{
    private $cache = [];
    private $db;
    private $ttl = 3600; // Varsayılan önbellek süresi (saniye)
    private $cacheEnabled = true;
    
    public function __construct($db){
        $this->db = $db;
    }
    
    /**
     * Önbellekten veri alır
     * 
     * @param string $table Tablo adı
     * @param string|array $key Anahtar (SQL sorgusu veya başka tanımlayıcı)
     * @param array $params Sorgu parametreleri (sorgunun bir parçası olarak kullanılır)
     * @return mixed Önbellekteki veri veya null (yoksa)
     */
    public function get($table, $key, $params = []){
        if (!$this->cacheEnabled) {
            return null;
        }
        
        $cacheKey = $this->generateCacheKey($key, $params);
        
        if(isset($this->cache[$table][$cacheKey])){ 
            $cachedItem = $this->cache[$table][$cacheKey];
            
            // TTL kontrolü
            if (isset($cachedItem['expires']) && $cachedItem['expires'] > time()) {
                $this->db->addSuccess([
                    'operation' => 'cache_hit',
                    'table' => $table,
                    'key' => $cacheKey
                ]);
                return $cachedItem['data'];
            } else {
                // Süresi dolmuş önbellek, temizle
                $this->delete($table, $cacheKey);
            }
        }
        return null;
    }
    
    /**
     * Önbelleğe veri kaydeder
     * 
     * @param string $table Tablo adı
     * @param string|array $key Anahtar (SQL sorgusu veya başka tanımlayıcı)
     * @param mixed $value Kaydedilecek veri
     * @param array $params Sorgu parametreleri (anahtarın bir parçası olarak kullanılır)
     * @param int|null $ttl Önbellekte kalma süresi (saniye), null ise varsayılan süre kullanılır
     * @return void
     */
    public function set($table, $key, $value, $params = [], $ttl = null){ 
        if (!$this->cacheEnabled) {
            return;
        }
        
        $cacheKey = $this->generateCacheKey($key, $params);
        $expiresAt = time() + ($ttl ?? $this->ttl);
        
        if (!isset($this->cache[$table])) {
            $this->cache[$table] = [];
        }
        
        $this->cache[$table][$cacheKey] = [
            'data' => $value,
            'expires' => $expiresAt,
            'created' => time()
        ];
        
        $this->db->addSuccess([
            'operation' => 'cache_set',
            'table' => $table,
            'key' => $cacheKey
        ]);
    } 
    
    /**
     * Önbellekten belirli bir anahtara ait veriyi siler
     * 
     * @param string $table Tablo adı
     * @param string|null $key Anahtar (null ise tüm tablo önbelleği temizlenir)
     * @return void
     */
    public function delete($table, $key = null){
        if ($key) {
            if (isset($this->cache[$table][$key])) {
                unset($this->cache[$table][$key]);
                $this->db->addSuccess([
                    'operation' => 'cache_delete',
                    'table' => $table,
                    'key' => $key
                ]);
            }
        } else {
            if (isset($this->cache[$table])) {
                unset($this->cache[$table]);
                $this->db->addSuccess([
                    'operation' => 'cache_delete_table',
                    'table' => $table
                ]);
            }
        }
    }
    
    /**
     * Bir tabloya ait tüm önbelleği temizler
     * 
     * @param string $table Tablo adı
     * @return void
     */
    public function clear($table){
        $this->delete($table);
    }
    
    /**
     * Tüm önbelleği temizler
     * 
     * @return void
     */
    public function clearAll(){
        $this->cache = [];
        $this->db->addSuccess([
            'operation' => 'cache_clear_all'
        ]);
    }
    
    /**
     * İlişkili tabloları temizler
     * 
     * @param string $mainTable Ana tablo
     * @param array $relatedTables İlişkili tablolar
     * @return void
     */
    public function clearRelated($mainTable, array $relatedTables = []){
        $this->clear($mainTable);
        
        foreach ($relatedTables as $table) {
            $this->clear($table);
        }
        
        $this->db->addSuccess([
            'operation' => 'cache_clear_related',
            'main_table' => $mainTable,
            'related_tables' => $relatedTables
        ]);
    }
    
    /**
     * Önbellekleme süresini ayarlar
     * 
     * @param int $seconds Önbellekleme süresi (saniye)
     * @return void
     */
    public function setTtl($seconds){
        $this->ttl = $seconds;
    }
    
    /**
     * Önbelleklemeyi etkinleştirir/devre dışı bırakır
     * 
     * @param bool $enabled Etkin mi?
     * @return void
     */
    public function setEnabled($enabled){
        $this->cacheEnabled = (bool)$enabled;
    }
    
    /**
     * Sorgu ve parametrelerden önbellek anahtarı oluşturur
     * 
     * @param string|array $key Anahtar (SQL sorgusu veya başka tanımlayıcı)
     * @param array $params Sorgu parametreleri
     * @return string Önbellek anahtarı
     */
    private function generateCacheKey($key, $params = []){
        if (is_array($key)) {
            // Diziyse, serialize ederek benzersiz bir anahtar oluştur
            $keyString = serialize($key);
        } else {
            $keyString = (string)$key;
        }
        
        // Parametreler varsa, anahtara ekle
        if (!empty($params)) {
            $keyString .= '_' . md5(serialize($params));
        }
        
        // Uzun anahtarları kısalt
        if (strlen($keyString) > 64) {
            return md5($keyString);
        }
        
        return $keyString;
    }
    
    /**
     * Önbellek istatistiklerini döndürür
     * 
     * @return array İstatistikler
     */
    public function getStats(){
        $stats = [
            'tables' => count($this->cache),
            'total_items' => 0,
            'items_by_table' => []
        ];
        
        foreach ($this->cache as $table => $items) {
            $tableCount = count($items);
            $stats['total_items'] += $tableCount;
            $stats['items_by_table'][$table] = $tableCount;
        }
        
        return $stats;
    }
}