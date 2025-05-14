<?php
namespace App\Database;
abstract class Model
{
    public int $id;
    private static $tablesChecked = [];
    protected $relations = [];
    protected $relatedData = [];
    private $fluentProps = []; // Fluent property erişimi için değerleri saklayacak dizi
    
    // Tablo adını sınıf ismi küçük harfle alıyoruz; isterseniz override edilebilir.
    public function getTableName(): string
    {
        return strtolower((new \ReflectionClass($this))->getShortName());
    }

    /**
     * Sınıf oluşturulduğunda tablo varlığını kontrol eder.
     */
    public function __construct()
    {
        $tableName = $this->getTableName();
        
        // Bu tabloya daha önce bakılmış mı? Her seferinde kontrol etmemek için
        if (!isset(self::$tablesChecked[$tableName])) {
            self::$tablesChecked[$tableName] = true;
            $this->ensureTableExists();
            
            // Tablo varsa, model ile şemayı karşılaştır ve gerekiyorsa güncelle
            $this->syncModelWithTable();
        }
        
        // İlişkileri başlat
        $this->initializeRelations();
    }
    
    /**
     * İlişkileri başlatır
     */
    protected function initializeRelations()
    {
        try {
            $ref = new \ReflectionClass($this);
            $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
            
            foreach ($props as $prop) {
                $name = $prop->getName();
                $type = $prop->getType()?->getName() ?? null;
                
                // Class tipi olan ama Unique, DateTime gibi yardımcı sınıflar olmayan özellikleri bul
                if ($type && class_exists($type) && !in_array($type, ['App\\Database\\Unique', 'DateTime']) && strpos($type, '\\') !== false) {
                    // Bu bir model ilişkisi olabilir
                    if (is_subclass_of($type, Model::class)) {
                        // BelongsTo ilişkisi olarak ekle
                        $this->relations[$name] = new Relation($this, Relation::BELONGS_TO, $type, $name);
                    }
                }
            }
        } catch (\Exception $e) {
            DB::start()->addErrors([
                'msg' => "İlişkileri başlatma hatası: " . $e->getMessage(),
                'source' => static::class . '::initializeRelations',
                'debug' => $e
            ]);
        }
    }
    
    /**
     * HasOne ilişkisi tanımlar
     * 
     * @param string $targetModel Hedef model sınıfı
     * @param string|null $foreignKey Yabancı anahtar
     * @param string $localKey Yerel anahtar
     * @return Relation İlişki nesnesi
     */
    public function hasOne(string $targetModel, ?string $foreignKey = null, string $localKey = 'id'): Relation
    {
        $relationName = $this->getRelationName($targetModel, $foreignKey);
        $this->relations[$relationName] = new Relation($this, Relation::HAS_ONE, $targetModel, $foreignKey, $localKey);
        return $this->relations[$relationName];
    }
    
    /**
     * HasMany ilişkisi tanımlar
     * 
     * @param string $targetModel Hedef model sınıfı
     * @param string|null $foreignKey Yabancı anahtar
     * @param string $localKey Yerel anahtar
     * @return Relation İlişki nesnesi
     */
    public function hasMany(string $targetModel, ?string $foreignKey = null, string $localKey = 'id'): Relation
    {
        $relationName = $this->getRelationName($targetModel, $foreignKey, true);
        $this->relations[$relationName] = new Relation($this, Relation::HAS_MANY, $targetModel, $foreignKey, $localKey);
        return $this->relations[$relationName];
    }
    
    /**
     * BelongsTo ilişkisi tanımlar
     * 
     * @param string $targetModel Hedef model sınıfı
     * @param string|null $foreignKey Yabancı anahtar
     * @param string $localKey Yerel anahtar
     * @return Relation İlişki nesnesi
     */
    public function belongsTo(string $targetModel, ?string $foreignKey = null, string $localKey = 'id'): Relation
    {
        $relationName = $this->getRelationName($targetModel, $foreignKey);
        $this->relations[$relationName] = new Relation($this, Relation::BELONGS_TO, $targetModel, $foreignKey, $localKey);
        return $this->relations[$relationName];
    }
    
    /**
     * İlişkiden ilişki adını oluşturur
     * 
     * @param string $targetModel Hedef model sınıfı
     * @param string|null $foreignKey Yabancı anahtar
     * @param bool $isPlural Çoğul mu?
     * @return string İlişki adı
     */
    private function getRelationName(string $targetModel, ?string $foreignKey, bool $isPlural = false): string
    {
        // Sınıf adını al
        $parts = explode('\\', $targetModel);
        $className = end($parts);
        
        // İlk harfi küçük yap
        $relationName = lcfirst($className);
        
        // Çoğul ilişki ise 's' ekle
        if ($isPlural) {
            $relationName .= 's';
        }
        
        return $relationName;
    }
    
    /**
     * İlişkili verileri yükler
     * 
     * @param string $relationName İlişki adı
     * @return mixed İlişkili model veya modeller
     */
    public function loadRelation(string $relationName)
    {
        if (!isset($this->relations[$relationName])) {
            DB::start()->addErrors([
                'msg' => "Belirtilen ilişki bulunamadı: {$relationName}",
                'source' => static::class . '::loadRelation'
            ]);
            return null;
        }
        
        if (!isset($this->relatedData[$relationName])) {
            $this->relatedData[$relationName] = $this->relations[$relationName]->load();
        }
        
        return $this->relatedData[$relationName];
    }
    
    /**
     * Sihirli method ile ilişkilere erişim ve property metod erişimi
     * 
     * @param string $name Metod adı
     * @param array $arguments Argümanlar
     * @return mixed İlişkili model, model nesnesi veya sonuç
     */
    public function __call(string $name, array $arguments)
    {
        // İlişki yükleme metodunu çağırma
        if (isset($this->relations[$name])) {
            // Eğer henüz yüklenmemişse ilişkiyi yükle
            if (!isset($this->relatedData[$name])) {
                $this->relatedData[$name] = $this->relations[$name]->load();
            }
            return $this->relatedData[$name];
        }
         
        // Property erişimi kontrol et
        $ref = new \ReflectionClass($this);
        if ($ref->hasProperty($name) && $ref->getProperty($name)->isPublic()) {
            // Parametre varsa değer ata (fluent erişim)
            if (count($arguments) > 0) {
                // Fluent property değerini ayarla
                $this->fluentProps[$name] = $arguments[0];
                
                // Aynı zamanda gerçek property'yi de güncelle
                // Burada property tipini kontrol et ve gerekirse dönüştür
                $prop = $ref->getProperty($name);
                $propType = $prop->getType()?->getName() ?? null; 
                // Özel tiplere dönüştürme
                if ($propType === 'App\\Database\\Unique' || $propType === Unique::class) {
                    // Eğer değer Unique nesnesi değilse, Unique nesnesine dönüştür
                    if (!($arguments[0] instanceof Unique)) {
                        $this->{$name} = new Unique($arguments[0]);
                    } else {
                        $this->{$name} = $arguments[0];
                    }
                } elseif ($propType && class_exists($propType) && is_subclass_of($propType, Model::class)) {
                    // Eğer değer bir model tipi ise ve gelen değer ID ise, ID'yi ata
                    if (is_numeric($arguments[0])) {
                        // ID değerini sakla, gerçek nesne lazy loading ile yüklenecek
                        $this->{$name} = $arguments[0];
                    } elseif ($arguments[0] instanceof $propType) {
                        // Eğer zaten doğru tipte bir nesne ise, direkt ata
                        $this->{$name} = $arguments[0];
                    } elseif ($arguments[0] === null) {
                        // Null değer için özel işlem
                        if ($prop->getType()->allowsNull()) {
                            $this->{$name} = null;
                        }
                    }
                } else {
                    // Normal tiplere doğrudan ata
                    $this->{$name} = $arguments[0];
                }
                
                // Benzersiz alan ise ve daha önce bu nesne kaydedilmemişse, bu alanla veritabanından yükleme dene
                if (($name === 'id' || ($propType === 'App\\Database\\Unique' || $propType === Unique::class))
                    && empty($this->id)) {
                    
                    $tableName = $this->getTableName();
                    $conditions = [];
                    
                    if ($name === 'id') { 
                        $conditions[$name] = $arguments[0];
                    } else {
                        $value = ($arguments[0] instanceof Unique) ? $arguments[0]->getValue() : $arguments[0];
                        $conditions[$name] = $value;
                    }
                     
                    try {
                        // Bu benzersiz değerle veritabanında kayıt ara
                        if (!empty($conditions)) {
                            
                            $record = static::findBy($conditions);
                            if (!empty($record)) {
                                $record = $record[0];
                                
                                // Bulunan kaydın tüm değerlerini bu nesneye kopyala
                                $allProps = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
                                foreach ($allProps as $recordProp) {
                                    $propName = $recordProp->getName();
                                    if (property_exists($record, $propName) && $propName != $name) {
                                        $this->{$propName} = $record->{$propName};
                                        $this->fluentProps[$propName] = $record->{$propName};
                                    }
                                }
                                
                                // İlişkili verilerin yüklenmesi için ilişkileri temizle
                                $this->relatedData = [];
                            }
                        }
                    } catch (\Exception $e) {
                        // Yükleme hatası olursa devam et, yeni kayıt olarak işlenecek
                        DB::start()->addErrors([
                            'msg' => "Benzersiz alan ile yükleme hatası: {$name}",
                            'source' => static::class . '::__call',
                            'debug' => $e
                        ]);
                    }
                }
                
                // Zincirleme için kendini döndür
                return $this;
            } else {
                // Değer okuma (parametre yok)
                // Fluent özellik varsa onu, yoksa gerçek property'yi döndür
                // Eğer id değeri varsa ve değer null/boş ise, veritabanından yüklemeyi dene
                if (isset($this->id) && $this->id > 0) {
                    // Fluent değer yoksa ve property null ise, veritabanından yüklemeyi dene
                    if (!isset($this->fluentProps[$name]) && 
                        (!isset($this->{$name}) || $this->{$name} === null)) {
                        // Mevcut kaydı yükle
                        try {
                            $record = static::find($this->id);
                            if ($record && isset($record->{$name})) {
                                $this->{$name} = $record->{$name};
                                $this->fluentProps[$name] = $record->{$name};
                            }
                        } catch (\Exception $e) {
                            // Yükleme hatası, log'a kaydedilebilir
                            DB::start()->addErrors([
                                'msg' => "Özellik lazy-loading hatası: {$name}",
                                'source' => static::class . '::__call',
                                'debug' => $e
                            ]);
                        }
                    }
                }
                
                return $this->fluentProps[$name] ?? $this->{$name} ?? null;
            }
        }
        
        throw new \BadMethodCallException("Metod veya özellik bulunamadı: {$name}");
    }
    
    /**
     * Bir ID ile veri tabanından kayıt bulup modele yükler
     * 
     * @param int $id Kayıt ID
     * @return Model Kendisi (zincirleme için)
     * @throws \Exception Kayıt bulunamazsa
     */
    public function load(int $id): Model
    {
        $record = static::find($id);
        
        if (!$record) {
            throw new \Exception("Kayıt bulunamadı: ID = {$id}");
        }
        
        // Bulunan kaydın tüm özelliklerini bu modele kopyala
        $ref = new \ReflectionClass($this);
        $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        
        foreach ($props as $prop) {
            $name = $prop->getName();
            if (property_exists($record, $name)) {
                $this->{$name} = $record->{$name};
                $this->fluentProps[$name] = $record->{$name};
            }
        }
        
        // İlişkili verilerin tekrar yüklenmesi için ilişkileri temizle
        $this->relatedData = [];
        
        return $this;
    }
    
    /**
     * Verilen koşullara göre veritabanından kayıt bulup modele yükler
     * 
     * @param array $conditions Koşullar
     * @return Model|null Kendisi veya kayıt bulunamazsa null
     */
    public function findOneBy(array $conditions): ?Model
    {
        $records = static::findBy($conditions);
        
        if (empty($records)) {
            return null;
        }
        
        // Bulunan ilk kaydın tüm özelliklerini bu modele kopyala
        $record = $records[0];
        
        $ref = new \ReflectionClass($this);
        $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        
        foreach ($props as $prop) {
            $name = $prop->getName();
            if (property_exists($record, $name)) {
                $this->{$name} = $record->{$name};
                $this->fluentProps[$name] = $record->{$name};
            }
        }
        
        return $this;
    }
    
    /**
     * Model ile veritabanı şemasını senkronize eder
     * 
     * @return array Senkronizasyon sonuçları
     */
    public function syncModelWithTable(): array
    {
        try {
            $result = DB::controllers()->syncModelSchema($this);
            
            // Sonucu kontrol et ve olası hataları yönet
            if (!is_array($result)) {
                return [
                    'status' => false,
                    'message' => "Beklenmeyen sonuç tipi",
                    'error' => "Senkronizasyon beklenmeyen bir sonuç döndürdü"
                ];
            }
            
            // Status değeri yoksa varsayılan olarak false yap
            if (!isset($result['status'])) {
                $result['status'] = false;
            }
            
            return $result;
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model-tablo senkronizasyon hatası",
                'source' => static::class . '::syncModelWithTable',
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
     * Tablonun var olduğundan emin olur, yoksa oluşturur
     * 
     * @return bool Başarılıysa true, değilse false
     */
    protected function ensureTableExists(): bool
    {
        $tableName = $this->getTableName();
        $controllers = DB::controllers();
        
        if (!$controllers->tableExists($tableName)) {
            // Tablo yoksa oluştur ve önbelleğe al
            $result = $this->create_table();
            if ($result) {
                $controllers->cacheTableSchema($tableName);
                return true;
            }
            return false;
        }
        
        return true;
    }

    // Tabloyu oluştur
    public function create_table()
    {
        $ref = new \ReflectionClass($this);
        $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);

        $cols = [];
        $foreignKeys = [];
        
        foreach ($props as $prop) {
            $name = $prop->getName();
            $type = $prop->getType()?->getName() ?? 'string';

            // Basit tip eşleştirmesi
            switch ($type) {
                case 'int':
                    $sqlType = 'INT';
                    break;
                case 'float':
                    $sqlType = 'FLOAT';
                    break;
                case 'bool':
                    $sqlType = 'TINYINT(1)';
                    break;
                case 'array':
                    $sqlType = 'TEXT'; // Array'ler için TEXT kullanacağız ve JSON olarak saklayacağız
                    break;
                default:
                    $sqlType = 'VARCHAR(255)';
            }

            // "id" alanı otomatik PK+AI olsun
            if ($name === 'id') {
                $cols[] = "`id` {$sqlType} NOT NULL PRIMARY KEY AUTO_INCREMENT";
            } else {
                // İlişkisel model alanları için foreign key tanımla
                if ($type && class_exists($type) && is_subclass_of($type, Model::class)) {
                    // Bir model tipiyse, bu bir ilişki alanı olabilir
                    $cols[] = "`{$name}` INT NULL";
                    
                    // İlişkili modelin tablo adını al
                    $relatedModelInstance = new $type();
                    $relatedTableName = $relatedModelInstance->getTableName();
                    
                    // Foreign key constraint tanımı
                    $foreignKeys[] = "CONSTRAINT `fk_{$this->getTableName()}_{$name}` FOREIGN KEY (`{$name}`) " . 
                                     "REFERENCES `{$relatedTableName}`(`id`) ON DELETE SET NULL ON UPDATE CASCADE";
            } else {
                $cols[] = "`{$name}` {$sqlType} NULL";
                }
            }
        }

        $table = $this->getTableName();
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (\n  "
            . implode(",\n  ", $cols);
            
        // Foreign key tanımları varsa ekle
        if (!empty($foreignKeys)) {
            $sql .= ",\n  " . implode(",\n  ", $foreignKeys);
        }
            
        $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        // SQL'i kaydet ve çalıştır
        try {
            $stmt = DB::pdo()->prepare($sql);
            $stmt->execute();
            
            // Başarılı işlemi kaydet
            DB::start()->addSuccess([
                'operation' => 'create_table',
                'table' => $table,
                'columns' => count($cols)
            ]);
            
            return true;
        } catch (\PDOException $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Tablo oluşturma hatası: {$table}",
                'source' => static::class . '::create_table',
                'debug' => $e
            ]);
            
            return false;
        } catch (\Exception $e) {
            // Genel hataları da DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model tablo oluşturma hatası: {$table}",
                'source' => static::class . '::create_table',
                'debug' => $e
            ]);
            
            return false;
        }
    }
    
    // Yeni kayıt ekleme veya güncelleme
    public function save(): bool
    {
        try {
            // Öncelikle tablo varlığını kontrol et
            $this->ensureTableExists();
            
        $table = $this->getTableName();
            $data = $this->toArray();
        
            // ID varsa güncelleme, yoksa ekleme yapalım
        if (isset($this->id) && $this->id > 0) {
            // UPDATE işlemi
                $id = $this->id;
                unset($data['id']); // ID'yi güncelleme verisinden çıkar
                
                return DB::update($table)
                    ->where('id', $id)
                    ->set($data)
                    ->execute();
            } else {
                // INSERT işlemi
                $id = DB::insert($table)
                    ->values($data)
                    ->execute();
                
                if ($id) {
                    $this->id = $id;
                    return true;
                }
                return false;
            }
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model kaydetme hatası: " . $this->getTableName(),
                'source' => static::class . '::save',
                'debug' => $e
            ]);
            
            return false;
        }
    }
    
    // Sınıf özelliklerini diziye dönüştür
    protected function toArray(): array
    {
        try {
            $ref = new \ReflectionClass($this);
            $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
            $data = [];
            
            foreach ($props as $prop) {
                $name = $prop->getName();
                $value = $this->{$name} ?? null;
                
                // Unique tipindeki değerler için özel işlem
                if ($value instanceof Unique) {
                    // Benzersizlik kontrolü yap
                    if (!$this->checkUniqueness($name, $value)) {
                        // Hata: Benzersiz olmayan değer
                        throw new \Exception("Benzersiz değer kısıtlaması ihlal edildi: {$name}");
                    }
                    
                    // Unique nesnesinden gerçek değeri al
                    $value = $value->getValue();
                }
                
                // Model tipindeki nesneler için sadece id değerini al
                if ($value instanceof Model) {
                    $value = $value->id;
                }
                
                // Array değerler için JSON encode uygulayalım
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                
                $data[$name] = $value;
            }
            
            return $data;
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model veri dönüştürme hatası: " . $e->getMessage(),
                'source' => static::class . '::toArray',
                'debug' => $e
            ]);
            
            throw $e; // Hatayı yukarı ilet
        }
    }
    
    /**
     * Benzersiz değer kontrolü yapar
     * 
     * @param string $columnName Sütun adı
     * @param Unique $uniqueValue Benzersiz değer
     * @return bool Değer benzersiz ise true, değilse false
     */
    protected function checkUniqueness(string $columnName, Unique $uniqueValue): bool
    {
        try {
            $tableName = $this->getTableName();
            $uniqueValue->setColumnName($columnName);
            
            // ID varsa (güncelleme durumu) bu ID'yi hariç tut
            $excludeId = $this->id ?? null;
            
            return $uniqueValue->isUnique($tableName, $columnName, $excludeId);
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Benzersizlik kontrolü hatası: {$columnName}",
                'source' => static::class . '::checkUniqueness',
                'debug' => $e
            ]);
            
            return false; // Hata durumunda false dön
        }
    }
    
    // ID'ye göre kayıt bulma
    public static function find(int $id)
    {
        try {
        $model = new static();
            // Tablo varlığını kontrol et
            $model->ensureTableExists();
            
        $table = $model->getTableName();
        
            $result = DB::query($table)
                ->where('id', $id)
                ->first();
                
            return $result ? static::createFromArray($result) : null;
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model kayıt bulma hatası (ID: {$id})",
                'source' => static::class . '::find',
                'debug' => $e
            ]);
            
            return null;
        }
    }
    
    // Tüm kayıtları getirme
    public static function findAll(string $orderBy = 'id', string $direction = 'ASC'): array
    {
        try {
        $model = new static();
            // Tablo varlığını kontrol et
            $model->ensureTableExists();
            
        $table = $model->getTableName();
        
            $results = DB::query($table)
                ->orderBy($orderBy, $direction)
                ->get();
                
            return array_map(function($item) {
                return static::createFromArray($item);
            }, $results);
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model tüm kayıtları getirme hatası",
                'source' => static::class . '::findAll',
                'debug' => $e
            ]);
            
            return [];
        }
    }
    
    // Koşula göre kayıt bulma
    public static function findBy(array $conditions): array
    {
        try {
        $model = new static();
            // Tablo varlığını kontrol et
            $model->ensureTableExists();
            
        $table = $model->getTableName();
        
            $query = DB::query($table);
        
        foreach ($conditions as $field => $value) {
                $query->where($field, $value);
            }
            
            $results = $query->get();
            
            return array_map(function($item) {
                return static::createFromArray($item);
            }, $results);
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model koşullu kayıt bulma hatası",
                'source' => static::class . '::findBy',
                'debug' => $e,
                'conditions' => $conditions
            ]);
            
            return [];
        }
    }
    
    // Kayıt silme
    public function delete(): bool
    {
        try {
        if (!isset($this->id) || $this->id <= 0) {
                DB::start()->addErrors([
                    'msg' => "Model silme hatası: Geçersiz ID",
                    'source' => static::class . '::delete'
                ]);
            return false;
        }
        
        $table = $this->getTableName();
            
            return DB::delete($table)
                ->where('id', $this->id)
                ->execute();
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model silme hatası (ID: {$this->id})",
                'source' => static::class . '::delete',
                'debug' => $e
            ]);
            
            return false;
        }
    }
    
    // Toplu silme
    public static function deleteWhere(array $conditions): int
    {
        try {
        $model = new static();
            // Tablo varlığını kontrol et
            $model->ensureTableExists();
            
        $table = $model->getTableName();
        
            if (empty($conditions)) {
                DB::start()->addErrors([
                    'msg' => "Model toplu silme hatası: Koşul belirtilmedi",
                    'source' => static::class . '::deleteWhere'
                ]);
                return 0;
            }
            
            $delete = DB::delete($table);
        
        foreach ($conditions as $field => $value) {
                $delete->where($field, $value);
            }
            
            return $delete->execute() ? 1 : 0; // Başarılı olursa 1, olmazsa 0 dön
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model toplu silme hatası",
                'source' => static::class . '::deleteWhere',
                'debug' => $e,
                'conditions' => $conditions
            ]);
            
            return 0;
        }
    }
    
    // Kayıt sayısını bulma
    public static function count(array $conditions = []): int
    {
        try {
        $model = new static();
            // Tablo varlığını kontrol et
            $model->ensureTableExists();
            
        $table = $model->getTableName();
        
            $query = DB::query($table);
        
        foreach ($conditions as $field => $value) {
                $query->where($field, $value);
            }
            
            return $query->count();
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model kayıt sayma hatası",
                'source' => static::class . '::count',
                'debug' => $e,
                'conditions' => $conditions
            ]);
            
            return 0;
        }
    }
    
    /**
     * Tablo şemasını veritabanı ile senkronize eder
     * 
     * @return bool İşlem başarılıysa true, değilse false
     */
    public function syncSchema(): bool
    {
        try {
            $tableName = $this->getTableName();
            return DB::controllers()->cacheTableSchema($tableName);
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model şema senkronizasyon hatası",
                'source' => static::class . '::syncSchema',
                'debug' => $e
            ]);
            
            return false;
        }
    }
    
    /**
     * Tablonun önbellekteki şemasını döndürür
     * 
     * @return array|null Şema bilgisi veya null
     */
    public function getSchema(): ?array
    {
        try {
            return DB::controllers()->getTableSchema($this->getTableName());
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model şema bilgisi alma hatası",
                'source' => static::class . '::getSchema',
                'debug' => $e
            ]);
            
            return null;
        }
    }
    
    // Dizi verisinden model nesnesi oluşturma
    public static function createFromArray(array $data)
    {
        try {
        $model = new static();
        $ref = new \ReflectionClass($model);
        
        foreach ($data as $key => $value) {
            if (property_exists($model, $key)) {
                // Property tipini kontrol edelim
                $prop = $ref->getProperty($key);
                $type = $prop->getType()?->getName() ?? null;
                    $allowsNull = $prop->getType()?->allowsNull() ?? true;
                    
                    // Null değer kontrolü
                    if ($value === null) {
                        if ($allowsNull) {
                            $model->{$key} = null;
                        }
                        continue;
                    }
                    
                    // İlişkili model alanları için
                    if ($type && class_exists($type) && is_subclass_of($type, Model::class)) {
                        if (is_numeric($value)) {
                            // ID değerinin gerçek model nesnesine dönüştürülmesi
                            $relatedModelInstance = new $type();
                            
                            try {
                                $relatedModel = $relatedModelInstance->load((int)$value);
                                $model->{$key} = $relatedModel;
                            } catch (\Exception $e) {
                                // Eğer model yüklenemezse, yeni bir boş model oluştur ve ID'yi ayarla
                                $relatedModelInstance->id = (int)$value;
                                $model->{$key} = $relatedModelInstance;
                            }
                        } elseif (is_array($value) && !empty($value)) {
                            // İlişkili model verisi dizi olarak geldiyse, onu model nesnesine dönüştür
                            $relatedModel = call_user_func([$type, 'createFromArray'], $value);
                            $model->{$key} = $relatedModel;
                        } elseif ($value instanceof $type) {
                            // Zaten doğru tipte bir nesne ise
                            $model->{$key} = $value;
                        }
                    }
                    // Unique tipindeki özellikler için nesne oluştur
                    elseif ($type === Unique::class || $type === 'App\\Database\\Unique') {
                        if (!($value instanceof Unique)) {
                            $model->{$key} = new Unique($value);
                        } else {
                $model->{$key} = $value;
            }
        }
                    // Array tipinde bir alan varsa ve JSON string ise, decode edelim
                    elseif ($type === 'array' && is_string($value)) {
                        $model->{$key} = json_decode($value, true) ?? [];
                    } else {
                        // Diğer tüm tipler için doğrudan atama
                        $model->{$key} = $value;
                    }
                    
                    // Ayrıca fluent erişim için de değeri sakla
                    $model->fluentProps[$key] = $model->{$key};
                }
            }
            
            return $model;
        } catch (\Exception $e) {
            // Hatayı DB sınıfına kaydet
            DB::start()->addErrors([
                'msg' => "Model nesne oluşturma hatası: " . $e->getMessage(),
                'source' => static::class . '::createFromArray',
                'debug' => $e
            ]);
            
            return null;
        }
    }
    
    /**
     * Modeldeki tüm değişiklikleri veritabanı tablosuna uygular
     * 
     * @return array İşlem sonuçları
     */
    public static function ensureTableSync(): array
    {
        $model = new static();
        return $model->syncModelWithTable();
    }
    
    /**
     * Tüm ilişkileri yükler
     * 
     * @return Model Bu model
     */
    public function loadAllRelations(): Model
    {
        foreach ($this->relations as $name => $relation) {
            $this->loadRelation($name);
        }
        
        return $this;
    }
    
    /**
     * Static metotlara fluent erişim için ara sınıf
     */
    public static function query()
    {
        return new ModelQuery(static::class);
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
                return null;
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
}

/**
 * Model sınıfları için fluent sorgu arayüzü
 */
