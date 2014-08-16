<?php
namespace App\Model\Rdf\Repositories;

use App\Model\Rdf\Entities\BaseEntity;
use Nette\Object;
use Nette\Utils\Strings;

class BaseRepository extends Object{
  const BASIC_ENTITY_NAMESPACE='App\Model\Rdf\Entities';
  /** @var \ARC2_Store $arc */
  protected $arcStore;

  public function __construct(\ARC2_Store $arcStore){
    $this->arcStore=$arcStore;
    if(!$this->arcStore->isSetUp()){
      $this->arcStore->setUp();
    }
  }

  public function reset(){
    $this->arcStore->reset();
  }

  /**
   * Funkce pro spuštění dotazu nad arc storem
   * @param string $query
   * @param string $format = raw
   * @param int $limit=-1
   * @param int $offset=-1
   * @return array|bool|int
   */
  public function executeQuery($query,$format='raw',$limit=-1,$offset=-1){
    if ($limit>-1){
      if (!Strings::contains($query,'ORDER')){
        $query.=' ORDER BY ?uri ';
      }
      $query.=' LIMIT '.$limit;
      if ($offset>-1){
        $query.=' OFFSET '.$offset;
      }
    }
    return $this->arcStore->query($query,$format);
  }

  /**
   * Funkce pro spuštění sady SPARQL dotazů
   * @param string[] $queriesArr
   */
  public function executeQueries($queriesArr){
    if (is_array($queriesArr) && count($queriesArr)){
      foreach($queriesArr as $query){
        $this->executeQuery($query);
      }
    }
  }

  /**
   * Funkce pro kontrolu, jestli daná URI v ontologii již existuje
   * @param string $uri - URI, kterou chceme ověřit, jestli existuje
   * @return bool
   */
  public function uriExists($uri){
    $query='ASK {'.BaseEntity::quoteUri($uri).' a ?class}';
    $result=$this->arcStore->query($query,'raw');
    return $result;
  }



  /**
   * Funkce pro vygenerování dosud neobrazené URI
   * @param string $uri
   * @return string
   */
  public function prepareNewEntityUri($uri){
    $finalUri=$uri;
    $item=1;
    while($this->uriExists($finalUri)){
      $finalUri=$uri.$item;
      $item++;
    }
    return $finalUri;
  }

  /**
   * @param BaseEntity $entity
   */
  public function saveEntity(&$entity){
    if (!is_object($entity)){
      exit(var_dump($entity));//TODO tohle je potřeba doladit!!!
    }
    if (!$entity->getUri()){
      $entity->setUri($this->prepareNewEntityUri($entity->prepareBaseUri()));
    }
    $this->executeQueries($entity->getSaveQuery($this));
    $entity->setChanged(false);
    $entity->setKnowledgeRepository($this);
  }

  /**
   * @param BaseEntity|string $entityClass
   * @param string $uri
   * @param string $entityClassNamespace = self::BASIC_ENTITY_NAMESPACE
   * @return BaseEntity|null
   */
  public function findEntity($entityClass,$uri,$entityClassNamespace=self::BASIC_ENTITY_NAMESPACE){
    if ($entityClassNamespace!=''){
      $entityClass=$entityClassNamespace.'\\'.$entityClass;
    }

    $result=$this->executeQuery($entityClass::getLoadQuery($uri),'raw');
    if ($result && !empty($result['rows'])){
      /** @var BaseEntity $entity */
      $entity=new $entityClass();
      $entity->prepareEntity($result['rows'][0],$uri);
      $entity->setKnowledgeRepository($this);
      $entity->setChanged(false);
      return $entity;
    }
    return null;
  }

  /**
   * Funkce pro načtení kolekce entit na základě vyhledávacích parametrů a názvu třídy
   * @param BaseEntity|string $entityClass
   * @param array|null $params
   * @param string $entityClassNamespace = null|self::BASIC_ENTITY_NAMESPACE
   * @param int $limit = -1
   * @param int $offset = -1
   * @return BaseEntity[]|null
   */
  public function findEntities($entityClass,$params,$entityClassNamespace=null,$limit=-1,$offset=-1){
    if ($entityClassNamespace==null){
      $entityClassNamespace=self::BASIC_ENTITY_NAMESPACE;
    }
    //TODO param knowledgeBase
    if ($entityClassNamespace!=''){
      $entityClass=$entityClassNamespace.'\\'.$entityClass;
    }
    #region params
    $filterSparql='';
    if (!empty($params['sparql'])){
      $filterSparql=$params['sparql'];
    }
    #endregion params

    $result=$this->executeQuery($entityClass::getLoadQuery('',$filterSparql),'raw',$limit,$offset);
    if ($result && !empty($result['rows'])){
      $output=array();
      foreach ($result['rows'] as $row){
        /** @var BaseEntity $resultEntity */
        $resultEntity=new $entityClass();
        $resultEntity->setKnowledgeRepository($this);
        $resultEntity->prepareEntity($row);
        $output[]=$resultEntity;
      }
      return $output;
    }
    return null;
  }

} 