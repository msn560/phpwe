<?php

namespace App\Database;

class Insert{
    private $db;
    private $table;
    private $data = [];
    
    public function __construct($db){
        $this->db = $db; 
    }
    
    public function setTable($table)
    {
        $this->table = $table;
        return $this;
    }
    
    public function values(array $data)
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }
    
    public function execute()
    {
        try {
            if (empty($this->table)) {
                $this->db->addErrors([
                    'msg' => "Tablo adı belirtilmedi",
                    'source' => 'Insert::execute'
                ]);
                throw new \Exception("Tablo adı belirtilmedi.");
            }
            
            if (empty($this->data)) {
                $this->db->addErrors([
                    'msg' => "Eklenecek veri belirtilmedi",
                    'source' => 'Insert::execute',
                    'table' => $this->table
                ]);
                throw new \Exception("Eklenecek veri belirtilmedi.");
            }
            
            $columns = array_keys($this->data);
            $placeholders = array_fill(0, count($columns), '?');
            $values = array_values($this->data);
            
            $sql = "INSERT INTO `{$this->table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            
            // Debug için SQL'i kaydet
            $this->db->addSql($sql, $values);
            
            try {
                $stmt = DB::pdo()->prepare($sql);
                $result = $stmt->execute($values);
                
                if ($result) {
                    $lastId = DB::pdo()->lastInsertId();
                    $this->db->addSuccess([
                        'operation' => 'insert',
                        'table' => $this->table,
                        'last_insert_id' => $lastId,
                        'data_keys' => $columns
                    ]);
                    
                    // İlişkili tabloların önbelleklerini temizle
                    $this->cleanCacheWithRelations();
                    
                    return $lastId;
                } else {
                    $this->db->addErrors([
                        'msg' => "SQL ekleme işlemi başarısız",
                        'source' => 'Insert::execute',
                        'table' => $this->table,
                        'error_info' => $stmt->errorInfo()
                    ]);
                }
                
                return false;
            } catch (\PDOException $e) {
                $this->db->addErrors([
                    'msg' => "SQL ekleme hatası: {$this->table}",
                    'source' => 'Insert::execute',
                    'table' => $this->table,
                    'query' => $sql,
                    'debug' => $e
                ]);
                return false;
            }
        } catch (\Exception $e) {
            // Genel hatalar
            $this->db->addErrors([
                'msg' => "Ekleme işlemi hatası",
                'source' => 'Insert::execute',
                'table' => $this->table ?? 'Belirtilmemiş',
                'debug' => $e
            ]);
            return false;
        }
    }
    
    /**
     * İlişkili tabloların önbelleklerini temizler
     * 
     * @return void
     */
    private function cleanCacheWithRelations()
    {
        try {
            // Ana tablonun önbelleğini temizle
            $this->db->cache()->delete($this->table);
            
            // İlişkili tabloları bulmaya çalış
            $relatedTables = [];
            
            // Foreign key ilişkileri olan tabloları belirle
            // Bu tabloya referans veren diğer tablolar
            try {
                $allTables = DB::controllers()->getAllTables();
                foreach ($allTables as $tableName) {
                    if ($tableName === $this->table) {
                        continue;
                    }
                    
                    $otherSchema = DB::controllers()->getTableSchema($tableName);
                    if ($otherSchema && isset($otherSchema['foreign_keys'])) {
                        foreach ($otherSchema['foreign_keys'] as $fk) {
                            if (isset($fk['referenced_table']) && $fk['referenced_table'] === $this->table) {
                                $relatedTables[] = $tableName;
                                break;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // İlişkili tabloları bulamadık, devam et
            }
            
            // Benzersiz ilişkili tablolar için önbelleği temizle
            $relatedTables = array_unique($relatedTables);
            foreach ($relatedTables as $tableName) {
                $this->db->cache()->delete($tableName);
                
                $this->db->addSuccess([
                    'operation' => 'cache_clean_related',
                    'table' => $this->table,
                    'related_table' => $tableName
                ]);
            }
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Önbellek temizleme hatası",
                'source' => 'Insert::cleanCacheWithRelations',
                'table' => $this->table,
                'debug' => $e
            ]);
        }
    }
}