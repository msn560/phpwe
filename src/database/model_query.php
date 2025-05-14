<?php
namespace App\Database;
class ModelQuery
{
    private $modelClass;
    private $conditions = [];
    private $orderBy = ['field' => 'id', 'direction' => 'ASC'];
    private $limit = null;
    private $offset = 0;
    
    /**
     * ModelQuery sınıfını başlatır
     * 
     * @param string $modelClass Model sınıfı
     */
    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }
    
    /**
     * Belirtilen alanın değerine göre kayıt bulma koşulu ekler
     * 
     * @param string $field Alan adı
     * @param mixed $value Değer
     * @param string $operator Operatör (varsayılan: =)
     * @return ModelQuery Kendisi (zincirleme için)
     */
    public function where(string $field, $value, string $operator = '='): ModelQuery
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => $value,
            'operator' => $operator
        ];
        return $this;
    }
    
    /**
     * Sıralama kriteri ekler
     * 
     * @param string $field Alan adı
     * @param string $direction Yön (ASC/DESC)
     * @return ModelQuery Kendisi (zincirleme için)
     */
    public function orderBy(string $field, string $direction = 'ASC'): ModelQuery
    {
        $this->orderBy = [
            'field' => $field,
            'direction' => strtoupper($direction)
        ];
        return $this;
    }
    
    /**
     * Sonuç sayısını sınırlar
     * 
     * @param int $limit Limit
     * @param int $offset Başlangıç konumu
     * @return ModelQuery Kendisi (zincirleme için)
     */
    public function limit(int $limit, int $offset = 0): ModelQuery
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * İlk kaydı döndürür
     * 
     * @return mixed Model nesnesi veya null
     */
    public function first()
    {
        // Sadece 1 kayıt al
        $this->limit = 1;
        $this->offset = 0;
        
        $results = $this->get();
        return empty($results) ? null : $results[0];
    }
    
    /**
     * Tüm kayıtları döndürür
     * 
     * @return array Model nesneleri dizisi
     */
    public function get(): array
    {
        // SQL sorgusu oluştur
        $model = new $this->modelClass();
        $table = $model->getTableName();
        
        $query = DB::query($table);
        
        // Koşulları ekle
        foreach ($this->conditions as $condition) {
            $query->where($condition['field'], $condition['value'], $condition['operator']);
        }
        
        // Sıralama ekle
        if ($this->orderBy) {
            $query->orderBy($this->orderBy['field'], $this->orderBy['direction']);
        }
        
        // Limit ekle
        if ($this->limit !== null) {
            $query->limit($this->limit, $this->offset);
        }
        
        // Sorguyu çalıştır
        $results = $query->get();
        
        // Sonuçları model nesnelerine dönüştür
        return array_map(function($item) {
            return call_user_func([$this->modelClass, 'createFromArray'], $item);
        }, $results);
    }
    
    /**
     * Belirtilen ID'ye sahip kaydı döndürür
     * 
     * @param int $id Kayıt ID
     * @return mixed Model nesnesi veya null
     */
    public function find(int $id)
    {
        return $this->where('id', $id)->first();
    }
    
    /**
     * Belirtilen koşullara göre kayıt sayısını döndürür
     * 
     * @return int Kayıt sayısı
     */
    public function count(): int
    {
        $model = new $this->modelClass();
        $table = $model->getTableName();
        
        $query = DB::query($table);
        
        // Koşulları ekle
        foreach ($this->conditions as $condition) {
            $query->where($condition['field'], $condition['value'], $condition['operator']);
        }
        
        return $query->count();
    }
    
    /**
     * Belirtilen koşullara göre kayıtları günceller
     * 
     * @param array $data Güncellenecek veriler
     * @return int Etkilenen kayıt sayısı
     */
    public function update(array $data): int
    {
        if (empty($this->conditions)) {
            throw new \Exception("Güncelleme için en az bir koşul gereklidir.");
        }
        
        $model = new $this->modelClass();
        $table = $model->getTableName();
        
        $update = DB::update($table);
        
        // Koşulları ekle
        foreach ($this->conditions as $condition) {
            $update->where($condition['field'], $condition['value'], $condition['operator']);
        }
        
        // Verileri ayarla
        $update->set($data);
        
        // Güncelleme işlemini yap
        $result = $update->execute();
        
        return $result ? 1 : 0; // Başarılı ise 1, değilse 0 döndür
    }
    
    /**
     * Belirtilen koşullara göre kayıtları siler
     * 
     * @return int Etkilenen kayıt sayısı
     */
    public function delete(): int
    {
        if (empty($this->conditions)) {
            throw new \Exception("Silme işlemi için en az bir koşul gereklidir.");
        }
        
        $model = new $this->modelClass();
        $table = $model->getTableName();
        
        $delete = DB::delete($table);
        
        // Koşulları ekle
        foreach ($this->conditions as $condition) {
            $delete->where($condition['field'], $condition['value'], $condition['operator']);
        }
        
        // Silme işlemini yap
        $result = $delete->execute();
        
        return $result ? 1 : 0; // Başarılı ise 1, değilse 0 döndür
    }
    
    /**
     * Yeni bir kayıt oluşturur
     * 
     * @param array $data Eklenecek veriler
     * @return int Yeni kaydın ID'si veya 0
     */
    public function create(array $data): int
    {
        $model = new $this->modelClass();
        $table = $model->getTableName();
        
        $insert = DB::insert($table);
        $insert->values($data);
        
        // Ekleme işlemini yap
        $id = $insert->execute();
        
        return $id ?? 0;
    }
}
 