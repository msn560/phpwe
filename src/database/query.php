<?php

namespace App\Database;

class Query{
    private $db;
    private $table;
    private $select = '*';
    private $where = [];
    private $orderBy = null;
    private $limit = null;
    private $offset = null;
    private $params = [];
    
    public function __construct($db){
        $this->db = $db; 
    }
    
    public function setTable($table)
    {
        $this->table = $table;
        return $this;
    }
    
    public function select($fields)
    {
        $this->select = $fields;
        return $this;
    }
    
    public function where($column, $value, $operator = '=')
    {
        // Model nesnelerini değere dönüştür
        if ($value instanceof Model) {
            $value = $value->id;
        }
        
        $this->where[] = [
            'column' => $column,
            'value' => $value,
            'operator' => $operator
        ];
        return $this;
    }
    
    public function orderBy($column, $direction = 'ASC')
    {
        $this->orderBy = "`{$column}` " . strtoupper($direction);
        return $this;
    }
    
    public function limit($limit, $offset = 0)
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }
    
    private function buildQuery($countOnly = false)
    {
        try {
            if (empty($this->table)) {
                $this->db->addErrors([
                    'msg' => "Tablo adı belirtilmedi",
                    'source' => 'Query::buildQuery'
                ]);
                throw new \Exception("Tablo adı belirtilmedi.");
            }
            
            $select = $countOnly ? "COUNT(*)" : $this->select;
            $sql = "SELECT {$select} FROM `{$this->table}`";
            
            $this->params = [];
            
            if (!empty($this->where)) {
                $whereParts = [];
                foreach ($this->where as $condition) {
                    $whereParts[] = "`{$condition['column']}` {$condition['operator']} ?";
                    $this->params[] = $condition['value'];
                }
                $sql .= " WHERE " . implode(' AND ', $whereParts);
            }
            
            if (!$countOnly && $this->orderBy) {
                $sql .= " ORDER BY {$this->orderBy}";
            }
            
            if (!$countOnly && $this->limit) {
                $sql .= " LIMIT {$this->limit}";
                
                if ($this->offset > 0) {
                    $sql .= " OFFSET {$this->offset}";
                }
            }
            
            return $sql;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Sorgu oluşturma hatası",
                'source' => 'Query::buildQuery',
                'table' => $this->table ?? 'Belirtilmemiş',
                'debug' => $e
            ]);
            throw $e; // Yeniden fırlat
        }
    }
    
    public function get()
    {
        try {
            $sql = $this->buildQuery();
            

            // Önbellekte kontrol et (sorgu ve parametreleri kullanarak)
            $cachedResult = $this->db->cache()->get($this->table, $sql, $this->params);
            if ($cachedResult !== null) {
                return $cachedResult;
            }

            // Debug için SQL'i kaydet
            $this->db->addSql($sql, $this->params);
            
            try {
                $stmt = DB::pdo()->prepare($sql);
                $stmt->execute($this->params);
                
                $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                
                $this->db->addSuccess([
                    'operation' => 'select',
                    'table' => $this->table,
                    'rows_returned' => count($results),
                    'where_conditions' => $this->where
                ]);
                
                // Sonucu önbelleğe kaydet (parametrelerle birlikte)
                $this->db->cache()->set($this->table, $sql, $results, $this->params);
                return $results;
            } catch (\PDOException $e) {
                $this->db->addErrors([
                    'msg' => "SQL sorgu hatası: {$this->table}",
                    'source' => 'Query::get',
                    'table' => $this->table,
                    'query' => $sql,
                    'params' => $this->params,
                    'debug' => $e
                ]);
                return [];
            }
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Sorgu çalıştırma hatası",
                'source' => 'Query::get',
                'table' => $this->table ?? 'Belirtilmemiş',
                'debug' => $e
            ]);
            return [];
        }
    }
    
    public function first()
    {
        try {
            $originalLimit = $this->limit;
            $originalOffset = $this->offset;
            
            $this->limit = 1;
            $this->offset = 0;
            
            $result = $this->get();
            
            $this->limit = $originalLimit;
            $this->offset = $originalOffset;
            
            $found = !empty($result);
            
            $this->db->addSuccess([
                'operation' => 'select_first',
                'table' => $this->table,
                'where_conditions' => $this->where,
                'found' => $found
            ]);
            
            return $found ? $result[0] : null;
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "İlk kayıt sorgulama hatası",
                'source' => 'Query::first',
                'table' => $this->table ?? 'Belirtilmemiş',
                'debug' => $e
            ]);
            return null;
        }
    }
    
    public function count()
    {
        try {
            $sql = $this->buildQuery(true);
            
            // Önbellekte kontrol et (sorgu ve parametreleri kullanarak)
            $cachedCount = $this->db->cache()->get($this->table, $sql, $this->params);
            if ($cachedCount !== null) {
                return $cachedCount;
            }
            
            // Debug için SQL'i kaydet
            $this->db->addSql($sql, $this->params);
            
            try {
                $stmt = DB::pdo()->prepare($sql);
                $stmt->execute($this->params);
                
                $count = (int)$stmt->fetchColumn();
                
                $this->db->addSuccess([
                    'operation' => 'count',
                    'table' => $this->table,
                    'count' => $count,
                    'where_conditions' => $this->where
                ]);
                
                // Sonucu önbelleğe kaydet (parametrelerle birlikte)
                $this->db->cache()->set($this->table, $sql, $count, $this->params);
                return $count;
            } catch (\PDOException $e) {
                $this->db->addErrors([
                    'msg' => "SQL sayım hatası: {$this->table}",
                    'source' => 'Query::count',
                    'table' => $this->table,
                    'query' => $sql,
                    'params' => $this->params,
                    'debug' => $e
                ]);
                return 0;
            }
        } catch (\Exception $e) {
            $this->db->addErrors([
                'msg' => "Sayım sorgusu hatası",
                'source' => 'Query::count',
                'table' => $this->table ?? 'Belirtilmemiş',
                'debug' => $e
            ]);
            return 0;
        }
    }
}