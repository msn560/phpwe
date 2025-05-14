<?php

namespace App\Database;

/**
 * Model ilişkilerini yönetmek için kullanılan sınıf
 */
class Relation
{
    const HAS_ONE = 'hasOne';
    const HAS_MANY = 'hasMany';
    const BELONGS_TO = 'belongsTo';
    
    private $sourceModel;
    private $relationType;
    private $targetModel;
    private $foreignKey;
    private $localKey;
    
    /**
     * Yeni bir Relation örneği oluşturur
     * 
     * @param object $sourceModel Kaynak model
     * @param string $relationType İlişki türü (hasOne, hasMany, belongsTo)
     * @param string $targetModel Hedef model sınıfı
     * @param string|null $foreignKey Yabancı anahtar
     * @param string $localKey Yerel anahtar
     */
    public function __construct($sourceModel, string $relationType, string $targetModel, ?string $foreignKey = null, string $localKey = 'id')
    {
        $this->sourceModel = $sourceModel;
        $this->relationType = $relationType;
        $this->targetModel = $targetModel;
        $this->localKey = $localKey;
        
        // Yabancı anahtar belirtilmemişse, varsayılan kuralı uygula
        if ($foreignKey === null) {
            if ($relationType === self::BELONGS_TO) {
                // BelongsTo ilişkisi için, hedef model adından türet
                $targetClassName = $this->getClassNameFromNamespace($targetModel);
                $this->foreignKey = strtolower($targetClassName) . '_id';
            } else {
                // HasOne, HasMany ilişkisi için, kaynak model adından türet
                $sourceClassName = $this->getClassNameFromObject($sourceModel);
                $this->foreignKey = strtolower($sourceClassName) . '_id';
            }
        } else {
            $this->foreignKey = $foreignKey;
        }
    }
    
    /**
     * Namespace içeren sınıf adından sadece sınıf adını alır
     * 
     * @param string $className Sınıf adı
     * @return string Kısa sınıf adı
     */
    private function getClassNameFromNamespace(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
    
    /**
     * Nesneden sınıf adını alır
     * 
     * @param object $object Nesne
     * @return string Sınıf adı
     */
    private function getClassNameFromObject($object): string
    {
        $className = get_class($object);
        return $this->getClassNameFromNamespace($className);
    }
    
    /**
     * İlişkili verileri yükler
     * 
     * @return mixed İlişkili model veya modeller
     */
    public function load()
    {
        try {
            $targetModelInstance = new $this->targetModel();
            $targetTable = $targetModelInstance->getTableName();
            
            switch ($this->relationType) {
                case self::HAS_ONE:
                    return $this->loadHasOne($targetTable);
                case self::HAS_MANY:
                    return $this->loadHasMany($targetTable);
                case self::BELONGS_TO:
                    return $this->loadBelongsTo($targetTable);
                default:
                    throw new \Exception("Bilinmeyen ilişki türü: {$this->relationType}");
            }
        } catch (\Exception $e) {
            DB::start()->addErrors([
                'msg' => "İlişkili veri yükleme hatası: " . $e->getMessage(),
                'source' => 'Relation::load',
                'debug' => $e
            ]);
            
            return null;
        }
    }
    
    /**
     * HasOne ilişkisini yükler
     * 
     * @param string $targetTable Hedef tablo
     * @return mixed İlişkili model
     */
    private function loadHasOne(string $targetTable)
    {
        $localKeyValue = $this->sourceModel->{$this->localKey};
        
        if (!$localKeyValue) {
            return null;
        }
        
        $items = DB::query($targetTable)
            ->where($this->foreignKey, $localKeyValue)
            ->limit(1)
            ->get();
            
        if (empty($items)) {
            return null;
        }
        
        return call_user_func([$this->targetModel, 'createFromArray'], $items[0]);
    }
    
    /**
     * HasMany ilişkisini yükler
     * 
     * @param string $targetTable Hedef tablo
     * @return array İlişkili modeller
     */
    private function loadHasMany(string $targetTable)
    {
        $localKeyValue = $this->sourceModel->{$this->localKey};
        
        if (!$localKeyValue) {
            return [];
        }
        
        $items = DB::query($targetTable)
            ->where($this->foreignKey, $localKeyValue)
            ->get();
            
        if (empty($items)) {
            return [];
        }
        
        return array_map(function($item) {
            return call_user_func([$this->targetModel, 'createFromArray'], $item);
        }, $items);
    }
    
    /**
     * BelongsTo ilişkisini yükler
     * 
     * @param string $targetTable Hedef tablo
     * @return mixed İlişkili model
     */
    private function loadBelongsTo(string $targetTable)
    {
        try {
            // Önce property'nin değerini kontrol et
            $foreignKeyValue = null;
            
            // Eğer sourceModel nesnesinde foreignKey bir özellik olarak varsa
            if (property_exists($this->sourceModel, $this->foreignKey)) {
                $foreignKeyValue = $this->sourceModel->{$this->foreignKey};
                
                // Eğer değer bir nesne ise ve bir id özelliği varsa
                if (is_object($foreignKeyValue) && property_exists($foreignKeyValue, 'id')) {
                    $foreignKeyValue = $foreignKeyValue->id;
                }
            }
            
            // ForeignKey değeri yoksa veya null ise ilişki kurulamaz
            if (!$foreignKeyValue) {
                // Önce source model'in id'sini kontrol et, veritabanından kaydı tazeleyelim
                if (isset($this->sourceModel->id) && $this->sourceModel->id > 0) {
                    // Kaynağı tazeleyelim
                    $refreshedSource = call_user_func([$this->sourceModel::class, 'find'], $this->sourceModel->id);
                    
                    if ($refreshedSource && property_exists($refreshedSource, $this->foreignKey)) {
                        $foreignKeyValue = $refreshedSource->{$this->foreignKey};
                        
                        // Eğer değer bir nesne ise ve bir id özelliği varsa
                        if (is_object($foreignKeyValue) && property_exists($foreignKeyValue, 'id')) {
                            $foreignKeyValue = $foreignKeyValue->id;
                        }
                    }
                }
                
                // Hala değer bulunamadıysa null dön
                if (!$foreignKeyValue) {
                    return null;
                }
            }
            
            $items = DB::query($targetTable)
                ->where($this->localKey, $foreignKeyValue)
                ->limit(1)
                ->get();
                
            if (empty($items)) {
                return null;
            }
            
            return call_user_func([$this->targetModel, 'createFromArray'], $items[0]);
        } catch (\Exception $e) {
            DB::start()->addErrors([
                'msg' => "BelongsTo ilişkisi yükleme hatası: " . $e->getMessage(),
                'source' => 'Relation::loadBelongsTo',
                'debug' => $e
            ]);
            
            return null;
        }
    }
    
    /**
     * İlişki tipini döndürür
     * 
     * @return string İlişki tipi
     */
    public function getRelationType(): string
    {
        return $this->relationType;
    }
    
    /**
     * Hedef modeli döndürür
     * 
     * @return string Hedef model
     */
    public function getTargetModel(): string
    {
        return $this->targetModel;
    }
    
    /**
     * Yabancı anahtarı döndürür
     * 
     * @return string Yabancı anahtar
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }
    
    /**
     * Yerel anahtarı döndürür
     * 
     * @return string Yerel anahtar
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }
} 