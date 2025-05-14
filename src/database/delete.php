<?php

namespace App\Database;

class Delete{
    private $db;
    private $table;
    private $conditions = [];
    
    public function __construct($db){
        $this->db = $db; 
    }
    
    public function setTable($table)
    {
        $this->table = $table;
        return $this;
    }
    
    public function where($column, $value, $operator = '=')
    {
        $this->conditions[] = [
            'column' => $column,
            'value' => $value,
            'operator' => $operator
        ];
        return $this;
    }
    
    public function execute()
    {
        try {
            if (empty($this->table)) {
                $this->db->addErrors([
                    'msg' => "Tablo adı belirtilmedi",
                    'source' => 'Delete::execute'
                ]);
                throw new \Exception("Tablo adı belirtilmedi.");
            }
            
            $whereClause = '';
            $params = [];
            
            if (!empty($this->conditions)) {
                $whereParts = [];
                foreach ($this->conditions as $condition) {
                    $whereParts[] = "`{$condition['column']}` {$condition['operator']} ?";
                    $params[] = $condition['value'];
                }
                $whereClause = " WHERE " . implode(' AND ', $whereParts);
            } else {
                $this->db->addErrors([
                    'msg' => "Silme işlemi için en az bir koşul belirtilmedi",
                    'source' => 'Delete::execute',
                    'table' => $this->table
                ]);
                throw new \Exception("Silme işlemi için en az bir koşul belirtmelisiniz.");
            }
            
            $sql = "DELETE FROM `{$this->table}`{$whereClause}";
            
            // Debug için SQL'i kaydet
            $this->db->addSql($sql, $params);
            
            try {
                $stmt = DB::pdo()->prepare($sql);
                $result = $stmt->execute($params);
                
                if ($result) {
                    $rowCount = $stmt->rowCount();
                    
                    // İlişkili tabloların önbelleklerini temizle
                    $this->cleanCacheWithRelations();
                    
                    $this->db->addSuccess([
                        'operation' => 'delete',
                        'table' => $this->table,
                        'affected_rows' => $rowCount,
                        'conditions' => $this->conditions
                    ]);
                    
                    // Hiç kayıt silinmediyse uyarı koy
                    if ($rowCount === 0) {
                        $this->db->addErrors([
                            'msg' => "Silme işlemi tamamlandı, ancak etkilenen kayıt yok",
                            'source' => 'Delete::execute',
                            'table' => $this->table,
                            'conditions' => $this->conditions,
                            'is_warning' => true
                        ]);
                    }
                } else {
                    $this->db->addErrors([
                        'msg' => "SQL silme işlemi başarısız",
                        'source' => 'Delete::execute',
                        'table' => $this->table,
                        'error_info' => $stmt->errorInfo()
                    ]);
                }
                
                return $result;
            } catch (\PDOException $e) {
                $this->db->addErrors([
                    'msg' => "SQL silme hatası: {$this->table}",
                    'source' => 'Delete::execute',
                    'table' => $this->table,
                    'query' => $sql,
                    'params' => $params,
                    'debug' => $e
                ]);
                return false;
            }
        } catch (\Exception $e) {
            // Genel hatalar
            $this->db->addErrors([
                'msg' => "Silme işlemi hatası",
                'source' => 'Delete::execute',
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
            
            // Tablo şemasını al (mümkünse)
            $schema = DB::controllers()->getTableSchema($this->table);
            if ($schema && isset($schema['foreign_keys'])) {
                foreach ($schema['foreign_keys'] as $fk) {
                    if (isset($fk['referenced_table'])) {
                        $relatedTables[] = $fk['referenced_table'];
                    }
                }
            }
            
            // Şu anki tabloya referans veren diğer tabloları bul
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
                'source' => 'Delete::cleanCacheWithRelations',
                'table' => $this->table,
                'debug' => $e
            ]);
        }
    }
}