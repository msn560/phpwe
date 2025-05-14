<?php

namespace App\Database;

function safe_rmdir($dir){
    if(is_dir($dir)){
        $files = scandir($dir);
        foreach($files as $file){
            if($file != "." and $file != ".."){
                if (is_dir($dir . "/" . $file)) {
                    safe_rmdir($dir . "/" . $file);
                }else{
                    unlink($dir . "/" . $file);
                }
            }
        }
        if(is_dir($dir)){
         rmdir($dir);
        }
    }
}
class Controllers
{
    private $db;
    private $table;
    private const CACHE_DIR = __DIR__ . '/cache';
    private const SCHEMA_PREFIX = 'schema_';
    private $memoryCache = [];
    private $cacheTTL = 3600; // Önbellek süresi (saniye)
    private $lastCacheCleanup = 0;

    public function __construct($db, $cacheTTL = 3600)
    {
        $this->db = $db;
        $this->cacheTTL = $cacheTTL;
        $this->lastCacheCleanup = time();
        if(defined("DEBUG")){
            if(DEBUG and is_dir(self::CACHE_DIR)){
                //safe_rmdir(self::CACHE_DIR); 
            }
        }
        // Cache dizini yoksa oluştur
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
    }

    public function setTable($table)
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Veritabanı şemasını kontrol eder ve tabloları gerekirse oluşturur
     *
     * @param array $models Kontrol edilecek model sınıfları
     * @return array İşlem sonuçları
     */
    public function ensureSchema(array $models = []): array
    {
        $results = [];

        try {
            if (empty($models)) {
                // Model sınıfları verilmemişse, mevcut tabloların durumunu kontrol et
                $results = $this->checkExistingTables();
            } else {
                // Model sınıfları verilmişse, her biri için tablo varlığını kontrol et ve gerekirse oluştur
                foreach ($models as $modelClass) {
                    if (!class_exists($modelClass)) {
                        $results[$modelClass] = [
                            'status' => false,
                            'message' => 'Model sınıfı bulunamadı'
                        ];

                        $this->db->addErrors([
                            'msg' => "Model sınıfı bulunamadı: {$modelClass}",
                            'source' => 'Controllers::ensureSchema'
                        ]);

                        continue;
                    }

                    // Model sınıfından bir örnek oluştur
                    $model = new $modelClass();
                    if (!method_exists($model, 'getTableName') || !method_exists($model, 'create_table')) {
                        $results[$modelClass] = [
                            'status' => false,
                            'message' => 'Geçerli bir Model sınıfı değil'
                        ];

                        $this->db->addErrors([
                            'msg' => "Geçerli bir Model sınıfı değil: {$modelClass}",
                            'source' => 'Controllers::ensureSchema'
                        ]);

                        continue;
                    }

                    // Tablonun varlığını kontrol et, yoksa oluştur
                    $tableName = $model->getTableName();
                    if (!$this->tableExists($tableName)) {
                        try {
                            $model->create_table();
                            $this->cacheTableSchema($tableName);
                            $results[$modelClass] = [
                                'status' => true,
                                'message' => "'{$tableName}' tablosu oluşturuldu"
                            ];

                            $this->db->addSuccess([
                                'operation' => 'create_table',
                                'table' => $tableName,
                                'model' => $modelClass
                            ]);
                        } catch (\Exception $e) {
                            $results[$modelClass] = [
                                'status' => false,
                                'message' => "'{$tableName}' tablosu oluşturulamadı: " . $e->getMessage()
                            ];

                            $this->db->addErrors([
                                'msg' => "Tablo oluşturulamadı: {$tableName}",
                                'source' => 'Controllers::ensureSchema',
                                'model' => $modelClass,
                                'debug' => $e
                            ]);
                        }
                    } else {
                        // Tablo zaten varsa, şemasını güncelle
                        $this->cacheTableSchema($tableName);
                        $results[$modelClass] = [
                            'status' => true,
                            'message' => "'{$tableName}' tablosu zaten mevcut"
                        ];

                        $this->db->addSuccess([
                            'operation' => 'schema_updated',
                            'table' => $tableName,
                            'model' => $modelClass
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Şema kontrolü sırasında hata oluştu",
                'source' => 'Controllers::ensureSchema',
                'debug' => $e
            ]);
        }

        return $results;
    }

    /**
     * Mevcut veritabanı tablolarını kontrol eder ve şema bilgilerini önbelleğe alır
     *
     * @return array İşlem sonuçları
     */
    public function checkExistingTables(): array
    {
        $results = [];

        try {
            $tables = $this->getAllTables();

            foreach ($tables as $table) {
                $cached = $this->cacheTableSchema($table);
                $results[$table] = [
                    'status' => $cached,
                    'message' => $cached ? "'{$table}' tablosu önbelleğe alındı" : "'{$table}' tablosu önbelleğe alınamadı"
                ];

                if ($cached) {
                    $this->db->addSuccess([
                        'operation' => 'cache_schema',
                        'table' => $table
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Var olan tabloları kontrol ederken hata oluştu",
                'source' => 'Controllers::checkExistingTables',
                'debug' => $e
            ]);
        }

        return $results;
    }

    /**
     * Veritabanındaki tüm tabloları döndürür
     *
     * @return array Tablo adları
     */
    public function getAllTables(): array
    {
        try {
            $dbType = $this->db->getDatabaseType();
            $sql = '';

            if ($dbType === 'mysql') {
                $sql = "SHOW TABLES";
            } elseif ($dbType === 'sqlite') {
                $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
            } else {
                $this->db->addErrors([
                    'msg' => "Bilinmeyen veritabanı türü: {$dbType}",
                    'source' => 'Controllers::getAllTables'
                ]);
                return [];
            }

            $this->db->addSql($sql, []);
            $stmt = DB::pdo()->query($sql);
            $tables = [];

            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            $this->db->addSuccess([
                'operation' => 'get_all_tables',
                'count' => count($tables)
            ]);

            return $tables;
        } catch (\PDOException $e) {
            $this->db->addErrors([
                'msg' => "Tablo listesi alınamadı",
                'source' => 'Controllers::getAllTables',
                'debug' => $e
            ]);

            return [];
        }
    }

    /**
     * Tablonun varlığını kontrol eder
     *
     * @param string $tableName Tablo adı
     * @return bool Tablo varsa true, yoksa false
     */
    public function tableExists(string $tableName): bool
    {
        $cacheKey = "table_exists_{$tableName}";
        
        // RAM önbelleğinden hızlıca kontrol et
        if (isset($this->memoryCache[$cacheKey])) {
            if ((time() - $this->memoryCache[$cacheKey]['cached_at']) < $this->cacheTTL) {
                return $this->memoryCache[$cacheKey]['exists'];
            }
        }
        
        // Önce önbellekten kontrol et
        if ($this->tableExistsInCache($tableName)) {
            // RAM önbelleğine kaydet
            $this->memoryCache[$cacheKey] = [
                'exists' => true,
                'cached_at' => time()
            ];
            return true;
        }

        // Veritabanından kontrol et
        try {
            $dbType = $this->db->getDatabaseType();
            $sql = '';

            if ($dbType === 'mysql') {
                $sql = "SHOW TABLES LIKE ?";
            } elseif ($dbType === 'sqlite') {
                $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name = ?";
            } else {
                $this->db->addErrors([
                    'msg' => "Bilinmeyen veritabanı türü: {$dbType}",
                    'source' => 'Controllers::tableExists',
                    'table' => $tableName
                ]);
                return false;
            }

            $this->db->addSql($sql, [$tableName]);
            $stmt = DB::pdo()->prepare($sql);
            $stmt->execute([$tableName]);

            $exists = $stmt->rowCount() > 0;
            
            // RAM önbelleğine kaydet
            $this->memoryCache["table_exists_{$tableName}"] = [
                'exists' => $exists,
                'cached_at' => time()
            ];

            if ($exists) {
                // Tablo varsa şemasını da önbelleğe al
                $this->cacheTableSchema($tableName);
                
                $this->db->addSuccess([
                    'operation' => 'table_check',
                    'table' => $tableName,
                    'exists' => true
                ]);
            }

            return $exists;
        } catch (\PDOException $e) {
            $this->db->addErrors([
                'msg' => "Tablo varlığı kontrol edilemedi: {$tableName}",
                'source' => 'Controllers::tableExists',
                'debug' => $e
            ]);

            return false;
        }
    }

    /**
     * Tablonun önbellekte var olup olmadığını kontrol eder
     *
     * @param string $tableName Tablo adı
     * @return bool Önbellekte varsa true, yoksa false
     */
    private function tableExistsInCache(string $tableName): bool
    {
        $cacheKey = "schema_{$tableName}";
        
        // Önce RAM önbelleğine bak
        if (isset($this->memoryCache[$cacheKey])) {
            // Önbellek süresi geçerli mi kontrol et
            if ((time() - $this->memoryCache[$cacheKey]['cached_at']) < $this->cacheTTL) {
                return true;
            }
        }
        
        // Dosya önbelleğini kontrol et
        $cacheFile = $this->getSchemaPath($tableName);
        if (file_exists($cacheFile)) {
            // Dosya yaşını kontrol et
            $fileAge = time() - filemtime($cacheFile);
            if ($fileAge < $this->cacheTTL) {
                // RAM önbelleğine kaydet
                if (!isset($this->memoryCache[$cacheKey])) {
                    $schema = json_decode(file_get_contents($cacheFile), true);
                    $this->memoryCache[$cacheKey] = [
                        'data' => $schema,
                        'cached_at' => time() - $fileAge
                    ];
                }
                return true;
            }
        }
        
        return false;
    }

    /**
     * Tablonun şema dosyasının yolunu döndürür
     *
     * @param string $tableName Tablo adı
     * @return string Şema dosyasının yolu
     */
    private function getSchemaPath(string $tableName): string
    {
        return self::CACHE_DIR . '/' . self::SCHEMA_PREFIX . $tableName . '.json';
    }

    /**
     * Tablonun şemasını önbelleğe alır
     *
     * @param string $tableName Tablo adı
     * @param bool $forceRefresh Zorunlu yenileme yapılıp yapılmayacağı
     * @return bool İşlem başarılıysa true, değilse false
     */
    public function cacheTableSchema(string $tableName, bool $forceRefresh = false): bool
    {
        try {
            $cacheKey = "schema_{$tableName}";
            $cacheFile = $this->getSchemaPath($tableName);
            
            // Eğer zorunlu yenileme istenmiyorsa ve önbellek dosyası mevcutsa
            if (!$forceRefresh && file_exists($cacheFile)) {
                $fileAge = time() - filemtime($cacheFile);
                // Önbellek süresi dolmamışsa, yeniden oluşturmaya gerek yok
                if ($fileAge < $this->cacheTTL) {
                    // RAM önbelleği güncellenmediyse güncelleyelim
                    if (!isset($this->memoryCache[$cacheKey])) {
                        $schema = json_decode(file_get_contents($cacheFile), true);
                        $this->memoryCache[$cacheKey] = [
                            'data' => $schema,
                            'cached_at' => time() - $fileAge // Dosyanın yaşını kullan
                        ];
                    }
                    return true;
                }
            }
            
            // Önbellek temizliği
            $this->cleanupCache();
            
            $dbType = $this->db->getDatabaseType();
            $columns = [];
            $columnsIndexed = [];

            if ($dbType === 'mysql') {
                $sql = "DESCRIBE `{$tableName}`";
                $this->db->addSql($sql, []);
                $stmt = DB::pdo()->query($sql);

                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $column = [
                        'name' => $row['Field'],
                        'type' => $row['Type'],
                        'null' => $row['Null'] === 'YES',
                        'key' => $row['Key'],
                        'default' => $row['Default'],
                        'extra' => $row['Extra']
                    ];

                    $columns[] = $column;
                    $columnsIndexed[$row['Field']] = $column;
                }
            } elseif ($dbType === 'sqlite') {
                $sql = "PRAGMA table_info(`{$tableName}`)";
                $this->db->addSql($sql, []);
                $stmt = DB::pdo()->query($sql);

                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $column = [
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'null' => $row['notnull'] == 0,
                        'key' => $row['pk'] == 1 ? 'PRI' : '',
                        'default' => $row['dflt_value'],
                        'extra' => ''
                    ];

                    $columns[] = $column;
                    $columnsIndexed[$row['name']] = $column;
                }
            } else {
                $this->db->addErrors([
                    'msg' => "Bilinmeyen veritabanı türü: {$dbType}",
                    'source' => 'Controllers::cacheTableSchema',
                    'table' => $tableName
                ]);
                return false;
            }

            // Foreign key ve unique kısıtlamalarını al
            $constraints = $this->getTableConstraints($tableName);

            $schema = [
                'table' => $tableName,
                'columns' => $columnsIndexed,
                'constraints' => $constraints,
                'cached_at' => date('Y-m-d H:i:s'),
                'db_type' => $dbType
            ];

            // Cache dizini yoksa oluştur
            if (!is_dir(self::CACHE_DIR)) {
                mkdir(self::CACHE_DIR, 0755, true);
            }

            $cacheFile = $this->getSchemaPath($tableName);
            $result = file_put_contents($cacheFile, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // RAM önbelleğine kaydet
            $cacheKey = "schema_{$tableName}";
            $this->memoryCache[$cacheKey] = [
                'data' => $schema,
                'cached_at' => time()
            ];
            
            if ($result) {
                $this->db->addSuccess([
                    'operation' => 'cache_schema',
                    'table' => $tableName,
                    'columns' => count($columns)
                ]);
            }

            return $result !== false;
        } catch (\PDOException $e) {
            $this->db->addErrors([
                'msg' => "Tablo şeması önbelleğe alınamadı: {$tableName}",
                'source' => 'Controllers::cacheTableSchema',
                'debug' => $e
            ]);

            return false;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Tablo şeması önbelleğe alınamadı (genel hata): {$tableName}",
                'source' => 'Controllers::cacheTableSchema',
                'debug' => $e
            ]);

            return false;
        }
    }

    /**
     * Tablo kısıtlamalarını (foreign key, unique) alır
     *
     * @param string $tableName Tablo adı
     * @return array Kısıtlamalar dizisi
     */
    private function getTableConstraints(string $tableName): array
    {
        $constraints = [
            'foreign_keys' => [],
            'unique' => []
        ];

        try {
            $dbType = $this->db->getDatabaseType();

            if ($dbType === 'mysql') {
                // Foreign key kısıtlamalarını al
                $sql = "SELECT 
                            tc.CONSTRAINT_NAME, 
                            tc.TABLE_NAME, 
                            kcu.COLUMN_NAME, 
                            kcu.REFERENCED_TABLE_NAME,
                            kcu.REFERENCED_COLUMN_NAME
                        FROM 
                            information_schema.TABLE_CONSTRAINTS tc
                        JOIN 
                            information_schema.KEY_COLUMN_USAGE kcu
                        ON 
                            tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                        WHERE 
                            tc.CONSTRAINT_TYPE = 'FOREIGN KEY' 
                            AND tc.TABLE_NAME = ?";

                $stmt = DB::pdo()->prepare($sql);
                $stmt->execute([$tableName]);

                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $constraints['foreign_keys'][$row['CONSTRAINT_NAME']] = [
                        'column' => $row['COLUMN_NAME'],
                        'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                        'referenced_column' => $row['REFERENCED_COLUMN_NAME']
                    ];
                }

                // Unique kısıtlamalarını al
                $sql = "SELECT 
                            tc.CONSTRAINT_NAME, 
                            tc.TABLE_NAME, 
                            kcu.COLUMN_NAME
                        FROM 
                            information_schema.TABLE_CONSTRAINTS tc
                        JOIN 
                            information_schema.KEY_COLUMN_USAGE kcu
                        ON 
                            tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                        WHERE 
                            tc.CONSTRAINT_TYPE = 'UNIQUE' 
                            AND tc.TABLE_NAME = ?";

                $stmt = DB::pdo()->prepare($sql);
                $stmt->execute([$tableName]);

                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $constraints['unique'][$row['CONSTRAINT_NAME']] = [
                        'column' => $row['COLUMN_NAME']
                    ];
                }
            }

            return $constraints;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Tablo kısıtlamaları alınamadı: {$tableName}",
                'source' => 'Controllers::getTableConstraints',
                'debug' => $e
            ]);

            return $constraints;
        }
    }

    /**
     * Önbellekten tablo şemasını döndürür
     *
     * @param string $tableName Tablo adı
     * @return array|null Tablo şeması veya null
     */
    public function getTableSchema(string $tableName): ?array
    {
        try {
            // RAM önbelleğini kontrol et
            $cacheKey = "schema_{$tableName}";
            if (isset($this->memoryCache[$cacheKey])) {
                $schema = $this->memoryCache[$cacheKey];
                
                // Önbellek süresi geçmiş mi kontrol et
                if ((time() - $schema['cached_at']) < $this->cacheTTL) {
                    $this->db->addSuccess([
                        'operation' => 'get_schema',
                        'table' => $tableName,
                        'from_cache' => 'memory'
                    ]);
                    return $schema['data'];
                }
            }

            // Dosya önbelleğini kontrol et
            $cacheFile = $this->getSchemaPath($tableName);

            if (file_exists($cacheFile)) {
                // Önbellek dosyasının yaşını kontrol et
                $fileAge = time() - filemtime($cacheFile);
                if ($fileAge > $this->cacheTTL) {
                    // Cache süresi dolmuş, yenile
                    $this->cacheTableSchema($tableName);
                }
                
                $schema = json_decode(file_get_contents($cacheFile), true);

                // Sütunları isimlerine göre indeksle
                $columnsIndexed = [];
                if (isset($schema['columns']) && is_array($schema['columns'])) {
                    foreach ($schema['columns'] as $column) {
                        if (isset($column['name'])) {
                            $columnsIndexed[$column['name']] = $column;
                        }
                    }
                    $schema['columns'] = $columnsIndexed;
                }
                
                // RAM önbelleğine kaydet
                $this->memoryCache[$cacheKey] = [
                    'data' => $schema,
                    'cached_at' => time()
                ];

                $this->db->addSuccess([
                    'operation' => 'get_schema',
                    'table' => $tableName,
                    'from_cache' => 'file'
                ]);

                return $schema;
            }

            // Önbellekte yoksa ve tablo varsa, önbelleğe al
            if ($this->tableExists($tableName)) {
                $this->cacheTableSchema($tableName);

                $this->db->addSuccess([
                    'operation' => 'get_schema',
                    'table' => $tableName,
                    'from_cache' => false,
                    'cached_now' => true
                ]);

                return $this->getTableSchema($tableName);
            }

            $this->db->addErrors([
                'msg' => "Tablo şeması bulunamadı: {$tableName}",
                'source' => 'Controllers::getTableSchema'
            ]);

            return null;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Tablo şeması alınamadı: {$tableName}",
                'source' => 'Controllers::getTableSchema',
                'debug' => $e
            ]);

            return null;
        }
    }

    /**
     * Belirtilen tabloda sütunun varlığını kontrol eder
     *
     * @param string $tableName Tablo adı
     * @param string $columnName Sütun adı
     * @return bool Sütun varsa true, yoksa false
     */
    public function columnExists(string $tableName, string $columnName): bool
    {
        try {
            $cacheKey = "column_exists_{$tableName}_{$columnName}";
            
            // Önce RAM önbelleğinden kontrol et
            if (isset($this->memoryCache[$cacheKey])) {
                if ((time() - $this->memoryCache[$cacheKey]['cached_at']) < $this->cacheTTL) {
                    return $this->memoryCache[$cacheKey]['exists'];
                }
            }
            
            $schema = $this->getTableSchema($tableName);

            if (!$schema) {
                // RAM önbelleğine kaydet (sütunun olmadığı bilgisini)
                $this->memoryCache[$cacheKey] = [
                    'exists' => false,
                    'cached_at' => time()
                ];
                return false;
            }

            foreach ($schema['columns'] as $column) {
                if ($column['name'] === $columnName) {
                    // RAM önbelleğine kaydet
                    $cacheKey = "column_exists_{$tableName}_{$columnName}";
                    $this->memoryCache[$cacheKey] = [
                        'exists' => true,
                        'cached_at' => time()
                    ];
                    
                    $this->db->addSuccess([
                        'operation' => 'column_check',
                        'table' => $tableName,
                        'column' => $columnName,
                        'exists' => true
                    ]);

                    return true;
                }
            }

            // RAM önbelleğine kaydet (sütunun olmadığı bilgisini)
            $this->memoryCache["column_exists_{$tableName}_{$columnName}"] = [
                'exists' => false,
                'cached_at' => time()
            ];
            
            $this->db->addErrors([
                'msg' => "Sütun bulunamadı: {$tableName}.{$columnName}",
                'source' => 'Controllers::columnExists'
            ]);

            return false;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Sütun kontrolü sırasında hata: {$tableName}.{$columnName}",
                'source' => 'Controllers::columnExists',
                'debug' => $e
            ]);

            return false;
        }
    }

    /**
     * Tüm tablolardaki şemaları yeniler
     *
     * @param bool $clearMemoryCache RAM önbelleğini temizlemek isteyip istemediğiniz
     * @return array İşlem sonuçları
     */
    public function refreshAllSchemas(bool $clearMemoryCache = true): array
    {
        try {
            $this->db->addSuccess([
                'operation' => 'refresh_all_schemas',
                'started_at' => date('Y-m-d H:i:s')
            ]);
            
            // RAM önbelleğini temizle
            if ($clearMemoryCache) {
                $this->memoryCache = [];
                $this->db->addSuccess([
                    'operation' => 'clear_memory_cache',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }

            $results = $this->checkExistingTables();

            $this->db->addSuccess([
                'operation' => 'refresh_all_schemas',
                'completed_at' => date('Y-m-d H:i:s'),
                'tables_processed' => count($results),
                'memory_cache_cleared' => $clearMemoryCache
            ]);

            return $results;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Şemaları yenilerken hata oluştu",
                'source' => 'Controllers::refreshAllSchemas',
                'debug' => $e
            ]);

            return [];
        }
    }

    /**
     * Model sınıfı ile veritabanı tablosunun şemasını senkronize eder
     *
     * @param mixed $model Model nesnesi
     * @return array İşlem sonuçları
     */
    public function syncModelSchema($model): array
    {
        try {
            $tableName = $model->getTableName();

            // Tablo var mı kontrol et
            if (!$this->tableExists($tableName)) {
                // Tablo yoksa oluştur
                if ($model->create_table()) {
                    $this->cacheTableSchema($tableName);
                    return [
                        'status' => true,
                        'message' => "Tablo oluşturuldu: {$tableName}",
                        'table' => $tableName
                    ];
                } else {
                    return [
                        'status' => false,
                        'message' => "Tablo oluşturulamadı: {$tableName}",
                        'table' => $tableName
                    ];
                }
            }

            // Tablo şemasını al
            $tableSchema = $this->getTableSchema($tableName);
            if (!$tableSchema) {
                return [
                    'status' => false,
                    'message' => "Tablo şeması alınamadı: {$tableName}",
                    'table' => $tableName
                ];
            }

            // Model özelliklerini al
            $modelProperties = $this->getModelProperties($model);

            // Eklenmesi gereken sütunları belirle
            $columnsToAdd = [];
            $columnsToModify = [];
            $columnsToRemove = [];
            $foreignKeysToAdd = [];

            // Model özelliklerini DB şemasıyla karşılaştır
            foreach ($modelProperties as $propName => $propInfo) {
                if (!isset($tableSchema['columns'][$propName])) {
                    // Bu özellik tabloda yok, ekle
                    $columnsToAdd[$propName] = $propInfo;

                    // Eğer bu bir ilişki alanıysa (model tipi), foreign key ekle
                    if ($propInfo['is_model_relation']) {
                        $relatedModelInstance = new $propInfo['type']();
                        $relatedTableName = $relatedModelInstance->getTableName();

                        $foreignKeysToAdd[$propName] = [
                            'column' => $propName,
                            'references_table' => $relatedTableName,
                            'references_column' => 'id'
                        ];
                    }
                } else {
                    // Bu özellik tabloda var, tip kontrolü yap
                    $dbColumn = $tableSchema['columns'][$propName];

                    if ($this->shouldModifyColumn($propInfo, $dbColumn)) {
                        $columnsToModify[$propName] = $propInfo;
                    }
                }
            }

            // Tabloda olan ama modelde olmayan sütunları belirle (silme işlemi için)
            foreach ($tableSchema['columns'] as $colName => $colInfo) {
                if (!isset($modelProperties[$colName])) {
                    $columnsToRemove[$colName] = $colInfo;
                }
            }

            // Güncelleme işlemleri yap
            $changes = [
                'added' => [],
                'modified' => [],
                'removed' => [],
                'foreign_keys_added' => []
            ];

            // Yeni sütunlar ekle
            if (!empty($columnsToAdd)) {
                $addResult = $this->addColumnsToTable($tableName, $columnsToAdd);
                if ($addResult['status']) {
                    $changes['added'] = $addResult['columns'];
                }
            }

            // Mevcut sütunları değiştir
            if (!empty($columnsToModify)) {
                $modifyResult = $this->modifyColumnsInTable($tableName, $columnsToModify);
                if ($modifyResult['status']) {
                    $changes['modified'] = $modifyResult['columns'];
                }
            }

            // Foreign key ekle
            if (!empty($foreignKeysToAdd)) {
                $fkResult = $this->addForeignKeysToTable($tableName, $foreignKeysToAdd);
                if ($fkResult['status']) {
                    $changes['foreign_keys_added'] = $fkResult['foreign_keys'];
                }
            }

            // Gereksiz sütunları kaldır (bu riski azaltmak için genelde yapılmaması önerilir)
            // if (!empty($columnsToRemove)) {
            //     $removeResult = $this->removeColumnsFromTable($tableName, $columnsToRemove);
            //     if ($removeResult['status']) {
            //         $changes['removed'] = $removeResult['columns'];
            //     }
            // }

            // Değişiklikler yapıldı mı?
            $hasChanges = !empty($changes['added']) || !empty($changes['modified']) ||
                !empty($changes['removed']) || !empty($changes['foreign_keys_added']);

            // Tablo şemasını güncelle
            $this->cacheTableSchema($tableName);

            if ($hasChanges) {
                $this->db->addSuccess([
                    'operation' => 'sync_model_schema',
                    'table' => $tableName,
                    'changes' => $changes
                ]);

                return [
                    'status' => 1,
                    'message' => "Tablo şeması güncellendi: {$tableName}",
                    'table' => $tableName,
                    'changes' => $changes
                ];
            } else {
                return [
                    'status' => 2,
                    'message' => "Tablo şeması güncel: {$tableName}",
                    'table' => $tableName
                ];
            }
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Model şema senkronizasyon hatası: " . $e->getMessage(),
                'source' => 'Controllers::syncModelSchema',
                'model' => get_class($model),
                'debug' => $e
            ]);

            return [
                'status' => false,
                'message' => "Hata: " . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Model özelliklerini al
     *
     * @param object $model Model örneği
     * @return array Özellik bilgileri
     */
    private function getModelProperties($model): array
    {
        $properties = [];

        try {
            $ref = new \ReflectionClass($model);
            $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);

            foreach ($props as $prop) {
                $name = $prop->getName();
                $type = $prop->getType()?->getName() ?? 'string';
                $defaultValue = null;

                // Varsayılan değeri almaya çalış
                try {
                    if ($prop->isInitialized($model)) {
                        $defaultValue = $model->{$name};
                    }
                } catch (\Throwable $e) {
                    // Değer alınamadıysa yok sayılır
                }

                $properties[$name] = [
                    'name' => $name,
                    'type' => $type,
                    'default' => $defaultValue,
                    'reflection' => $prop,
                    'is_unique' => $type === 'App\\Database\\Unique' || $type === Unique::class,
                    'is_relation' => class_exists($type) && is_subclass_of($type, Model::class),
                    'is_model_relation' => class_exists($type) && is_subclass_of($type, Model::class)
                ];
            }

            return $properties;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Model özelliklerini alma hatası",
                'source' => 'Controllers::getModelProperties',
                'debug' => $e
            ]);

            return [];
        }
    }

    /**
     * Tabloya yeni sütunlar ekler
     *
     * @param string $tableName Tablo adı
     * @param array $columns Eklenecek sütunlar
     * @return array İşlem sonuçları
     */
    private function addColumnsToTable(string $tableName, array $columns): array
    {
        $results = [
            'status' => false,
            'columns' => []
        ];

        if (empty($columns)) {
            return $results;
        }

        // Mevcut tablo şemasını al
        $tableSchema = $this->getTableSchema($tableName);
        if (!$tableSchema || !isset($tableSchema['columns'])) {
            $this->db->addErrors([
                'msg' => "Tablo şeması alınamadı: {$tableName}",
                'source' => 'Controllers::addColumnsToTable'
            ]);
            return $results;
        }

        $dbType = $this->db->getDatabaseType();
        $uniqueColumns = [];
        $foreignKeys = [];
        $addedColumns = [];

        foreach ($columns as $colName => $colInfo) {
            // Sütun zaten tabloda var mı kontrol et
            if (isset($tableSchema['columns'][$colName])) {
                $this->db->addSuccess([
                    'operation' => 'column_exists',
                    'table' => $tableName,
                    'column' => $colName
                ]);

                // Var olan sütunları sonuçlara ekle ama ALTER TABLE yapmaya çalışma
                $results['columns'][$colName] = [
                    'status' => true,
                    'message' => "Sütun zaten mevcut: {$colName}",
                    'already_exists' => true
                ];

                continue;
            }

            // Model ilişkisi ise, foreign key olarak işaretleyip ilgili SQL tipini belirle
            if ($colInfo['is_relation'] ?? false) {
                // İlişkili model sınıfı
                $relatedModelClass = $colInfo['type'];
                $relatedModel = new $relatedModelClass();

                // Foreign key için otomatik oluşturulacak sütun adı
                $foreignKeyColumn = $colName;

                // İlişkiyi foreign key olarak kaydet
                $foreignKeys[$foreignKeyColumn] = [
                    'related_table' => $relatedModel->getTableName(),
                    'related_column' => 'id', // İlişkilerde genellikle id sütunu referans alınır
                    'column_name' => $foreignKeyColumn
                ];

                // Foreign key için int tipini kullan
                $sqlType = 'INT';
            } else {
                // PHP veri tipini SQL veri tipine dönüştür
                $sqlType = $this->phpTypeToSqlType($colInfo['type'], $dbType);
            }

            // Benzersiz değer kısıtlaması için Unique tipini kontrol et
            $isUnique = $colInfo['is_unique'] ?? false;
            if ($isUnique) {
                $uniqueColumns[] = $colName;
                // Unique türü için string kullan
                if ($sqlType === 'TEXT') {
                    $sqlType = 'VARCHAR(255)';
                }
            }

            // Varsayılan değer varsa ekle
            $defaultClause = '';
            if ($colInfo['default'] !== null) {
                // TEXT sütunlarında varsayılan değer kullanma
                if ($sqlType === 'TEXT') {
                    // TEXT alanları için varsayılan değer MySQL'de izin verilmiyor
                    $defaultClause = "";
                } else if ($colInfo['default'] instanceof Unique) {
                    // Unique nesnesi için değerini al
                    $defaultValue = $colInfo['default']->getValue();

                    if (is_array($defaultValue)) {
                        $defaultValue = json_encode($defaultValue);
                        $defaultClause = " DEFAULT '{$defaultValue}'";
                    } elseif (is_bool($defaultValue)) {
                        $defaultValue = $defaultValue ? 1 : 0;
                        $defaultClause = " DEFAULT {$defaultValue}";
                    } elseif (is_string($defaultValue)) {
                        $defaultValue = DB::pdo()->quote($defaultValue);
                        $defaultClause = " DEFAULT {$defaultValue}";
                    } elseif ($defaultValue !== null) {
                        $defaultClause = " DEFAULT {$defaultValue}";
                    }
                } else if (is_array($colInfo['default'])) {
                    if ($sqlType !== 'TEXT') {
                        $defaultValue = json_encode($colInfo['default']);
                        $defaultClause = " DEFAULT '{$defaultValue}'";
                    }
                } elseif (is_bool($colInfo['default'])) {
                    $defaultValue = $colInfo['default'] ? 1 : 0;
                    $defaultClause = " DEFAULT {$defaultValue}";
                } elseif (is_string($colInfo['default'])) {
                    $defaultValue = DB::pdo()->quote($colInfo['default']);
                    $defaultClause = " DEFAULT {$defaultValue}";
                } else {
                    $defaultClause = " DEFAULT {$colInfo['default']}";
                }
            }

            // ALTER TABLE SQL ifadesini oluştur
            $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$colName}` {$sqlType} NULL{$defaultClause}";

            // Debug için SQL'i kaydet
            $this->db->addSql($sql, []);

            try {
                $stmt = DB::pdo()->prepare($sql);
                $result = $stmt->execute();

                $addedColumns[$colName] = true;
                $results['columns'][$colName] = [
                    'status' => $result,
                    'message' => $result ? "Sütun eklendi: {$colName}" : "Sütun eklenemedi: {$colName}",
                    'sql_type' => $sqlType,
                    'default' => $colInfo['default'],
                    'is_unique' => $isUnique,
                    'is_relation' => $colInfo['is_relation'] ?? false
                ];

                if ($result) {
                    $this->db->addSuccess([
                        'operation' => 'add_column',
                        'table' => $tableName,
                        'column' => $colName,
                        'type' => $sqlType,
                        'is_unique' => $isUnique,
                        'is_relation' => $colInfo['is_relation'] ?? false
                    ]);
                } else {
                    $this->db->addErrors([
                        'msg' => "Sütun ekleme başarısız: {$tableName}.{$colName}",
                        'source' => 'Controllers::addColumnsToTable',
                        'error_info' => $stmt->errorInfo()
                    ]);
                }
            } catch (\PDOException $e) {
                $results['columns'][$colName] = [
                    'status' => false,
                    'message' => "Hata: " . $e->getMessage(),
                    'error' => $e->getMessage()
                ];

                $this->db->addErrors([
                    'msg' => "Sütun ekleme hatası: {$tableName}.{$colName}",
                    'source' => 'Controllers::addColumnsToTable',
                    'debug' => $e
                ]);
            }
        }

        // Unique sütunları için kısıtlama ekle
        if (!empty($uniqueColumns)) {
            foreach ($uniqueColumns as $uniqueColumn) {
                // Sütun eklenmediyse devam etme
                if (!isset($addedColumns[$uniqueColumn])) {
                    continue;
                }

                $indexName = "idx_unique_{$tableName}_{$uniqueColumn}";

                // Kısıtlama zaten var mı kontrol et
                if (isset($tableSchema['constraints']['unique'][$indexName])) {
                    continue;
                }

                $sql = "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$indexName}` UNIQUE (`{$uniqueColumn}`)";

                // Debug için SQL'i kaydet
                $this->db->addSql($sql, []);

                try {
                    $stmt = DB::pdo()->prepare($sql);
                    $result = $stmt->execute();

                    $results['columns']["{$uniqueColumn}_constraint"] = [
                        'status' => $result,
                        'message' => $result ? "Unique kısıtlaması eklendi: {$uniqueColumn}" : "Unique kısıtlaması eklenemedi: {$uniqueColumn}",
                        'index_name' => $indexName
                    ];

                    if ($result) {
                        $this->db->addSuccess([
                            'operation' => 'add_unique_constraint',
                            'table' => $tableName,
                            'column' => $uniqueColumn,
                            'index_name' => $indexName
                        ]);
                    } else {
                        $this->db->addErrors([
                            'msg' => "Unique kısıtlaması eklenemedi: {$tableName}.{$uniqueColumn}",
                            'source' => 'Controllers::addColumnsToTable',
                            'error_info' => $stmt->errorInfo()
                        ]);
                    }
                } catch (\PDOException $e) {
                    $results['columns']["{$uniqueColumn}_constraint"] = [
                        'status' => false,
                        'message' => "Hata: " . $e->getMessage(),
                        'error' => $e->getMessage()
                    ];

                    $this->db->addErrors([
                        'msg' => "Unique kısıtlaması ekleme hatası: {$tableName}.{$uniqueColumn}",
                        'source' => 'Controllers::addColumnsToTable',
                        'debug' => $e
                    ]);
                }
            }
        }

        // Foreign key kısıtlamalarını ekle (ilişki sütunları eklenebilmiş olmalı)
        if (!empty($foreignKeys)) {
            foreach ($foreignKeys as $columnName => $fkInfo) {
                // Sütun eklenmediyse devam etme
                if (!isset($addedColumns[$columnName])) {
                    continue;
                }

                $fkName = "fk_{$tableName}_{$columnName}";

                // Foreign key constraint zaten var mı kontrol et
                if (isset($tableSchema['constraints']['foreign_keys'][$fkName])) {
                    continue;
                }

                $sql = "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$columnName}`) REFERENCES `{$fkInfo['related_table']}` (`{$fkInfo['related_column']}`) ON DELETE RESTRICT ON UPDATE CASCADE";

                // Debug için SQL'i kaydet
                $this->db->addSql($sql, []);

                try {
                    $stmt = DB::pdo()->prepare($sql);
                    $result = $stmt->execute();

                    $results['columns']["{$columnName}_fk"] = [
                        'status' => $result,
                        'message' => $result ? "Foreign key kısıtlaması eklendi: {$columnName}" : "Foreign key kısıtlaması eklenemedi: {$columnName}",
                        'fk_name' => $fkName,
                        'related_table' => $fkInfo['related_table'],
                        'related_column' => $fkInfo['related_column']
                    ];

                    if ($result) {
                        $this->db->addSuccess([
                            'operation' => 'add_foreign_key_constraint',
                            'table' => $tableName,
                            'column' => $columnName,
                            'fk_name' => $fkName,
                            'related_table' => $fkInfo['related_table']
                        ]);
                    } else {
                        $this->db->addErrors([
                            'msg' => "Foreign key kısıtlaması eklenemedi: {$tableName}.{$columnName}",
                            'source' => 'Controllers::addColumnsToTable',
                            'error_info' => $stmt->errorInfo()
                        ]);
                    }
                } catch (\PDOException $e) {
                    $results['columns']["{$columnName}_fk"] = [
                        'status' => false,
                        'message' => "Hata: " . $e->getMessage(),
                        'error' => $e->getMessage()
                    ];

                    $this->db->addErrors([
                        'msg' => "Foreign key kısıtlaması ekleme hatası: {$tableName}.{$columnName}",
                        'source' => 'Controllers::addColumnsToTable',
                        'debug' => $e
                    ]);
                }
            }
        }

        $results['status'] = !empty($results['columns']);
        return $results;
    }

    /**
     * Tablodaki sütunları değiştirir
     *
     * @param string $tableName Tablo adı
     * @param array $columns Değiştirilecek sütunlar
     * @return array İşlem sonuçları
     */
    private function modifyColumnsInTable(string $tableName, array $columns): array
    {
        $results = [];

        try {
            $dbType = $this->db->getDatabaseType();

            foreach ($columns as $colName => $colInfo) {
                // PHP veri tipini SQL veri tipine dönüştür
                $sqlType = $this->phpTypeToSqlType($colInfo['type'], $dbType);

                // Varsayılan değer varsa ekle
                $defaultClause = '';
                if ($colInfo['default'] !== null) {
                    if (is_array($colInfo['default'])) {
                        $defaultValue = json_encode($colInfo['default']);
                        $defaultClause = " DEFAULT '{$defaultValue}'";
                    } elseif (is_bool($colInfo['default'])) {
                        $defaultValue = $colInfo['default'] ? 1 : 0;
                        $defaultClause = " DEFAULT {$defaultValue}";
                    } elseif (is_string($colInfo['default'])) {
                        $defaultValue = DB::pdo()->quote($colInfo['default']);
                        $defaultClause = " DEFAULT {$defaultValue}";
                    } else {
                        $defaultClause = " DEFAULT {$colInfo['default']}";
                    }
                }

                // ALTER TABLE SQL ifadesini oluştur
                $sql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `{$colName}` {$sqlType} NULL{$defaultClause}";

                // Debug için SQL'i kaydet
                $this->db->addSql($sql, []);

                try {
                    $stmt = DB::pdo()->prepare($sql);
                    $result = $stmt->execute();

                    $results[$colName] = [
                        'status' => $result,
                        'message' => $result ? "Sütun değiştirildi: {$colName}" : "Sütun değiştirilemedi: {$colName}",
                        'sql_type' => $sqlType,
                        'default' => $colInfo['default']
                    ];

                    if ($result) {
                        $this->db->addSuccess([
                            'operation' => 'modify_column',
                            'table' => $tableName,
                            'column' => $colName,
                            'type' => $sqlType
                        ]);
                    } else {
                        $this->db->addErrors([
                            'msg' => "Sütun değiştirme başarısız: {$tableName}.{$colName}",
                            'source' => 'Controllers::modifyColumnsInTable',
                            'error_info' => $stmt->errorInfo()
                        ]);
                    }
                } catch (\PDOException $e) {
                    $results[$colName] = [
                        'status' => false,
                        'message' => "Hata: " . $e->getMessage(),
                        'error' => $e->getMessage()
                    ];

                    $this->db->addErrors([
                        'msg' => "Sütun değiştirme hatası: {$tableName}.{$colName}",
                        'source' => 'Controllers::modifyColumnsInTable',
                        'debug' => $e
                    ]);
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Sütun değiştirme işlemi sırasında genel hata: {$tableName}",
                'source' => 'Controllers::modifyColumnsInTable',
                'debug' => $e
            ]);

            return [
                'status' => false,
                'message' => "Genel hata: " . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Tablodan sütunları kaldırır
     *
     * @param string $tableName Tablo adı
     * @param array $columns Kaldırılacak sütunlar
     * @return array İşlem sonuçları
     */
    private function removeColumnsFromTable(string $tableName, array $columns): array
    {
        $results = [];

        try {
            foreach ($columns as $colName => $colInfo) {
                // ALTER TABLE SQL ifadesini oluştur
                $sql = "ALTER TABLE `{$tableName}` DROP COLUMN `{$colName}`";

                // Debug için SQL'i kaydet
                $this->db->addSql($sql, []);

                try {
                    $stmt = DB::pdo()->prepare($sql);
                    $result = $stmt->execute();

                    $results[$colName] = [
                        'status' => $result,
                        'message' => $result ? "Sütun kaldırıldı: {$colName}" : "Sütun kaldırılamadı: {$colName}"
                    ];

                    if ($result) {
                        $this->db->addSuccess([
                            'operation' => 'remove_column',
                            'table' => $tableName,
                            'column' => $colName
                        ]);
                    } else {
                        $this->db->addErrors([
                            'msg' => "Sütun kaldırma başarısız: {$tableName}.{$colName}",
                            'source' => 'Controllers::removeColumnsFromTable',
                            'error_info' => $stmt->errorInfo()
                        ]);
                    }
                } catch (\PDOException $e) {
                    $results[$colName] = [
                        'status' => false,
                        'message' => "Hata: " . $e->getMessage(),
                        'error' => $e->getMessage()
                    ];

                    $this->db->addErrors([
                        'msg' => "Sütun kaldırma hatası: {$tableName}.{$colName}",
                        'source' => 'Controllers::removeColumnsFromTable',
                        'debug' => $e
                    ]);
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Sütun kaldırma işlemi sırasında genel hata: {$tableName}",
                'source' => 'Controllers::removeColumnsFromTable',
                'debug' => $e
            ]);

            return [
                'status' => false,
                'message' => "Genel hata: " . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * PHP veri tipini SQL veri tipine dönüştürür
     *
     * @param string $phpType PHP veri tipi
     * @param string $dbType Veritabanı türü
     * @return string SQL veri tipi
     */
    private function phpTypeToSqlType(string $phpType, string $dbType): string
    {
        // Statik önbellekleme
        static $typeMapping = [];
        $cacheKey = "{$phpType}_{$dbType}";
        
        if (isset($typeMapping[$cacheKey])) {
            return $typeMapping[$cacheKey];
        }
        
        $result = '';
        switch ($phpType) {
            case 'int':
            case 'integer':
                $result = 'INT';
                break;
            case 'float':
            case 'double':
                $result = 'FLOAT';
                break;
            case 'bool':
            case 'boolean':
                $result = 'TINYINT(1)';
                break;
            case 'array':
            case 'object':
                $result = 'TEXT';
                break;
            case 'App\\Database\\Unique':
            case Unique::class:
                $result = 'VARCHAR(255)';
                break;
            case 'string':
                $result = 'VARCHAR(255)';
                break;
            default:
                $result = 'VARCHAR(255)';
        }
        
        // Sonucu önbelleğe kaydet
        $typeMapping[$cacheKey] = $result;
        return $result;
    }

    /**
     * Tabloya foreign key kısıtlamaları ekler
     *
     * @param string $tableName Tablo adı
     * @param array $foreignKeys Eklenecek foreign key'ler
     * @return array İşlem sonuçları
     */
    private function addForeignKeysToTable(string $tableName, array $foreignKeys): array
    {
        $results = [
            'status' => false,
            'foreign_keys' => []
        ];

        if (empty($foreignKeys)) {
            return $results;
        }

        $dbType = $this->db->getDatabaseType();
        if ($dbType !== 'mysql') {
            $this->db->addErrors([
                'msg' => "Foreign key ekleme sadece MySQL veritabanında destekleniyor",
                'source' => 'Controllers::addForeignKeysToTable',
                'table' => $tableName
            ]);
            return $results;
        }

        // Mevcut tablo şemasını al
        $tableSchema = $this->getTableSchema($tableName);
        if (!$tableSchema) {
            $this->db->addErrors([
                'msg' => "Tablo şeması alınamadı: {$tableName}",
                'source' => 'Controllers::addForeignKeysToTable'
            ]);
            return $results;
        }

        $addedKeys = [];
        $errors = [];

        foreach ($foreignKeys as $keyName => $keyInfo) {
            $column = $keyInfo['column'];
            $referencesTable = $keyInfo['references_table'];
            $referencesColumn = $keyInfo['references_column'];

            // Foreign key constraint adı
            $constraintName = "fk_{$tableName}_{$column}";

            // Kısıtlama zaten var mı kontrol et
            if (isset($tableSchema['constraints']['foreign_keys'][$constraintName])) {
                $this->db->addSuccess([
                    'operation' => 'foreign_key_exists',
                    'table' => $tableName,
                    'column' => $column,
                    'constraint_name' => $constraintName
                ]);

                $addedKeys[$column] = [
                    'column' => $column,
                    'references_table' => $referencesTable,
                    'references_column' => $referencesColumn,
                    'constraint_name' => $constraintName,
                    'already_exists' => true
                ];

                continue;
            }

            // Foreign key ekle
            $sql = "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$constraintName}` 
                    FOREIGN KEY (`{$column}`) REFERENCES `{$referencesTable}`(`{$referencesColumn}`) 
                    ON DELETE SET NULL ON UPDATE CASCADE";

            try {
                $this->db->addSql($sql, []);
                $stmt = DB::pdo()->prepare($sql);
                $stmt->execute();

                $addedKeys[$column] = [
                    'column' => $column,
                    'references_table' => $referencesTable,
                    'references_column' => $referencesColumn,
                    'constraint_name' => $constraintName,
                    'already_exists' => false
                ];

                $this->db->addSuccess([
                    'operation' => 'add_foreign_key',
                    'table' => $tableName,
                    'column' => $column,
                    'references' => "{$referencesTable}.{$referencesColumn}"
                ]);
            } catch (\PDOException $e) {
                // Foreign key zaten varsa veya başka bir hata olduysa
                $errors[$column] = $e->getMessage();

                $this->db->addErrors([
                    'msg' => "Foreign key ekleme hatası: {$constraintName}",
                    'source' => 'Controllers::addForeignKeysToTable',
                    'table' => $tableName,
                    'column' => $column,
                    'sql' => $sql,
                    'debug' => $e
                ]);
            }
        }

        $results['status'] = !empty($addedKeys);
        $results['foreign_keys'] = $addedKeys;
        $results['errors'] = $errors;

        return $results;
    }

    /**
     * Bir sütunun değiştirilmesi gerekip gerekmediğini kontrol eder
     * 
     * @param array $modelProperty Model özelliği bilgileri
     * @param array $dbColumn Veritabanı sütun bilgileri
     * @return bool Değiştirilmesi gerekiyorsa true
     */
    private function shouldModifyColumn(array $modelProperty, array $dbColumn): bool
    {
        $phpType = $modelProperty['type'];
        $dbType = $this->db->getDatabaseType();
        
        // İlişkisel model alanları için her zaman INT olmalı
        if ($modelProperty['is_model_relation']) {
            return !str_contains(strtolower($dbColumn['type']), 'int');
        }
        
        // PHP tipini SQL tipine dönüştür
        $sqlType = strtolower($this->phpTypeToSqlType($phpType, $dbType));
        $dbColType = strtolower($dbColumn['type']);
        
        // Eğer ilkel bir tip değilse (örn. Unique), karşılaştırma yapmaz
        if ($sqlType === null) {
            return false;
        }
        
        if (strpos($sqlType, 'varchar') !== false && strpos($dbColType, 'varchar') !== false) {
            // Her ikisi de varchar - uzunluk kontrolü yapmadan eşleşti kabul et
            return false;
        }
        
        if (strpos($sqlType, 'int') !== false && strpos($dbColType, 'int') !== false) {
            // Her ikisi de int tipinde - detay kontrolü yapmadan eşleşti kabul et
            return false;
        }
        
        if ((strpos($sqlType, 'float') !== false || strpos($sqlType, 'double') !== false) && 
            (strpos($dbColType, 'float') !== false || strpos($dbColType, 'double') !== false)) {
            // Her ikisi de float/double tipinde - eşleşti kabul et
            return false;
        }
        
        if ($sqlType === 'text' && $dbColType === 'text') {
            // Her ikisi de text - eşleşti kabul et
            return false;
        }
        
        // Tip uyuşmazlığı varsa değişiklik gerekiyor
        return !str_contains($dbColType, $sqlType);
    }
    
    /**
     * Önbellek temizliği yapar ve eski dosyaları temizler
     * 
     * @param int $maxFiles Saklanacak maksimum önbellek dosya sayısı
     * @return void
     */
    private function cleanupCache(int $maxFiles = 500): void 
    {
        // Her zaman temizlik yapmaya gerek yok, belirli aralıklarla yap
        if ((time() - $this->lastCacheCleanup) < 3600) { // Saatte bir temizlik kontrolü
            return;
        }
        
        $this->lastCacheCleanup = time();
        
        try {
            // Önbellek dizini var mı kontrol et
            if (!is_dir(self::CACHE_DIR)) {
                return;
            }
            
            $cacheFiles = [];
            $files = scandir(self::CACHE_DIR);
            
            // Önbellek dosyalarını bul ve sırala
            foreach ($files as $file) {
                if (strpos($file, self::SCHEMA_PREFIX) === 0) {
                    $filePath = self::CACHE_DIR . '/' . $file;
                    $cacheFiles[$filePath] = filemtime($filePath);
                }
            }
            
            // Dosya sayısı limiti aşılmadıysa işlem yapma
            if (count($cacheFiles) <= $maxFiles) {
                return;
            }
            
            // Dosyaları son değiştirilme tarihine göre sırala (en eski en başta)
            asort($cacheFiles);
            
            // Silinecek dosya sayısını hesapla
            $filesToDelete = count($cacheFiles) - $maxFiles;
            
            // En eski dosyaları sil
            $deleted = 0;
            foreach ($cacheFiles as $filePath => $mtime) {
                if ($deleted >= $filesToDelete) {
                    break;
                }
                
                if (unlink($filePath)) {
                    $deleted++;
                }
            }
            
            $this->db->addSuccess([
                'operation' => 'cache_cleanup',
                'deleted_files' => $deleted,
                'total_files' => count($cacheFiles),
                'max_files' => $maxFiles,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Önbellek temizleme hatası",
                'source' => 'Controllers::cleanupCache',
                'debug' => $e
            ]);
        }
    }
    
    /**
     * Önbellek istatistiklerini döndürür
     * 
     * @return array Önbellek istatistikleri
     */
    public function getCacheStats(): array
    {
        $fileCacheStats = [
            'total' => 0,
            'valid' => 0,
            'expired' => 0,
            'size' => 0
        ];
        
        // Dosya önbelleği istatistikleri
        if (is_dir(self::CACHE_DIR)) {
            $files = scandir(self::CACHE_DIR);
            foreach ($files as $file) {
                if (strpos($file, self::SCHEMA_PREFIX) === 0) {
                    $fileCacheStats['total']++;
                    $filePath = self::CACHE_DIR . '/' . $file;
                    $fileAge = time() - filemtime($filePath);
                    
                    if ($fileAge < $this->cacheTTL) {
                        $fileCacheStats['valid']++;
                    } else {
                        $fileCacheStats['expired']++;
                    }
                    
                    $fileCacheStats['size'] += filesize($filePath);
                }
            }
        }
        
        // RAM önbelleği istatistikleri
        $memoryCacheStats = [
            'items' => count($this->memoryCache),
            'valid' => 0,
            'expired' => 0
        ];
        
        foreach ($this->memoryCache as $cacheKey => $cacheItem) {
            if ((time() - $cacheItem['cached_at']) < $this->cacheTTL) {
                $memoryCacheStats['valid']++;
            } else {
                $memoryCacheStats['expired']++;
            }
        }
        
        return [
            'file_cache' => $fileCacheStats,
            'memory_cache' => $memoryCacheStats,
            'cache_ttl' => $this->cacheTTL,
            'last_cleanup' => date('Y-m-d H:i:s', $this->lastCacheCleanup),
            'next_cleanup' => date('Y-m-d H:i:s', $this->lastCacheCleanup + 3600)
        ];
    }
}