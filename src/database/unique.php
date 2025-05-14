<?php

namespace App\Database;

/**
 * Benzersiz (unique) değer kısıtlaması için kullanılan sınıf
 */
class Unique
{
    public $value;
    private $columnName;
    
    /**
     * Yeni bir Unique değer oluşturur
     * 
     * @param mixed $value Değer
     */
    public function __construct($value = null)
    {
        $this->value = $value;
    }
    
    /**
     * Değeri ayarlar
     * 
     * @param mixed $value Değer
     * @return Unique
     */
    public function setValue($value): Unique
    {
        $this->value = $value;
        return $this;
    }
    
    /**
     * Değeri döndürür
     * 
     * @return mixed Değer
     */
    public function getValue()
    {
        return $this->value;
    }
    
    /**
     * Sütun adını ayarlar
     * 
     * @param string $columnName Sütun adı
     * @return Unique
     */
    public function setColumnName(string $columnName): Unique
    {
        $this->columnName = $columnName;
        return $this;
    }
    
    /**
     * Sütun adını döndürür
     * 
     * @return string Sütun adı
     */
    public function getColumnName(): string
    {
        return $this->columnName;
    }
    
    /**
     * Değerin belirtilen tabloda benzersiz olup olmadığını kontrol eder
     * 
     * @param string $table Tablo adı
     * @param string $column Sütun adı
     * @param int|null $excludeId Hariç tutulacak ID
     * @return bool Benzersizse true, değilse false
     */
    public function isUnique(string $table, string $column, ?int $excludeId = null): bool
    {
        $query = DB::query($table)->where($column, $this->value);
        
        // Kendisi hariç kontrol ediliyorsa (güncelleme durumunda)
        if ($excludeId !== null) {
            $query->where('id', $excludeId, '!=');
        }
        
        $count = $query->count();
        return $count === 0;
    }
    
    /**
     * Değeri string olarak döndürür
     * 
     * @return string Değer
     */
    public function __toString()
    {
        // Değer null ise boş string döndür
        if ($this->value === null) {
            return '';
        }
        
        // Değer array veya object ise JSON'a dönüştür
        if (is_array($this->value) || is_object($this->value)) {
            return json_encode($this->value);
        }
        
        // Diğer tipleri string'e çevir
        return (string)$this->value;
    }
}