<?php

namespace App\Database;

class Update{
    private $db;
    private $table;
    private $conditions = [];
    private $data = [];
    
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
    
    public function set($data)
    {
        if (is_array($data)) {
            $this->data = array_merge($this->data, $data);
        }
        return $this;
    }
    
    public function execute()
    {
        try {
            if (empty($this->table)) {
                $this->db->addErrors([
                    'msg' => "Tablo adı belirtilmedi",
                    'source' => 'Update::execute'
                ]);
                throw new \Exception("Tablo adı belirtilmedi.");
            }
            
            if (empty($this->data)) {
                $this->db->addErrors([
                    'msg' => "Güncellenecek veri belirtilmedi",
                    'source' => 'Update::execute',
                    'table' => $this->table
                ]);
                throw new \Exception("Güncellenecek veri belirtilmedi.");
            }
            
            $setStatements = [];
            $params = [];
            
            foreach ($this->data as $column => $value) {
                $setStatements[] = "`{$column}` = ?";
                $params[] = $value;
            }
            
            $whereClause = '';
            if (!empty($this->conditions)) {
                $whereParts = [];
                foreach ($this->conditions as $condition) {
                    $whereParts[] = "`{$condition['column']}` {$condition['operator']} ?";
                    $params[] = $condition['value'];
                }
                $whereClause = " WHERE " . implode(' AND ', $whereParts);
            }
            
            $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setStatements) . $whereClause;
            
            // Debug için SQL'i kaydet
            $this->db->addSql($sql, $params);
            
            try {
                $stmt = DB::pdo()->prepare($sql);
                $result = $stmt->execute($params);
                
                if ($result) {
                    $this->db->addSuccess([
                        'operation' => 'update',
                        'table' => $this->table,
                        'affected_rows' => $stmt->rowCount(),
                        'conditions' => $this->conditions
                    ]);
                } else {
                    $this->db->addErrors([
                        'msg' => "SQL güncelleme başarısız",
                        'source' => 'Update::execute',
                        'table' => $this->table,
                        'error_info' => $stmt->errorInfo()
                    ]);
                }
                
                // İlişkili tabloları bul ve önbelleği temizle
                $this->cleanCacheWithRelations();
                
                return $result;
            } catch (\PDOException $e) {
                $this->db->addErrors([
                    'msg' => "SQL güncelleme hatası: {$this->table}",
                    'source' => 'Update::execute',
                    'table' => $this->table,
                    'debug' => $e
                ]);
                return false;
            }
        } catch (\Exception $e) {
            // Genel hatalar
            $this->db->addErrors([
                'msg' => "Güncelleme işlemi hatası",
                'source' => 'Update::execute',
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
            
            // Şu anki tabloya referans veren diğer tabloları bulmaya çalış
            // Bu genellikle daha karmaşık bir işlemdir, burada basitleştirilmiş bir yaklaşım kullanıyoruz
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
                // İlişkili tabloları bulamadık, sadece devam et
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
                'source' => 'Update::cleanCacheWithRelations',
                'table' => $this->table,
                'debug' => $e
            ]);
        }
    }
}